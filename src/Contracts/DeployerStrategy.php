<?php

namespace SageGrids\ContinuousDelivery\Contracts;

use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\DeployerResult;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

interface DeployerStrategy
{
    /**
     * Deploy the application.
     */
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult;

    /**
     * Rollback to a previous release.
     */
    public function rollback(AppConfig $app, DeployerDeployment $deployment, ?string $targetRelease = null): DeployerResult;

    /**
     * Get available releases/versions to rollback to.
     */
    public function getAvailableReleases(AppConfig $app): array;
}
