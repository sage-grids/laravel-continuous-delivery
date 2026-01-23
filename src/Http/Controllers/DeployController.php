<?php

namespace SageGrids\ContinuousDelivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApprovalRequired;
use SageGrids\ContinuousDelivery\Notifications\DeploymentStarted;
use SageGrids\ContinuousDelivery\Support\Signature;

class DeployController extends Controller
{
    /**
     * Handle incoming GitHub webhook.
     */
    public function github(Request $request): JsonResponse
    {
        // Verify signature
        if (!$this->verifySignature($request)) {
            Log::warning('[continuous-delivery] Invalid webhook signature');
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Verify repository if configured
        if (!$this->verifyRepository($request)) {
            Log::info('[continuous-delivery] Repository mismatch, ignoring');
            return response()->json(['message' => 'Repository not configured'], 200);
        }

        $event = $request->header('X-GitHub-Event');
        $payload = $request->json()->all();

        Log::info('[continuous-delivery] Received webhook', [
            'event' => $event,
            'delivery_id' => $request->header('X-GitHub-Delivery'),
        ]);

        return match ($event) {
            'push' => $this->handlePush($payload),
            'release' => $this->handleRelease($payload),
            'ping' => response()->json(['message' => 'pong']),
            default => response()->json(['message' => 'Event ignored'], 200),
        };
    }

    /**
     * Handle push event (staging deployments).
     */
    protected function handlePush(array $payload): JsonResponse
    {
        $ref = $payload['ref'] ?? '';
        $branch = str_replace('refs/heads/', '', $ref);

        $environment = $this->findEnvironmentForBranch($branch);

        if (!$environment) {
            Log::info('[continuous-delivery] No environment configured for branch', [
                'branch' => $branch,
            ]);
            return response()->json(['message' => 'Branch not configured'], 200);
        }

        return $this->createDeployment($environment, 'branch_push', $branch, $payload);
    }

    /**
     * Handle release event (production deployments).
     */
    protected function handleRelease(array $payload): JsonResponse
    {
        $action = $payload['action'] ?? '';

        if ($action !== 'published') {
            return response()->json(['message' => 'Release action ignored'], 200);
        }

        $tag = $payload['release']['tag_name'] ?? '';
        $environment = $this->findEnvironmentForTag($tag);

        if (!$environment) {
            Log::info('[continuous-delivery] No environment configured for tag', [
                'tag' => $tag,
            ]);
            return response()->json(['message' => 'Tag pattern not matched'], 200);
        }

        return $this->createDeployment($environment, 'release', $tag, $payload);
    }

    /**
     * Create a deployment record and dispatch job if applicable.
     */
    protected function createDeployment(
        string $environment,
        string $triggerType,
        string $triggerRef,
        array $payload
    ): JsonResponse {
        $config = config("continuous-delivery.environments.{$environment}");

        // Check for active deployment to same environment
        $activeDeployment = Deployment::forEnvironment($environment)
            ->active()
            ->first();

        if ($activeDeployment) {
            Log::warning('[continuous-delivery] Active deployment exists', [
                'environment' => $environment,
                'active_uuid' => $activeDeployment->uuid,
            ]);
            return response()->json([
                'error' => 'Active deployment in progress',
                'active_deployment' => $activeDeployment->uuid,
            ], 409);
        }

        $requiresApproval = $config['approval_required'] ?? false;
        $timeoutHours = $config['approval_timeout_hours'] ?? 2;

        $deployment = Deployment::createFromWebhook(
            $environment,
            $triggerType,
            $triggerRef,
            $payload,
            $requiresApproval,
            $timeoutHours
        );

        Log::info('[continuous-delivery] Deployment created', [
            'uuid' => $deployment->uuid,
            'environment' => $environment,
            'trigger' => "{$triggerType}:{$triggerRef}",
            'requires_approval' => $requiresApproval,
        ]);

        if ($requiresApproval) {
            $this->notifyApprovalRequired($deployment);

            return response()->json([
                'message' => 'Deployment pending approval',
                'deployment_id' => $deployment->uuid,
                'status' => $deployment->status,
                'approve_url' => $deployment->getApproveUrl(),
                'reject_url' => $deployment->getRejectUrl(),
                'expires_at' => $deployment->approval_expires_at->toIso8601String(),
            ], 202);
        }

        $this->dispatchDeployment($deployment);

        return response()->json([
            'message' => 'Deployment queued',
            'deployment_id' => $deployment->uuid,
            'status' => $deployment->status,
        ], 202);
    }

    /**
     * Dispatch deployment job.
     */
    protected function dispatchDeployment(Deployment $deployment): void
    {
        $this->notifyDeploymentStarted($deployment);

        $job = new RunDeployJob($deployment);

        $connection = config('continuous-delivery.queue.connection');
        $queue = config('continuous-delivery.queue.queue');

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * Find environment configuration for a branch.
     */
    protected function findEnvironmentForBranch(string $branch): ?string
    {
        $environments = config('continuous-delivery.environments', []);

        foreach ($environments as $name => $config) {
            if (!($config['enabled'] ?? true)) {
                continue;
            }

            if (($config['trigger'] ?? '') !== 'branch') {
                continue;
            }

            if (($config['branch'] ?? '') === $branch) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Find environment configuration for a tag.
     */
    protected function findEnvironmentForTag(string $tag): ?string
    {
        $environments = config('continuous-delivery.environments', []);

        foreach ($environments as $name => $config) {
            if (!($config['enabled'] ?? true)) {
                continue;
            }

            if (($config['trigger'] ?? '') !== 'release') {
                continue;
            }

            $pattern = $config['tag_pattern'] ?? '/^v\d+\.\d+\.\d+$/';

            if (preg_match($pattern, $tag)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Verify GitHub webhook signature.
     */
    protected function verifySignature(Request $request): bool
    {
        $secret = config('continuous-delivery.github.webhook_secret');

        if (empty($secret)) {
            Log::warning('[continuous-delivery] No webhook secret configured');
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $payload = $request->getContent();

        return Signature::verifyGithubSha256($payload, $signature, $secret);
    }

    /**
     * Verify repository matches configuration (if specified).
     */
    protected function verifyRepository(Request $request): bool
    {
        $configuredRepo = config('continuous-delivery.github.only_repo_full_name');

        if (empty($configuredRepo)) {
            return true;
        }

        $payload = $request->json()->all();
        $requestRepo = $payload['repository']['full_name'] ?? '';

        return $requestRepo === $configuredRepo;
    }

    /**
     * Send approval required notification.
     */
    protected function notifyApprovalRequired(Deployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentApprovalRequired($deployment));
        } catch (\Throwable $e) {
            Log::error('[continuous-delivery] Failed to send approval notification', [
                'deployment' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send deployment started notification.
     */
    protected function notifyDeploymentStarted(Deployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentStarted($deployment));
        } catch (\Throwable $e) {
            Log::error('[continuous-delivery] Failed to send started notification', [
                'deployment' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get deployment status.
     */
    public function status(string $uuid): JsonResponse
    {
        $deployment = Deployment::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'uuid' => $deployment->uuid,
            'environment' => $deployment->environment,
            'trigger' => "{$deployment->trigger_type}:{$deployment->trigger_ref}",
            'status' => $deployment->status,
            'commit' => $deployment->short_commit_sha,
            'author' => $deployment->author,
            'created_at' => $deployment->created_at->toIso8601String(),
            'started_at' => $deployment->started_at?->toIso8601String(),
            'completed_at' => $deployment->completed_at?->toIso8601String(),
            'duration' => $deployment->duration_for_humans,
            'exit_code' => $deployment->exit_code,
        ]);
    }
}
