<?php

namespace SageGrids\ContinuousDelivery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Deployers\DeployerFactory;
use SageGrids\ContinuousDelivery\Exceptions\DeploymentException;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentFailed;
use SageGrids\ContinuousDelivery\Notifications\DeploymentSucceeded;

class RunDeployJob implements ShouldQueue, ShouldBeUnique
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

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public DeployerDeployment $deployment
    ) {}

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->deployment->app_key;
    }

    public function handle(AppRegistry $registry, DeployerFactory $factory): void
    {
        $startTime = microtime(true);

        Log::info('[continuous-delivery] Job started', [
            'uuid' => $this->deployment->uuid,
            'app' => $this->deployment->app_key,
            'strategy' => $this->deployment->strategy,
            'story' => $this->deployment->envoy_story,
            'ref' => $this->deployment->trigger_ref,
            'attempt' => $this->attempts(),
        ]);

        // Get app configuration
        $app = $registry->get($this->deployment->app_key);
        if (! $app) {
            $error = "App configuration not found: {$this->deployment->app_key}";
            Log::error('[continuous-delivery] '.$error);
            $this->deployment->markFailed($error, 1);
            $this->notifyFailure();

            return;
        }

        try {
            $this->validatePrerequisites($app);
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
            // Get the appropriate deployer strategy
            $deployer = $factory->make($app);

            // Run deployment
            $result = $deployer->deploy($app, $this->deployment);

            if ($result->isSuccess()) {
                $this->deployment->markSuccess($result->output, $result->releaseName);

                $duration = round(microtime(true) - $startTime, 2);
                Log::info('[continuous-delivery] Job completed successfully', [
                    'uuid' => $this->deployment->uuid,
                    'duration_seconds' => $duration,
                    'release_name' => $result->releaseName,
                ]);

                $this->notifySuccess();
            } else {
                $this->deployment->markFailed($result->output, $result->exitCode);

                $duration = round(microtime(true) - $startTime, 2);
                Log::error('[continuous-delivery] Job failed', [
                    'uuid' => $this->deployment->uuid,
                    'exit_code' => $result->exitCode,
                    'duration_seconds' => $duration,
                ]);

                $this->notifyFailure();
            }

        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $exitCode = $e->getCode() ?: 1;

            $this->deployment->markFailed($output, $exitCode);

            $duration = round(microtime(true) - $startTime, 2);
            Log::error('[continuous-delivery] Job failed with exception', [
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
    protected function validatePrerequisites(AppConfig $app): void
    {
        // Check Envoy binary exists
        try {
            $this->getEnvoyPath();
        } catch (\RuntimeException $e) {
            throw DeploymentException::envoyNotFound();
        }

        // Check app directory exists
        if (! is_dir($app->path)) {
            throw DeploymentException::appDirDoesNotExist($app->path);
        }

        // For advanced strategy, also check releases/shared paths
        if ($app->isAdvanced()) {
            $releasesPath = dirname($app->getReleasesPath());
            if (! is_dir($releasesPath) && ! is_writable(dirname($releasesPath))) {
                throw DeploymentException::appDirNotWritable(dirname($releasesPath));
            }
        }

        // Check git is installed
        $result = Process::run('which git');
        if (! $result->successful()) {
            throw DeploymentException::gitNotInstalled();
        }

        Log::debug('[continuous-delivery] Prerequisites validated', [
            'uuid' => $this->deployment->uuid,
            'app' => $app->key,
        ]);
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
