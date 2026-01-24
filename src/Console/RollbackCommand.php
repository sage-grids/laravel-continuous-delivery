<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Deployers\DeployerFactory;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class RollbackCommand extends Command
{
    protected $signature = 'deployer:rollback
                            {app=default : The app key}
                            {--release= : Target release name (for advanced strategy)}
                            {--steps=1 : Number of releases to rollback (for simple strategy)}
                            {--reason= : Reason for rollback}
                            {--force : Skip confirmation}';

    protected $description = 'Rollback to a previous release';

    public function handle(AppRegistry $registry, DeployerFactory $factory): int
    {
        $app = $registry->get($this->argument('app'));

        if (! $app) {
            $this->error("App not found: {$this->argument('app')}");

            return self::FAILURE;
        }

        $reason = $this->option('reason') ?? 'Manual rollback via CLI';
        $deployer = $factory->make($app);

        // Get available releases
        $releases = $deployer->getAvailableReleases($app);

        if (empty($releases)) {
            $this->error('No releases available for rollback.');

            return self::FAILURE;
        }

        // Determine target release
        $targetRelease = $this->option('release');
        if (! $targetRelease && $app->isAdvanced()) {
            // For advanced strategy, show available releases and let user choose
            $this->info('Available releases:');
            foreach ($releases as $i => $release) {
                $active = ($release['is_active'] ?? false) ? ' <fg=green>(ACTIVE)</>' : '';
                $this->line("  {$release['name']}{$active}");
            }
            $this->newLine();

            // Find the previous release
            $previousRelease = null;
            foreach ($releases as $i => $release) {
                if (! ($release['is_active'] ?? false) && $previousRelease === null) {
                    $previousRelease = $release['name'];
                }
            }

            if (! $previousRelease) {
                $this->error('No previous release found to rollback to.');

                return self::FAILURE;
            }

            $targetRelease = $this->ask('Target release', $previousRelease);
        } elseif (! $targetRelease) {
            // Simple strategy: use steps
            $targetRelease = (string) max(1, (int) $this->option('steps'));
        }

        // Show rollback plan
        $this->info('Rollback Plan:');
        $this->table([], [
            ['App', $app->name],
            ['Strategy', $app->strategy],
            ['Target', $targetRelease],
            ['Reason', $reason],
        ]);

        if (! $this->option('force') && ! $this->confirm('Proceed with rollback?')) {
            $this->info('Rollback cancelled.');

            return self::SUCCESS;
        }

        // Create rollback deployment record
        $deployment = DeployerDeployment::createRollback($app, $targetRelease);
        $deployment->update([
            'commit_message' => "Rollback to {$targetRelease}: {$reason}",
            'metadata' => [
                'rollback' => true,
                'target_release' => $targetRelease,
                'reason' => $reason,
            ],
        ]);

        Log::info('[continuous-delivery] Rollback initiated', [
            'uuid' => $deployment->uuid,
            'app' => $app->key,
            'target' => $targetRelease,
            'reason' => $reason,
        ]);

        $this->info("Rollback deployment created: {$deployment->uuid}");
        $this->info('Executing rollback...');

        $deployment->markRunning();

        try {
            $result = $deployer->rollback($app, $deployment, $targetRelease);

            if ($result->isSuccess()) {
                $deployment->markSuccess($result->output, $result->releaseName);
                $this->info('Rollback completed successfully.');

                Log::info('[continuous-delivery] Rollback completed', [
                    'uuid' => $deployment->uuid,
                ]);

                return self::SUCCESS;
            }

            $deployment->markFailed($result->output, $result->exitCode);
            $this->error('Rollback failed.');
            $this->line($result->output);

            Log::error('[continuous-delivery] Rollback failed', [
                'uuid' => $deployment->uuid,
                'exit_code' => $result->exitCode,
            ]);

            return self::FAILURE;

        } catch (\Throwable $e) {
            $deployment->markFailed($e->getMessage(), 1);
            $this->error('Rollback failed: '.$e->getMessage());

            Log::error('[continuous-delivery] Rollback failed with exception', [
                'uuid' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }
}
