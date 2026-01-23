# Task 04: Create Deployment Model

**Phase:** 1 - Foundation
**Priority:** P0
**Estimated Effort:** Medium
**Depends On:** 03

---

## Objective

Create the `Deployment` Eloquent model with status management, approval workflow methods, and proper connection handling.

---

## File: `src/Models/Deployment.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class Deployment extends Model
{
    protected $table = 'deployments';

    protected $guarded = ['id'];

    protected $casts = [
        'approval_expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Status constants for type safety.
     */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /**
     * Use isolated database connection if configured.
     */
    public function getConnectionName(): ?string
    {
        $dbPath = config('continuous-delivery.storage.database');

        return $dbPath ? 'continuous-delivery' : config('database.default');
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Deployment $deployment) {
            if (empty($deployment->uuid)) {
                $deployment->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Status Checks
    |--------------------------------------------------------------------------
    */

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_REJECTED,
            self::STATUS_EXPIRED,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Approval Workflow
    |--------------------------------------------------------------------------
    */

    public function canBeApproved(): bool
    {
        return $this->isPendingApproval() && !$this->hasExpired();
    }

    public function canBeRejected(): bool
    {
        return $this->isPendingApproval();
    }

    public function hasExpired(): bool
    {
        return $this->approval_expires_at?->isPast() ?? false;
    }

    public function approve(string $approvedBy): self
    {
        if (!$this->canBeApproved()) {
            throw new \RuntimeException('Deployment cannot be approved');
        }

        $this->update([
            'status' => self::STATUS_QUEUED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        return $this;
    }

    public function reject(string $rejectedBy, ?string $reason = null): self
    {
        if (!$this->canBeRejected()) {
            throw new \RuntimeException('Deployment cannot be rejected');
        }

        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $rejectedBy,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $this;
    }

    public function expire(): self
    {
        if (!$this->isPendingApproval()) {
            return $this;
        }

        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);

        return $this;
    }

    public function markQueued(): self
    {
        $this->update([
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        return $this;
    }

    public function markRunning(): self
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return $this;
    }

    public function markSuccess(string $output): self
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'completed_at' => now(),
            'output' => $output,
            'exit_code' => 0,
            'duration_seconds' => $this->started_at?->diffInSeconds(now()),
        ]);

        return $this;
    }

    public function markFailed(string $output, int $exitCode): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_seconds' => $this->started_at?->diffInSeconds(now()),
        ]);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | URL Generation
    |--------------------------------------------------------------------------
    */

    public function getApproveUrl(): string
    {
        return route('continuous-delivery.approve', $this->approval_token);
    }

    public function getRejectUrl(): string
    {
        return route('continuous-delivery.reject', $this->approval_token);
    }

    public function getStatusUrl(): string
    {
        return route('continuous-delivery.status', $this->uuid);
    }

    /*
    |--------------------------------------------------------------------------
    | Computed Attributes
    |--------------------------------------------------------------------------
    */

    protected function shortCommitSha(): Attribute
    {
        return Attribute::get(fn () => substr($this->commit_sha, 0, 7));
    }

    protected function durationForHumans(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->duration_seconds) {
                return null;
            }

            if ($this->duration_seconds < 60) {
                return "{$this->duration_seconds} seconds";
            }

            $minutes = floor($this->duration_seconds / 60);
            $seconds = $this->duration_seconds % 60;

            return "{$minutes}m {$seconds}s";
        });
    }

    protected function timeUntilExpiry(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->approval_expires_at || $this->hasExpired()) {
                return null;
            }

            return $this->approval_expires_at->diffForHumans();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
        ]);
    }

    public function scopeForEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL)
            ->where('approval_expires_at', '<', now());
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Methods
    |--------------------------------------------------------------------------
    */

    public static function createFromWebhook(
        string $environment,
        string $triggerType,
        string $triggerRef,
        array $payload,
        bool $requiresApproval = false,
        int $approvalTimeoutHours = 2
    ): self {
        $config = config("continuous-delivery.environments.{$environment}");

        return self::create([
            'environment' => $environment,
            'trigger_type' => $triggerType,
            'trigger_ref' => $triggerRef,
            'commit_sha' => $payload['after'] ?? $payload['release']['target_commitish'] ?? 'unknown',
            'commit_message' => $payload['head_commit']['message'] ?? $payload['release']['body'] ?? null,
            'author' => $payload['sender']['login'] ?? 'unknown',
            'repository' => $payload['repository']['full_name'] ?? null,
            'mode' => request('mode', 'simple'),
            'envoy_story' => $config['envoy_story'] ?? $environment,
            'status' => $requiresApproval ? self::STATUS_PENDING_APPROVAL : self::STATUS_QUEUED,
            'approval_token' => $requiresApproval ? Str::random(64) : null,
            'approval_expires_at' => $requiresApproval ? now()->addHours($approvalTimeoutHours) : null,
            'queued_at' => $requiresApproval ? null : now(),
            'payload' => $payload,
        ]);
    }
}
```

---

## Acceptance Criteria

- [ ] Model uses isolated database connection when configured
- [ ] UUID is auto-generated on create
- [ ] All status transitions work correctly
- [ ] Scopes return expected results
- [ ] URL generation works with named routes
- [ ] `createFromWebhook` factory creates valid records

---

## Notes

- Status constants prevent typos and enable IDE autocomplete
- `guarded = ['id']` allows mass assignment for all other fields
- Computed attributes provide formatted data for notifications
- Scopes enable chainable queries
