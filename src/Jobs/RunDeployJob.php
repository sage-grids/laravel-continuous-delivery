<?php

namespace SageGrids\ContinuousDelivery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Exceptions\DeploymentException;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentFailed;
use SageGrids\ContinuousDelivery\Notifications\DeploymentSucceeded;

class RunDeployJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times to retry the job.
     */
    public int $tries = 1;

    /**
     * Timeout in seconds (30 minutes).
     */
    public int $timeout = 1800;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function handle(): void
    {
        $startTime = microtime(true);

        Log::info('[continuous-delivery] Job started', [
            'uuid' => $this->deployment->uuid,
            'environment' => $this->deployment->environment,
            'story' => $this->deployment->envoy_story,
            'ref' => $this->deployment->trigger_ref,
            'attempt' => $this->attempts(),
        ]);

        try {
            $this->validatePrerequisites();
        } catch (DeploymentException $e) {
            Log::error('[continuous-delivery] Prerequisite validation failed', [
                'uuid' => $this->deployment->uuid,
                'error' => $e->getMessage(),
            ]);

            $this->deployment->markFailed($e->getMessage(), 1);
            $this->notifyFailure();

            throw $e;
        }

        $this->deployment->markRunning();

        Log::info('[continuous-delivery] Deployment running', [
            'uuid' => $this->deployment->uuid,
        ]);

        try {
            $output = $this->runEnvoy();

            $this->deployment->markSuccess($output);

            $duration = round(microtime(true) - $startTime, 2);
            Log::info('[continuous-delivery] Job completed successfully', [
                'uuid' => $this->deployment->uuid,
                'duration_seconds' => $duration,
                'duration_human' => $this->deployment->duration_for_humans,
            ]);

            $this->notifySuccess();

        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $exitCode = $e->getCode() ?: 1;

            $this->deployment->markFailed($output, $exitCode);

            $duration = round(microtime(true) - $startTime, 2);
            Log::error('[continuous-delivery] Job failed', [
                'uuid' => $this->deployment->uuid,
                'error' => $e->getMessage(),
                'exit_code' => $exitCode,
                'duration_seconds' => $duration,
            ]);

            $this->notifyFailure();

            throw $e;
        }
    }

    /**
     * Validate deployment prerequisites.
     */
    protected function validatePrerequisites(): void
    {
        // Check Envoy binary exists
        try {
            $this->getEnvoyPath();
        } catch (\RuntimeException $e) {
            throw DeploymentException::envoyNotFound();
        }

        // Check app directory if configured
        $appDir = config('continuous-delivery.app_dir');
        if ($appDir) {
            if (!is_dir($appDir)) {
                throw DeploymentException::appDirDoesNotExist($appDir);
            }

            if (!is_writable($appDir)) {
                throw DeploymentException::appDirNotWritable($appDir);
            }
        }

        // Check git is installed
        $result = Process::run('which git');
        if (!$result->successful()) {
            throw DeploymentException::gitNotInstalled();
        }

        Log::debug('[continuous-delivery] Prerequisites validated', [
            'uuid' => $this->deployment->uuid,
        ]);
    }

    /**
     * Run the Envoy deployment.
     */
    protected function runEnvoy(): string
    {
        $envoyPath = $this->getEnvoyPath();
        $story = $this->deployment->envoy_story;
        $ref = $this->deployment->trigger_ref;

        // Build envoy command
        $command = sprintf(
            '%s run %s --ref=%s',
            escapeshellarg($envoyPath),
            escapeshellarg($story),
            escapeshellarg($ref)
        );

        // Add custom envoy file path if configured
        $envoyFile = config('continuous-delivery.envoy.path');
        if ($envoyFile && file_exists($envoyFile)) {
            $command = sprintf(
                '%s run %s --ref=%s --path=%s',
                escapeshellarg($envoyPath),
                escapeshellarg($story),
                escapeshellarg($ref),
                escapeshellarg($envoyFile)
            );
        }

        Log::debug('[continuous-delivery] Running Envoy command', [
            'command' => $command,
        ]);

        $result = Process::timeout($this->timeout)->run($command);

        $output = $result->output() . "\n" . $result->errorOutput();

        if (!$result->successful()) {
            throw new \RuntimeException(
                "Envoy deployment failed:\n" . $output,
                $result->exitCode()
            );
        }

        return $output;
    }

    /**
     * Get the path to the envoy binary.
     */
    protected function getEnvoyPath(): string
    {
        $configPath = config('continuous-delivery.envoy.binary');

        if ($configPath && is_executable($configPath)) {
            return $configPath;
        }

        // Default to vendor binary
        $vendorPath = base_path('vendor/bin/envoy');
        if (is_executable($vendorPath)) {
            return $vendorPath;
        }

        throw new \RuntimeException('Envoy binary not found. Install with: composer require laravel/envoy');
    }

    /**
     * Send success notification.
     */
    protected function notifySuccess(): void
    {
        try {
            $this->deployment->notify(new DeploymentSucceeded($this->deployment));
        } catch (\Throwable $e) {
            Log::warning('[continuous-delivery] Failed to send success notification', [
                'uuid' => $this->deployment->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send failure notification.
     */
    protected function notifyFailure(): void
    {
        try {
            $this->deployment->notify(new DeploymentFailed($this->deployment));
        } catch (\Throwable $e) {
            Log::warning('[continuous-delivery] Failed to send failure notification', [
                'uuid' => $this->deployment->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        if ($this->deployment->isRunning()) {
            $this->deployment->markFailed(
                $exception->getMessage(),
                $exception->getCode() ?: 1
            );
        }
    }
}
