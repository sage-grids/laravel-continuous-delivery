<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Models\Deployment;

class RollbackCommand extends Command
{
    protected $signature = 'deploy:rollback
                            {environment : The environment to rollback}
                            {--steps=1 : Number of releases to rollback}
                            {--reason= : Reason for rollback}
                            {--force : Skip confirmation}';

    protected $description = 'Rollback to a previous deployment';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $steps = max(1, (int) $this->option('steps'));
        $reason = $this->option('reason') ?? 'Manual rollback via CLI';

        // Find recent successful deployments for this environment
        $successfulDeployments = Deployment::forEnvironment($environment)
            ->where('status', Deployment::STATUS_SUCCESS)
            ->orderBy('completed_at', 'desc')
            ->take($steps + 1)
            ->get();

        if ($successfulDeployments->count() <= $steps) {
            $this->error("Not enough successful deployments to rollback {$steps} step(s).");
            $this->info("Found only {$successfulDeployments->count()} successful deployment(s).");
            return self::FAILURE;
        }

        $currentDeployment = $successfulDeployments->first();
        $targetDeployment = $successfulDeployments->last();

        $this->info('Rollback Plan:');
        $this->table(
            ['', 'Ref', 'Commit', 'Deployed At'],
            [
                ['Current', $currentDeployment->trigger_ref, $currentDeployment->short_commit_sha, $currentDeployment->completed_at->format('Y-m-d H:i')],
                ['Target', $targetDeployment->trigger_ref, $targetDeployment->short_commit_sha, $targetDeployment->completed_at->format('Y-m-d H:i')],
            ]
        );

        if (!$this->option('force') && !$this->confirm("Proceed with rollback to {$targetDeployment->trigger_ref}?")) {
            $this->info('Rollback cancelled.');
            return self::SUCCESS;
        }

        // Create a new deployment record for the rollback
        $rollbackDeployment = Deployment::create([
            'environment' => $environment,
            'trigger_type' => 'rollback',
            'trigger_ref' => $targetDeployment->trigger_ref,
            'commit_sha' => $targetDeployment->commit_sha,
            'commit_message' => "Rollback to {$targetDeployment->trigger_ref}: {$reason}",
            'author' => 'cli:' . get_current_user(),
            'repository' => $targetDeployment->repository,
            'envoy_story' => $targetDeployment->envoy_story,
            'status' => Deployment::STATUS_QUEUED,
            'queued_at' => now(),
            'metadata' => [
                'rollback' => true,
                'rollback_from' => $currentDeployment->uuid,
                'rollback_to' => $targetDeployment->uuid,
                'rollback_reason' => $reason,
                'rollback_steps' => $steps,
            ],
        ]);

        Log::info('[continuous-delivery] Rollback initiated', [
            'uuid' => $rollbackDeployment->uuid,
            'environment' => $environment,
            'from' => $currentDeployment->trigger_ref,
            'to' => $targetDeployment->trigger_ref,
            'reason' => $reason,
        ]);

        $this->info("Rollback deployment created: {$rollbackDeployment->uuid}");

        // Execute the rollback immediately
        $this->info('Executing rollback...');

        $rollbackDeployment->markRunning();

        try {
            $envoyPath = $this->getEnvoyPath();
            $story = $rollbackDeployment->envoy_story;
            $ref = $rollbackDeployment->trigger_ref;

            $command = sprintf(
                '%s run %s --ref=%s',
                escapeshellarg($envoyPath),
                escapeshellarg($story),
                escapeshellarg($ref)
            );

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

            $result = Process::timeout(1800)->run($command);
            $output = $result->output() . "\n" . $result->errorOutput();

            if ($result->successful()) {
                $rollbackDeployment->markSuccess($output);
                $this->info('Rollback completed successfully.');

                Log::info('[continuous-delivery] Rollback completed', [
                    'uuid' => $rollbackDeployment->uuid,
                ]);

                return self::SUCCESS;
            }

            $rollbackDeployment->markFailed($output, $result->exitCode());
            $this->error('Rollback failed.');
            $this->line($output);

            Log::error('[continuous-delivery] Rollback failed', [
                'uuid' => $rollbackDeployment->uuid,
                'exit_code' => $result->exitCode(),
            ]);

            return self::FAILURE;

        } catch (\Throwable $e) {
            $rollbackDeployment->markFailed($e->getMessage(), 1);
            $this->error('Rollback failed: ' . $e->getMessage());

            Log::error('[continuous-delivery] Rollback failed with exception', [
                'uuid' => $rollbackDeployment->uuid,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    protected function getEnvoyPath(): string
    {
        $configPath = config('continuous-delivery.envoy.binary');

        if ($configPath && is_executable($configPath)) {
            return $configPath;
        }

        $vendorPath = base_path('vendor/bin/envoy');
        if (is_executable($vendorPath)) {
            return $vendorPath;
        }

        throw new \RuntimeException('Envoy binary not found');
    }
}
