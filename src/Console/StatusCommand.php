<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\Deployment;

class StatusCommand extends Command
{
    protected $signature = 'deploy:status
                            {uuid? : The deployment UUID (optional, shows recent if omitted)}
                            {--environment= : Filter by environment}
                            {--limit=10 : Number of deployments to show}';

    protected $description = 'Show deployment status.';

    public function handle(): int
    {
        if ($uuid = $this->argument('uuid')) {
            return $this->showSingle($uuid);
        }

        return $this->showList();
    }

    protected function showSingle(string $uuid): int
    {
        $deployment = Deployment::where('uuid', $uuid)->first();

        if (!$deployment) {
            $this->error("Deployment not found: {$uuid}");
            return self::FAILURE;
        }

        $statusColor = match ($deployment->status) {
            Deployment::STATUS_SUCCESS => 'green',
            Deployment::STATUS_FAILED => 'red',
            Deployment::STATUS_RUNNING => 'yellow',
            Deployment::STATUS_PENDING_APPROVAL => 'cyan',
            Deployment::STATUS_REJECTED, Deployment::STATUS_EXPIRED => 'gray',
            default => 'white',
        };

        $this->newLine();
        $this->line("  <fg={$statusColor};options=bold>{$deployment->status}</>");
        $this->newLine();

        $this->table([], [
            ['UUID', $deployment->uuid],
            ['Environment', $deployment->environment],
            ['Trigger', "{$deployment->trigger_type}:{$deployment->trigger_ref}"],
            ['Commit', $deployment->commit_sha],
            ['Author', $deployment->author],
            ['Status', $deployment->status],
            ['Created', $deployment->created_at->format('Y-m-d H:i:s')],
            ['Started', $deployment->started_at?->format('Y-m-d H:i:s') ?? '-'],
            ['Completed', $deployment->completed_at?->format('Y-m-d H:i:s') ?? '-'],
            ['Duration', $deployment->duration_for_humans ?? '-'],
            ['Exit Code', $deployment->exit_code ?? '-'],
        ]);

        if ($deployment->isPendingApproval()) {
            $this->newLine();
            $this->line('Awaiting approval:');
            $this->line("  Approve: php artisan deploy:approve {$deployment->uuid}");
            $this->line("  Reject:  php artisan deploy:reject {$deployment->uuid}");
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
        $query = Deployment::orderBy('created_at', 'desc')
            ->limit($this->option('limit'));

        if ($environment = $this->option('environment')) {
            $query->forEnvironment($environment);
        }

        $deployments = $query->get();

        if ($deployments->isEmpty()) {
            $this->info('No deployments found.');
            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'Environment', 'Status', 'Trigger', 'Author', 'Duration', 'Created'],
            $deployments->map(fn (Deployment $d) => [
                substr($d->uuid, 0, 8) . '...',
                $d->environment,
                $this->formatStatus($d->status),
                "{$d->trigger_type}:{$d->trigger_ref}",
                $d->author,
                $d->duration_for_humans ?? '-',
                $d->created_at->diffForHumans(),
            ])
        );

        $this->newLine();
        $this->line('View details: <comment>php artisan deploy:status {uuid}</comment>');

        return self::SUCCESS;
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            Deployment::STATUS_SUCCESS => '<fg=green>success</>',
            Deployment::STATUS_FAILED => '<fg=red>failed</>',
            Deployment::STATUS_RUNNING => '<fg=yellow>running</>',
            Deployment::STATUS_QUEUED => '<fg=yellow>queued</>',
            Deployment::STATUS_PENDING_APPROVAL => '<fg=cyan>pending</>',
            Deployment::STATUS_APPROVED => '<fg=green>approved</>',
            Deployment::STATUS_REJECTED => '<fg=gray>rejected</>',
            Deployment::STATUS_EXPIRED => '<fg=gray>expired</>',
            default => $status,
        };
    }
}
