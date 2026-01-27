<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Enums\DeploymentStatus;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class CancelCommand extends Command
{
    protected $signature = 'deployer:cancel
                            {uuid : The deployment UUID to cancel}
                            {--reason= : Reason for cancellation}
                            {--force : Cancel without confirmation}';

    protected $description = 'Cancel a stuck or active deployment';

    public function handle(): int
    {
        $uuid = $this->argument('uuid');
        $deployment = DeployerDeployment::where('uuid', $uuid)->first();

        if (! $deployment) {
            $this->error("Deployment not found: {$uuid}");

            return self::FAILURE;
        }

        if (! $deployment->status->isActive()) {
            $this->error("Deployment is not active (status: {$deployment->status->value})");
            $this->line('Only active deployments (pending_approval, approved, queued, running) can be cancelled.');

            return self::FAILURE;
        }

        $this->table([], [
            ['UUID', $deployment->uuid],
            ['App', "{$deployment->app_key} ({$deployment->app_name})"],
            ['Trigger', "{$deployment->trigger_name}:{$deployment->trigger_ref}"],
            ['Status', $deployment->status->value],
            ['Created', $deployment->created_at->format('Y-m-d H:i:s').' ('.$deployment->created_at->diffForHumans().')'],
            ['Started', $deployment->started_at?->format('Y-m-d H:i:s') ?? '-'],
        ]);

        if (! $this->option('force')) {
            if (! $this->confirm('Are you sure you want to cancel this deployment?')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $reason = $this->option('reason') ?? $this->ask('Reason for cancellation (optional)', 'Manually cancelled via CLI');

        try {
            $previousStatus = $deployment->status->value;

            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'completed_at' => now(),
                'output' => $deployment->output
                    ? $deployment->output."\n\n--- CANCELLED ---\nReason: {$reason}\nCancelled by: CLI\nPrevious status: {$previousStatus}"
                    : "--- CANCELLED ---\nReason: {$reason}\nCancelled by: CLI\nPrevious status: {$previousStatus}",
            ]);

            Log::info('[continuous-delivery] Deployment cancelled via CLI', [
                'uuid' => $deployment->uuid,
                'app' => $deployment->app_key,
                'previous_status' => $previousStatus,
                'reason' => $reason,
            ]);

            $this->info("Deployment {$uuid} has been cancelled.");
            $this->line("Previous status: {$previousStatus} → failed");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[continuous-delivery] Failed to cancel deployment', [
                'uuid' => $deployment->uuid,
                'error' => $e->getMessage(),
            ]);

            $this->error("Failed to cancel deployment: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
