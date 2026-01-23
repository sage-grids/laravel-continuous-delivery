# Task 08: Update RunDeployJob

**Phase:** 2 - Webhook & Envoy
**Priority:** P0
**Estimated Effort:** Medium
**Depends On:** 04, 07

---

## Objective

Refactor `RunDeployJob` to use the Deployment model and execute Envoy instead of bash scripts.

---

## File: `src/Jobs/RunDeployJob.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentFailed;
use SageGrids\ContinuousDelivery\Notifications\DeploymentSucceeded;

class RunDeployJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Deployment $deployment
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Refresh to get latest state
        $this->deployment->refresh();

        // Verify deployment can still run
        if (!$this->canRun()) {
            Log::warning('[continuous-delivery] Deployment skipped - invalid state', [
                'uuid' => $this->deployment->uuid,
                'status' => $this->deployment->status,
            ]);
            return;
        }

        Log::info('[continuous-delivery] Starting deployment', [
            'uuid' => $this->deployment->uuid,
            'environment' => $this->deployment->environment,
            'story' => $this->deployment->envoy_story,
            'ref' => $this->deployment->trigger_ref,
        ]);

        $this->deployment->markRunning();

        try {
            $result = $this->runEnvoy();

            if ($result->successful()) {
                $this->handleSuccess($result->output());
            } else {
                $this->handleFailure(
                    $result->output() . "\n" . $result->errorOutput(),
                    $result->exitCode() ?? 1
                );
            }
        } catch (\Throwable $e) {
            $this->handleFailure(
                "Exception: {$e->getMessage()}\n{$e->getTraceAsString()}",
                255
            );
        }
    }

    /**
     * Check if deployment can run.
     */
    protected function canRun(): bool
    {
        return in_array($this->deployment->status, [
            Deployment::STATUS_QUEUED,
            Deployment::STATUS_APPROVED,
        ]);
    }

    /**
     * Execute Envoy command.
     */
    protected function runEnvoy(): \Illuminate\Process\ProcessResult
    {
        $envoyPath = $this->getEnvoyPath();
        $story = $this->deployment->envoy_story;
        $ref = $this->deployment->trigger_ref;
        $timeout = config('continuous-delivery.envoy.timeout', 1800);

        // Build command
        $command = sprintf(
            '%s %s run %s --ref=%s',
            $this->getPhpBinary(),
            escapeshellarg($envoyPath),
            escapeshellarg($story),
            escapeshellarg($ref)
        );

        Log::info('[continuous-delivery] Executing Envoy', [
            'command' => $command,
            'timeout' => $timeout,
        ]);

        // Set working directory to app root
        $workingDir = config('continuous-delivery.app_dir', base_path());

        return Process::timeout($timeout)
            ->path($workingDir)
            ->env($this->getEnvoyEnvironment())
            ->run($command);
    }

    /**
     * Get path to Envoy binary.
     */
    protected function getEnvoyPath(): string
    {
        $customPath = config('continuous-delivery.envoy.path');

        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        // Default: vendor binary
        $vendorPath = base_path('vendor/bin/envoy');

        if (file_exists($vendorPath)) {
            return $vendorPath;
        }

        throw new \RuntimeException('Envoy binary not found');
    }

    /**
     * Get PHP binary path.
     */
    protected function getPhpBinary(): string
    {
        return env('CD_PHP_PATH', PHP_BINARY);
    }

    /**
     * Get environment variables for Envoy execution.
     */
    protected function getEnvoyEnvironment(): array
    {
        return [
            'CD_APP_DIR' => config('continuous-delivery.app_dir', base_path()),
            'CD_BRANCH' => $this->deployment->trigger_ref,
            'CD_TELEGRAM_BOT_ID' => config('continuous-delivery.notifications.telegram.bot_id'),
            'CD_TELEGRAM_CHAT_ID' => config('continuous-delivery.notifications.telegram.chat_id'),
            'CD_SLACK_WEBHOOK' => config('continuous-delivery.notifications.slack.webhook_url'),
        ];
    }

    /**
     * Handle successful deployment.
     */
    protected function handleSuccess(string $output): void
    {
        $this->deployment->markSuccess($output);

        Log::info('[continuous-delivery] Deployment succeeded', [
            'uuid' => $this->deployment->uuid,
            'duration' => $this->deployment->duration_for_humans,
        ]);

        $this->notifySuccess();
    }

    /**
     * Handle failed deployment.
     */
    protected function handleFailure(string $output, int $exitCode): void
    {
        $this->deployment->markFailed($output, $exitCode);

        Log::error('[continuous-delivery] Deployment failed', [
            'uuid' => $this->deployment->uuid,
            'exit_code' => $exitCode,
            'output' => substr($output, -2000), // Last 2000 chars
        ]);

        $this->notifyFailure();

        // Re-throw to mark job as failed
        throw new \RuntimeException(
            "Deployment failed with exit code {$exitCode}"
        );
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
    public function failed(?\Throwable $exception): void
    {
        Log::error('[continuous-delivery] Job failed', [
            'uuid' => $this->deployment->uuid,
            'exception' => $exception?->getMessage(),
        ]);

        // Ensure deployment is marked as failed
        if ($this->deployment->isRunning()) {
            $this->deployment->markFailed(
                $exception?->getMessage() ?? 'Unknown error',
                255
            );
            $this->notifyFailure();
        }
    }
}
```

---

## Key Changes from Original

| Aspect | Before | After |
|--------|--------|-------|
| Input | `string $mode` | `Deployment $deployment` |
| Execution | Bash script | Envoy story |
| Status tracking | Log only | Database + Log |
| Notifications | None | Success/Failure via channels |
| Error handling | Basic exception | Full lifecycle handling |

---

## Execution Flow

```
1. Job starts
2. Refresh deployment from DB
3. Verify status is queued/approved
4. Mark as running
5. Execute Envoy command
6. Parse result
   ├─ Success → markSuccess() → notify
   └─ Failure → markFailed() → notify → throw
7. Job completes (or fails and triggers failed())
```

---

## Acceptance Criteria

- [ ] Job executes correct Envoy story
- [ ] Deployment status updates throughout lifecycle
- [ ] Success notification is sent
- [ ] Failure notification is sent
- [ ] Output is captured and stored
- [ ] Duration is calculated correctly
- [ ] Job timeout matches config
- [ ] Environment variables are passed to Envoy

---

## Notes

- Job has `$tries = 1` - deployments should not auto-retry
- Output is limited in logs (last 2000 chars) to prevent log bloat
- `failed()` method handles edge cases where job dies unexpectedly
