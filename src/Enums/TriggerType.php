<?php

namespace SageGrids\ContinuousDelivery\Enums;

enum TriggerType: string
{
    case Push = 'push';
    case Release = 'release';
    case Manual = 'manual';
    case Rollback = 'rollback';

    /**
     * Check if this trigger came from a webhook.
     */
    public function isWebhook(): bool
    {
        return in_array($this, [self::Push, self::Release]);
    }

    /**
     * Check if this trigger requires a branch.
     */
    public function requiresBranch(): bool
    {
        return $this === self::Push;
    }

    /**
     * Check if this trigger requires a tag pattern.
     */
    public function requiresTagPattern(): bool
    {
        return $this === self::Release;
    }

    /**
     * Get a human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push',
            self::Release => 'Release',
            self::Manual => 'Manual',
            self::Rollback => 'Rollback',
        };
    }
}
