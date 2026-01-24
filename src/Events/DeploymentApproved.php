<?php

namespace SageGrids\ContinuousDelivery\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class DeploymentApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly DeployerDeployment $deployment,
        public readonly string $approvedBy
    ) {}
}
