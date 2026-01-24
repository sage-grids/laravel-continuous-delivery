<?php

namespace SageGrids\ContinuousDelivery\Enums;

enum DeploymentStrategy: string
{
    case Simple = 'simple';
    case Advanced = 'advanced';

    /**
     * Check if this is the simple (in-place git pull) strategy.
     */
    public function isSimple(): bool
    {
        return $this === self::Simple;
    }

    /**
     * Check if this is the advanced (release-based) strategy.
     */
    public function isAdvanced(): bool
    {
        return $this === self::Advanced;
    }

    /**
     * Get a human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple (In-Place)',
            self::Advanced => 'Advanced (Releases)',
        };
    }

    /**
     * Get the Envoy story prefix for this strategy.
     */
    public function storyPrefix(): string
    {
        return match ($this) {
            self::Simple => '',
            self::Advanced => 'advanced-',
        };
    }
}
