<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentExpired;

class ExpireCommand extends Command
{
    protected $signature = 'deployer:expire';

    protected $description = 'Expire stale pending deployment approvals';

    public function handle(): int
    {
        $expired = DeployerDeployment::expired()->get();

        if ($expired->isEmpty()) {
            $this->info('No expired deployments to process.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($expired as $deployment) {
            try {
                $deployment->expire();

                Log::info('[continuous-delivery] Deployment expired', [
                    'uuid' => $deployment->uuid,
                    'app' => $deployment->app_key,
                ]);

                $this->notifyExpired($deployment);
                $count++;

            } catch (\Throwable $e) {
                Log::error('[continuous-delivery] Failed to expire deployment', [
                    'uuid' => $deployment->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$count} deployment(s).");

        return self::SUCCESS;
    }

    protected function notifyExpired(DeployerDeployment $deployment): void
    {
        try {
            $deployment->notify(new DeploymentExpired($deployment));
        } catch (\Throwable $e) {
            // Silent fail for notifications
        }
    }
}
