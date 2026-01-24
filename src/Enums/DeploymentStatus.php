<?php

namespace SageGrids\ContinuousDelivery\Enums;

enum DeploymentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    /**
     * Check if this status represents a completed deployment.
     */
    public function isComplete(): bool
    {
        return in_array($this, [
            self::Success,
            self::Failed,
            self::Rejected,
            self::Expired,
        ]);
    }

    /**
     * Check if this status represents an active deployment.
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::PendingApproval,
            self::Approved,
            self::Queued,
            self::Running,
        ]);
    }

    /**
     * Check if the deployment can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this === self::PendingApproval;
    }

    /**
     * Check if the deployment can be rejected.
     */
    public function canBeRejected(): bool
    {
        return $this === self::PendingApproval;
    }

    /**
     * Get a human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Success => 'Success',
            self::Failed => 'Failed',
        };
    }

    /**
     * Get the notification color for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Success => '#28a745',
            self::Failed => '#dc3545',
            self::PendingApproval => '#ffc107',
            self::Rejected => '#6c757d',
            self::Expired => '#6c757d',
            self::Running => '#17a2b8',
            default => '#007bff',
        };
    }
}
