<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Enums\DeploymentStatus;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class ActiveCommand extends Command
{
    protected $signature = 'deployer:active
                            {--app= : Filter by app}
                            {--trigger= : Filter by trigger}';

    protected $description = 'List all active deployments (pending_approval, approved, queued, running)';

    public function handle(): int
    {
        $query = DeployerDeployment::active()->orderBy('created_at', 'desc');

        if ($app = $this->option('app')) {
            $query->forApp($app);
        }

        if ($trigger = $this->option('trigger')) {
            $query->forTrigger($trigger);
        }

        $deployments = $query->get();

        if ($deployments->isEmpty()) {
            $this->info('No active deployments.');

            return self::SUCCESS;
        }

        $this->warn("Found {$deployments->count()} active deployment(s):");
        $this->newLine();

        $this->table(
            ['UUID', 'App', 'Trigger', 'Status', 'Strategy', 'Author', 'Created', 'Age'],
            $deployments->map(fn (DeployerDeployment $d) => [
                $d->uuid,
                $d->app_key,
                "{$d->trigger_name}:{$d->trigger_ref}",
                $this->formatStatus($d->status),
                $d->strategy->value,
                $d->author,
                $d->created_at->format('Y-m-d H:i:s'),
                $d->created_at->diffForHumans(),
            ])
        );

        $this->newLine();
        $this->line('Commands:');
        $this->line('  <comment>php artisan deployer:status {uuid}</comment>   View deployment details');
        $this->line('  <comment>php artisan deployer:cancel {uuid}</comment>   Cancel stuck deployment');
        $this->line('  <comment>php artisan deployer:approve {uuid}</comment>  Approve pending deployment');
        $this->line('  <comment>php artisan deployer:reject {uuid}</comment>   Reject pending deployment');

        return self::SUCCESS;
    }

    protected function formatStatus(DeploymentStatus $status): string
    {
        return match ($status) {
            DeploymentStatus::Running => '<fg=yellow;options=bold>running</>',
            DeploymentStatus::Queued => '<fg=yellow>queued</>',
            DeploymentStatus::PendingApproval => '<fg=cyan>pending_approval</>',
            DeploymentStatus::Approved => '<fg=green>approved</>',
            default => $status->value,
        };
    }
}
