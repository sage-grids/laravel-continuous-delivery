<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\Deployment;

class CleanupCommand extends Command
{
    protected $signature = 'deploy:cleanup
                            {--days=90 : Delete deployments older than this many days}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Skip confirmation}';

    protected $description = 'Clean up old completed deployment records';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $cutoff = now()->subDays($days);

        // Find completed deployments older than cutoff
        $query = Deployment::where('created_at', '<', $cutoff)
            ->whereIn('status', [
                Deployment::STATUS_SUCCESS,
                Deployment::STATUS_FAILED,
                Deployment::STATUS_REJECTED,
                Deployment::STATUS_EXPIRED,
            ]);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No deployments to clean up.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} deployments older than {$days} days.");

        if ($dryRun) {
            $this->table(
                ['UUID', 'Environment', 'Status', 'Created'],
                $query->limit(20)->get()->map(fn ($d) => [
                    substr($d->uuid, 0, 8) . '...',
                    $d->environment,
                    $d->status,
                    $d->created_at->format('Y-m-d H:i'),
                ])
            );

            if ($count > 20) {
                $this->info("... and " . ($count - 20) . " more.");
            }

            $this->info('Dry run complete. No records deleted.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Delete {$count} deployment records?")) {
            $this->info('Cleanup cancelled.');
            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} deployment records.");

        return self::SUCCESS;
    }
}
