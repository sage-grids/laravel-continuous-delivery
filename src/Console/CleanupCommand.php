<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Deployers\AdvancedDeployer;
use SageGrids\ContinuousDelivery\Deployers\DeployerFactory;
use SageGrids\ContinuousDelivery\Enums\DeploymentStatus;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class CleanupCommand extends Command
{
    protected $signature = 'deployer:cleanup
                            {--app= : Filter by app (or cleanup release directories)}
                            {--days=90 : Delete deployment records older than this many days}
                            {--releases : Also cleanup old release directories (advanced strategy)}
                            {--rescue : Mark stuck deployments as failed}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Skip confirmation}';

    protected $description = 'Clean up old deployment records and release directories';

    public function handle(AppRegistry $registry, DeployerFactory $factory): int
    {
        if ($this->option('rescue')) {
            return $this->rescueStuckDeployments();
        }

        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cleanupReleases = $this->option('releases');
        $appKey = $this->option('app');

        $cutoff = now()->subDays($days);

        // Build query
        $query = DeployerDeployment::where('created_at', '<', $cutoff)
            ->whereIn('status', [
                DeploymentStatus::Success,
                DeploymentStatus::Failed,
                DeploymentStatus::Rejected,
                DeploymentStatus::Expired,
            ]);

        if ($appKey) {
            $query->forApp($appKey);
        }

        $count = $query->count();

        if ($count === 0 && ! $cleanupReleases) {
            $this->info('No deployments to clean up.');

            return self::SUCCESS;
        }

        if ($count > 0) {
            $this->info("Found {$count} deployment records older than {$days} days.");

            if ($dryRun) {
                $this->table(
                    ['UUID', 'App', 'Status', 'Created'],
                    $query->limit(20)->get()->map(fn ($d) => [
                        substr($d->uuid, 0, 8).'...',
                        $d->app_key,
                        $d->status->value,
                        $d->created_at->format('Y-m-d H:i'),
                    ])
                );

                if ($count > 20) {
                    $this->info('... and '.($count - 20).' more.');
                }
            }

            if (! $dryRun && ($this->option('force') || $this->confirm("Delete {$count} deployment records?"))) {
                $deleted = $query->delete();
                $this->info("Deleted {$deleted} deployment records.");
            }
        }

        // Cleanup release directories for advanced strategy apps
        if ($cleanupReleases) {
            $this->newLine();
            $this->info('Cleaning up old release directories...');

            $apps = $appKey
                ? [$registry->get($appKey)]
                : array_values($registry->all());

            $totalCleaned = 0;

            foreach ($apps as $app) {
                if (! $app || ! $app->isAdvanced()) {
                    continue;
                }

                $deployer = $factory->make($app);

                if ($deployer instanceof AdvancedDeployer) {
                    if ($dryRun) {
                        $releases = $deployer->getAvailableReleases($app);
                        $inactiveCount = collect($releases)->where('is_active', false)->count();
                        $keepReleases = $app->getKeepReleases();
                        $toDelete = max(0, $inactiveCount - $keepReleases + 1);
                        $this->line("  {$app->name}: Would delete {$toDelete} old release(s)");
                    } else {
                        $cleaned = $deployer->cleanupOldReleases($app);
                        $totalCleaned += $cleaned;
                        if ($cleaned > 0) {
                            $this->line("  {$app->name}: Cleaned {$cleaned} old release(s)");
                        }
                    }
                }
            }

            if (! $dryRun && $totalCleaned > 0) {
                $this->info("Total: Cleaned {$totalCleaned} old release directories.");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run complete. No records or files deleted.');
        }

        return self::SUCCESS;
    }

    protected function rescueStuckDeployments(): int
    {
        $timeout = config('continuous-delivery.envoy.timeout', 1800);
        // Add a buffer to the timeout
        $cutoff = now()->subSeconds($timeout + 300);

        $stuck = DeployerDeployment::where('status', DeploymentStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck deployments found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stuck->count()} stuck deployments.");

        foreach ($stuck as $deployment) {
            if ($this->option('dry-run')) {
                $this->line("  Would fail: {$deployment->uuid} (Started: {$deployment->started_at})");
                continue;
            }

            $deployment->markFailed('Deployment timed out or process died.', 124);
            $this->line("  Marked as failed: {$deployment->uuid}");
        }

        return self::SUCCESS;
    }
}
