<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use InvalidArgumentException;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Contracts\DeployerStrategy;

class DeployerFactory
{
    protected array $strategies = [];

    public function __construct()
    {
        $this->strategies = [
            'simple' => SimpleDeployer::class,
            'advanced' => AdvancedDeployer::class,
        ];
    }

    /**
     * Create a deployer for the given app configuration.
     */
    public function make(AppConfig $app): DeployerStrategy
    {
        return $this->makeForStrategy($app->strategy);
    }

    /**
     * Create a deployer for the given strategy.
     */
    public function makeForStrategy(string $strategy): DeployerStrategy
    {
        if (! isset($this->strategies[$strategy])) {
            throw new InvalidArgumentException("Unknown deployment strategy: {$strategy}");
        }

        $class = $this->strategies[$strategy];

        return app($class);
    }

    /**
     * Register a custom strategy.
     */
    public function extend(string $name, string $class): void
    {
        if (! is_subclass_of($class, DeployerStrategy::class)) {
            throw new InvalidArgumentException("Strategy must implement DeployerStrategy interface");
        }

        $this->strategies[$name] = $class;
    }

    /**
     * Get all registered strategy names.
     */
    public function getAvailableStrategies(): array
    {
        return array_keys($this->strategies);
    }
}
