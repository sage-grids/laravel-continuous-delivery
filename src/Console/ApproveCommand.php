<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApproved;
use SageGrids\ContinuousDelivery\Notifications\DeploymentStarted;

class ApproveCommand extends Command
{
    protected $signature = 'deployer:approve
                            {uuid : The deployment UUID}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Approve a pending deployment';

    public function handle(): int
    {
        $uuid = $this->argument('uuid');
        $deployment = DeployerDeployment::where('uuid', $uuid)->first();

        if (! $deployment) {
            $this->error("Deployment not found: {$uuid}");

            return self::FAILURE;
        }

        if (! $deployment->canBeApproved()) {
            $this->error("Deployment cannot be approved. Status: {$deployment->status->value}");

            if ($deployment->hasExpired()) {
                $this->line('The approval window has expired.');
            }

            return self::FAILURE;
        }

        // Show deployment details
        $this->info('Deployment Details:');
        $this->table([], [
            ['UUID', $deployment->uuid],
            ['App', "{$deployment->app_key} ({$deployment->app_name})"],
            ['Strategy', $deployment->strategy->value],
            ['Trigger', "{$deployment->trigger_name}:{$deployment->trigger_ref}"],
            ['Commit', $deployment->short_commit_sha],
            ['Author', $deployment->author],
            ['Created', $deployment->created_at->format('Y-m-d H:i:s')],
            ['Expires', $deployment->approval_expires_at?->format('Y-m-d H:i:s') ?? '-'],
        ]);

        if (! $this->option('force') && ! $this->confirm('Approve this deployment?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $approvedBy = sprintf('cli:%s', get_current_user());

        try {
            $deployment->approve($approvedBy);

            Log::info('[continuous-delivery] Deployment approved via CLI', [
                'uuid' => $deployment->uuid,
                'approved_by' => $approvedBy,
            ]);

            $this->info('Deployment approved and queued.');

            // Dispatch the job
            $this->dispatchDeployment($deployment);

            // Send notifications
            $this->notifyApproved($deployment);
            $this->notifyStarted($deployment);

            $this->line('Deployment is now running. Check status with:');
            $this->line("  php artisan deployer:status {$deployment->uuid}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("Failed to approve: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function dispatchDeployment(DeployerDeployment $deployment): void
    {
        $job = new RunDeployJob($deployment);

        $connection = config('continuous-delivery.queue.connection');
        $queue = config('continuous-delivery.queue.queue');

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    protected function notifyApproved(DeployerDeployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentApproved($deployment));
        } catch (\Throwable $e) {
            // Silent fail for notifications
        }
    }

    protected function notifyStarted(DeployerDeployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentStarted($deployment));
        } catch (\Throwable $e) {
            // Silent fail for notifications
        }
    }
}
