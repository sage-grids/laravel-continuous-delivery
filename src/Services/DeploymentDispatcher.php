<?php

namespace SageGrids\ContinuousDelivery\Services;

use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class DeploymentDispatcher
{
    /**
     * Dispatch a deployment job to the queue.
     */
    public function dispatch(DeployerDeployment $deployment): void
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
}
