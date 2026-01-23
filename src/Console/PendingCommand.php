<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\Deployment;

class PendingCommand extends Command
{
    protected $signature = 'deploy:pending
                            {--environment= : Filter by environment}';

    protected $description = 'List pending deployment approvals.';

    public function handle(): int
    {
        $query = Deployment::pending()->orderBy('created_at', 'desc');

        if ($environment = $this->option('environment')) {
            $query->forEnvironment($environment);
        }

        $deployments = $query->get();

        if ($deployments->isEmpty()) {
            $this->info('No pending deployments.');
            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'Environment', 'Trigger', 'Author', 'Expires', 'Created'],
            $deployments->map(fn (Deployment $d) => [
                $d->uuid,
                $d->environment,
                "{$d->trigger_type}:{$d->trigger_ref}",
                $d->author,
                $d->hasExpired() ? 'EXPIRED' : $d->time_until_expiry,
                $d->created_at->diffForHumans(),
            ])
        );

        $this->newLine();
        $this->line('Commands:');
        $this->line('  <comment>php artisan deploy:approve {uuid}</comment>  Approve deployment');
        $this->line('  <comment>php artisan deploy:reject {uuid}</comment>   Reject deployment');

        // Show copy-friendly commands for easy execution
        if ($deployments->count() > 0) {
            $this->newLine();
            $this->line('<fg=gray>Copy-paste examples:</>');
            foreach ($deployments->take(3) as $deployment) {
                $this->line("  <fg=cyan>php artisan deploy:approve {$deployment->uuid}</>");
            }
        }

        return self::SUCCESS;
    }
}
