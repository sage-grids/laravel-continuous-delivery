<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentRejected;

class RejectCommand extends Command
{
    protected $signature = 'deployer:reject
                            {uuid : The deployment UUID}
                            {--reason= : Reason for rejection}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Reject a pending deployment';

    public function handle(): int
    {
        $uuid = $this->argument('uuid');
        $deployment = DeployerDeployment::where('uuid', $uuid)->first();

        if (! $deployment) {
            $this->error("Deployment not found: {$uuid}");

            return self::FAILURE;
        }

        if (! $deployment->canBeRejected()) {
            $this->error("Deployment cannot be rejected. Status: {$deployment->status}");

            return self::FAILURE;
        }

        // Show deployment details
        $this->info('Deployment Details:');
        $this->table([], [
            ['UUID', $deployment->uuid],
            ['App', "{$deployment->app_key} ({$deployment->app_name})"],
            ['Strategy', $deployment->strategy],
            ['Trigger', "{$deployment->trigger_name}:{$deployment->trigger_ref}"],
            ['Commit', $deployment->short_commit_sha],
            ['Author', $deployment->author],
            ['Created', $deployment->created_at->format('Y-m-d H:i:s')],
        ]);

        if (! $this->option('force') && ! $this->confirm('Reject this deployment?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $rejectedBy = sprintf('cli:%s', get_current_user());
        $reason = $this->option('reason') ?? $this->ask('Reason for rejection (optional)', 'Rejected via CLI');

        try {
            $deployment->reject($rejectedBy, $reason);

            Log::info('[continuous-delivery] Deployment rejected via CLI', [
                'uuid' => $deployment->uuid,
                'rejected_by' => $rejectedBy,
                'reason' => $reason,
            ]);

            $this->info('Deployment rejected.');

            // Send notification
            $this->notifyRejected($deployment);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("Failed to reject: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function notifyRejected(DeployerDeployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentRejected($deployment));
        } catch (\Throwable $e) {
            // Silent fail for notifications
        }
    }
}
