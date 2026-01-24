<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class PendingCommand extends Command
{
    protected $signature = 'deployer:pending
                            {--app= : Filter by app}';

    protected $description = 'List pending deployment approvals';

    public function handle(): int
    {
        $query = DeployerDeployment::pending()->orderBy('created_at', 'desc');

        if ($app = $this->option('app')) {
            $query->forApp($app);
        }

        $deployments = $query->get();

        if ($deployments->isEmpty()) {
            $this->info('No pending deployments.');

            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'App', 'Trigger', 'Strategy', 'Author', 'Expires', 'Created'],
            $deployments->map(fn (DeployerDeployment $d) => [
                $d->uuid,
                $d->app_key,
                "{$d->trigger_name}:{$d->trigger_ref}",
                $d->strategy,
                $d->author,
                $d->hasExpired() ? '<fg=red>EXPIRED</>' : $d->time_until_expiry,
                $d->created_at->diffForHumans(),
            ])
        );

        $this->newLine();
        $this->line('Commands:');
        $this->line('  <comment>php artisan deployer:approve {uuid}</comment>  Approve deployment');
        $this->line('  <comment>php artisan deployer:reject {uuid}</comment>   Reject deployment');

        // Show copy-friendly commands for easy execution
        if ($deployments->count() > 0) {
            $this->newLine();
            $this->line('<fg=gray>Copy-paste examples:</>');
            foreach ($deployments->take(3) as $deployment) {
                $this->line("  <fg=cyan>php artisan deployer:approve {$deployment->uuid}</>");
            }
        }

        return self::SUCCESS;
    }
}
