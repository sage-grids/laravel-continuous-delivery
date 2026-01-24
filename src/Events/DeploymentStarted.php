<?php

namespace SageGrids\ContinuousDelivery\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class DeploymentStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly DeployerDeployment $deployment
    ) {}
}
