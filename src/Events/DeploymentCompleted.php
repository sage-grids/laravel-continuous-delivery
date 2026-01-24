<?php

namespace SageGrids\ContinuousDelivery\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class DeploymentCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly DeployerDeployment $deployment,
        public readonly bool $success,
        public readonly ?string $releaseName = null
    ) {}

    /**
     * Check if the deployment was successful.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the deployment failed.
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }
}
