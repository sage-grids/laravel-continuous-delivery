<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class StatusCommand extends Command
{
    protected $signature = 'deployer:status
                            {uuid? : The deployment UUID (optional, shows recent if omitted)}
                            {--app= : Filter by app}
                            {--trigger= : Filter by trigger}
                            {--limit=10 : Number of deployments to show}';

    protected $description = 'Show deployment status';

    public function handle(): int
    {
        if ($uuid = $this->argument('uuid')) {
            return $this->showSingle($uuid);
        }

        return $this->showList();
    }

    protected function showSingle(string $uuid): int
    {
        $deployment = DeployerDeployment::where('uuid', $uuid)->first();

        if (! $deployment) {
            $this->error("Deployment not found: {$uuid}");

            return self::FAILURE;
        }

        $statusColor = match ($deployment->status) {
            DeployerDeployment::STATUS_SUCCESS => 'green',
            DeployerDeployment::STATUS_FAILED => 'red',
            DeployerDeployment::STATUS_RUNNING => 'yellow',
            DeployerDeployment::STATUS_PENDING_APPROVAL => 'cyan',
            DeployerDeployment::STATUS_REJECTED, DeployerDeployment::STATUS_EXPIRED => 'gray',
            default => 'white',
        };

        $this->newLine();
        $this->line("  <fg={$statusColor};options=bold>{$deployment->status}</>");
        $this->newLine();

        $this->table([], [
            ['UUID', $deployment->uuid],
            ['App', "{$deployment->app_key} ({$deployment->app_name})"],
            ['Strategy', $deployment->strategy],
            ['Trigger', "{$deployment->trigger_name}:{$deployment->trigger_ref}"],
            ['Commit', $deployment->commit_sha],
            ['Author', $deployment->author],
            ['Status', $deployment->status],
            ['Release', $deployment->release_name ?? '-'],
            ['Created', $deployment->created_at->format('Y-m-d H:i:s')],
            ['Started', $deployment->started_at?->format('Y-m-d H:i:s') ?? '-'],
            ['Completed', $deployment->completed_at?->format('Y-m-d H:i:s') ?? '-'],
            ['Duration', $deployment->duration_for_humans ?? '-'],
            ['Exit Code', $deployment->exit_code ?? '-'],
        ]);

        if ($deployment->isPendingApproval()) {
            $this->newLine();
            $this->line('Awaiting approval:');
            $this->line("  Approve: php artisan deployer:approve {$deployment->uuid}");
            $this->line("  Reject:  php artisan deployer:reject {$deployment->uuid}");
            $this->line("  Web URL: {$deployment->getApproveUrl()}");
        }

        if ($deployment->output) {
            $this->newLine();
            $this->info('Output:');
            $this->line($deployment->output);
        }

        return self::SUCCESS;
    }

    protected function showList(): int
    {
        $query = DeployerDeployment::orderBy('created_at', 'desc')
            ->limit($this->option('limit'));

        if ($app = $this->option('app')) {
            $query->forApp($app);
        }

        if ($trigger = $this->option('trigger')) {
            $query->forTrigger($trigger);
        }

        $deployments = $query->get();

        if ($deployments->isEmpty()) {
            $this->info('No deployments found.');

            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'App', 'Trigger', 'Status', 'Strategy', 'Author', 'Duration', 'Created'],
            $deployments->map(fn (DeployerDeployment $d) => [
                substr($d->uuid, 0, 8).'...',
                $d->app_key,
                "{$d->trigger_name}:{$d->trigger_ref}",
                $this->formatStatus($d->status),
                $d->strategy,
                $d->author,
                $d->duration_for_humans ?? '-',
                $d->created_at->diffForHumans(),
            ])
        );

        $this->newLine();
        $this->line('View details: <comment>php artisan deployer:status {uuid}</comment>');

        return self::SUCCESS;
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            DeployerDeployment::STATUS_SUCCESS => '<fg=green>success</>',
            DeployerDeployment::STATUS_FAILED => '<fg=red>failed</>',
            DeployerDeployment::STATUS_RUNNING => '<fg=yellow>running</>',
            DeployerDeployment::STATUS_QUEUED => '<fg=yellow>queued</>',
            DeployerDeployment::STATUS_PENDING_APPROVAL => '<fg=cyan>pending</>',
            DeployerDeployment::STATUS_APPROVED => '<fg=green>approved</>',
            DeployerDeployment::STATUS_REJECTED => '<fg=gray>rejected</>',
            DeployerDeployment::STATUS_EXPIRED => '<fg=gray>expired</>',
            default => $status,
        };
    }
}
