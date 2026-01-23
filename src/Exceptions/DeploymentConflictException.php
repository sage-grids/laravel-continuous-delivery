<?php

namespace SageGrids\ContinuousDelivery\Exceptions;

use RuntimeException;
use SageGrids\ContinuousDelivery\Models\Deployment;

class DeploymentConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $environment,
        public readonly ?Deployment $activeDeployment = null,
        string $message = 'Active deployment in progress'
    ) {
        parent::__construct($message);
    }

    public function getActiveDeploymentUuid(): ?string
    {
        return $this->activeDeployment?->uuid;
    }
}
