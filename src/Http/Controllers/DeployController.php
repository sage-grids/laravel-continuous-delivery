<?php

namespace SageGrids\ContinuousDelivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Exceptions\DeploymentConflictException;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApprovalRequired;
use SageGrids\ContinuousDelivery\Notifications\DeploymentStarted;
use SageGrids\ContinuousDelivery\Services\DeploymentDispatcher;
use SageGrids\ContinuousDelivery\Support\Signature;

class DeployController extends Controller
{
    public function __construct(
        protected AppRegistry $registry,
        protected DeploymentDispatcher $dispatcher
    ) {}

    /**
     * Handle incoming GitHub webhook.
     */
    public function github(Request $request): JsonResponse
    {
        // Verify signature
        if (! $this->verifySignature($request)) {
            Log::warning('[continuous-delivery] Invalid webhook signature');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $event = $request->header('X-GitHub-Event');
        $deliveryId = $request->header('X-GitHub-Delivery');
        $payload = $request->json()->all();

        Log::info('[continuous-delivery] Received webhook', [
            'event' => $event,
            'delivery_id' => $deliveryId,
        ]);

        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        // Check for idempotency - if we've already processed this delivery, return the existing deployment
        if ($deliveryId) {
            $existingDeployment = DeployerDeployment::findByGithubDeliveryId($deliveryId);
            if ($existingDeployment) {
                Log::info('[continuous-delivery] Duplicate webhook detected', [
                    'delivery_id' => $deliveryId,
                    'existing_uuid' => $existingDeployment->uuid,
                ]);

                return response()->json([
                    'message' => 'Webhook already processed',
                    'deployment' => $existingDeployment->uuid,
                ], 200);
            }
        }

        // Determine event type and ref
        [$eventType, $ref] = $this->parseGithubEvent($event, $payload);

        if (! $eventType) {
            return response()->json(['message' => 'Event ignored'], 200);
        }

        // Get repository from payload
        $repository = $payload['repository']['full_name'] ?? null;

        // Find matching apps and triggers
        $matches = $this->registry->findByTrigger($eventType, $ref, $repository);

        if (empty($matches)) {
            Log::info('[continuous-delivery] No matching triggers', [
                'event' => $eventType,
                'ref' => $ref,
                'repository' => $repository,
            ]);

            return response()->json([
                'message' => 'No matching triggers',
                'event' => $eventType,
                'ref' => $ref,
            ], 200);
        }

        $deployments = [];

        try {
            foreach ($matches as $match) {
                $deployment = $this->createDeployment(
                    $match['app'],
                    $match['trigger'],
                    $eventType,
                    $ref,
                    $payload,
                    $deliveryId
                );

                if ($deployment) {
                    Log::info('[continuous-delivery] Deployment created', [
                        'uuid' => $deployment->uuid,
                        'app' => $deployment->app_key,
                        'trigger' => $match['trigger']['name'],
                        'strategy' => $match['app']->strategy,
                        'requires_approval' => $match['app']->requiresApproval($match['trigger']),
                    ]);

                    if ($match['app']->requiresApproval($match['trigger'])) {
                        $this->notifyApprovalRequired($deployment);
                    } else {
                        $this->dispatchDeployment($deployment);
                    }

                    $deployments[] = [
                        'uuid' => $deployment->uuid,
                        'app' => $deployment->app_key,
                        'status' => $deployment->status->value,
                    ];
                }
            }
        } catch (DeploymentConflictException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'active_deployment' => $e->getActiveDeploymentUuid(),
            ], 409);
        }

        return response()->json([
            'message' => count($deployments).' deployment(s) created',
            'deployments' => $deployments,
        ], 202);
    }

    /**
     * Parse GitHub event to determine type and ref.
     */
    protected function parseGithubEvent(string $event, array $payload): array
    {
        if ($event === 'push') {
            $ref = $payload['ref'] ?? '';
            $branch = str_replace('refs/heads/', '', $ref);

            return ['push', $branch];
        }

        if ($event === 'release') {
            $action = $payload['action'] ?? '';
            if ($action !== 'published') {
                return [null, null];
            }

            $tag = $payload['release']['tag_name'] ?? '';

            return ['release', $tag];
        }

        return [null, null];
    }

    /**
     * Create a deployment record and dispatch job if applicable.
     */
    protected function createDeployment(
        AppConfig $app,
        array $trigger,
        string $triggerType,
        string $triggerRef,
        array $payload,
        ?string $githubDeliveryId = null
    ): ?DeployerDeployment {
        return DB::connection(DeployerDeployment::getDeploymentConnection())
            ->transaction(function () use ($app, $trigger, $triggerType, $triggerRef, $payload, $githubDeliveryId) {
                // Check for active deployment with pessimistic locking
                $activeDeployment = DeployerDeployment::forApp($app->key)
                    ->forTrigger($trigger['name'])
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($activeDeployment) {
                    throw new DeploymentConflictException($app->key, $activeDeployment);
                }

                return DeployerDeployment::createFromWebhook(
                    $app,
                    $trigger,
                    $triggerType,
                    $triggerRef,
                    $payload,
                    $githubDeliveryId
                );
            });
    }

    /**
     * Dispatch deployment job.
     */
    protected function dispatchDeployment(DeployerDeployment $deployment): void
    {
        $this->notifyDeploymentStarted($deployment);
        $this->dispatcher->dispatch($deployment);
    }

    /**
     * Verify GitHub webhook signature.
     */
    protected function verifySignature(Request $request): bool
    {
        if (! config('continuous-delivery.github.verify_signature', true)) {
            return true;
        }

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
     * Send approval required notification.
     */
    protected function notifyApprovalRequired(DeployerDeployment $deployment): void
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
    protected function notifyDeploymentStarted(DeployerDeployment $deployment): void
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
        $deployment = DeployerDeployment::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'uuid' => $deployment->uuid,
            'app' => $deployment->app_key,
            'app_name' => $deployment->app_name,
            'trigger' => "{$deployment->trigger_name}:{$deployment->trigger_ref}",
            'strategy' => $deployment->strategy->value,
            'status' => $deployment->status->value,
            'commit' => $deployment->short_commit_sha,
            'author' => $deployment->author,
            'release_name' => $deployment->release_name,
            'created_at' => $deployment->created_at->toIso8601String(),
            'started_at' => $deployment->started_at?->toIso8601String(),
            'completed_at' => $deployment->completed_at?->toIso8601String(),
            'duration' => $deployment->duration_for_humans,
            'exit_code' => $deployment->exit_code,
        ]);
    }
}
