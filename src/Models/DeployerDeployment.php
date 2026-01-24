<?php

namespace SageGrids\ContinuousDelivery\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\AppRegistry;

class DeployerDeployment extends Model
{
    use Notifiable;

    protected $table = 'deployer_deployments';

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

    // Status constants
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
        return static::getDeploymentConnection();
    }

    /**
     * Get the deployment database connection name.
     */
    public static function getDeploymentConnection(): ?string
    {
        $connection = config('continuous-delivery.database.connection');

        return $connection === 'sqlite' ? 'continuous-delivery' : config('database.default');
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DeployerDeployment $deployment) {
            if (empty($deployment->uuid)) {
                $deployment->uuid = (string) Str::uuid();
            }

            // Auto-compute approval token hash
            if (! empty($deployment->approval_token) && empty($deployment->approval_token_hash)) {
                $deployment->approval_token_hash = hash('sha256', $deployment->approval_token);
            }
        });

        static::updating(function (DeployerDeployment $deployment) {
            // Update hash when token changes
            if ($deployment->isDirty('approval_token') && ! empty($deployment->approval_token)) {
                $deployment->approval_token_hash = hash('sha256', $deployment->approval_token);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function releases(): HasMany
    {
        return $this->hasMany(DeployerRelease::class, 'deployment_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Notification Routing
    |--------------------------------------------------------------------------
    */

    public function routeNotificationForTelegram(): ?string
    {
        // First try app-specific notification
        $appConfig = $this->getAppConfig();
        if ($appConfig && $appConfig->getTelegramChatId()) {
            return $appConfig->getTelegramChatId();
        }

        // Fall back to global config
        return config('continuous-delivery.notifications.telegram.chat_id');
    }

    public function routeNotificationForSlack(): ?string
    {
        // First try app-specific notification
        $appConfig = $this->getAppConfig();
        if ($appConfig && $appConfig->getSlackWebhook()) {
            return $appConfig->getSlackWebhook();
        }

        // Fall back to global config
        return config('continuous-delivery.notifications.slack.webhook_url');
    }

    /**
     * Get the app configuration for this deployment.
     */
    public function getAppConfig(): ?AppConfig
    {
        return app(AppRegistry::class)->get($this->app_key);
    }

    /**
     * Send a notification with error handling.
     */
    public function sendNotification($notification): bool
    {
        try {
            $this->notify($notification);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[continuous-delivery] Failed to send notification', [
                'deployment' => $this->uuid,
                'notification' => get_class($notification),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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

    public function isSimpleStrategy(): bool
    {
        return $this->strategy === 'simple';
    }

    public function isAdvancedStrategy(): bool
    {
        return $this->strategy === 'advanced';
    }

    /*
    |--------------------------------------------------------------------------
    | Approval Workflow
    |--------------------------------------------------------------------------
    */

    public function canBeApproved(): bool
    {
        return $this->isPendingApproval() && ! $this->hasExpired();
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
        if (! $this->canBeApproved()) {
            throw new \RuntimeException('Deployment cannot be approved');
        }

        $this->update([
            'status' => self::STATUS_QUEUED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'queued_at' => now(),
        ]);

        return $this;
    }

    public function reject(string $rejectedBy, ?string $reason = null): self
    {
        if (! $this->canBeRejected()) {
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
        if (! $this->isPendingApproval()) {
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

    public function markSuccess(string $output, ?string $releaseName = null): self
    {
        $data = [
            'status' => self::STATUS_SUCCESS,
            'completed_at' => now(),
            'output' => $output,
            'exit_code' => 0,
            'duration_seconds' => $this->started_at?->diffInSeconds(now()),
        ];

        if ($releaseName) {
            $data['release_name'] = $releaseName;
        }

        $this->update($data);

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
            if (! $this->duration_seconds) {
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
            if (! $this->approval_expires_at || $this->hasExpired()) {
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

    public function scopeForApp($query, string $appKey)
    {
        return $query->where('app_key', $appKey);
    }

    public function scopeForTrigger($query, string $triggerName)
    {
        return $query->where('trigger_name', $triggerName);
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

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Methods
    |--------------------------------------------------------------------------
    */

    public static function createFromWebhook(
        AppConfig $app,
        array $trigger,
        string $triggerType,
        string $triggerRef,
        array $payload,
    ): self {
        $requiresApproval = $app->requiresApproval($trigger);
        $approvalToken = $requiresApproval ? Str::random(64) : null;
        $approvalTimeoutHours = $app->getApprovalTimeout($trigger);

        return self::create([
            'app_key' => $app->key,
            'app_name' => $app->name,
            'trigger_name' => $trigger['name'],
            'trigger_type' => $triggerType,
            'trigger_ref' => $triggerRef,
            'repository' => $payload['repository']['full_name'] ?? $app->repository,
            'commit_sha' => self::extractCommitSha($payload, $triggerType),
            'commit_message' => $payload['head_commit']['message'] ?? $payload['release']['body'] ?? null,
            'author' => $payload['sender']['login'] ?? 'unknown',
            'strategy' => $app->strategy,
            'envoy_story' => $app->getEnvoyStory($trigger),
            'status' => $requiresApproval ? self::STATUS_PENDING_APPROVAL : self::STATUS_QUEUED,
            'approval_token' => $approvalToken,
            'approval_token_hash' => $approvalToken ? hash('sha256', $approvalToken) : null,
            'approval_expires_at' => $requiresApproval ? now()->addHours($approvalTimeoutHours) : null,
            'queued_at' => $requiresApproval ? null : now(),
            'payload' => $payload,
        ]);
    }

    public static function createManual(
        AppConfig $app,
        array $trigger,
        string $ref,
        ?string $commitSha = null,
    ): self {
        return self::create([
            'app_key' => $app->key,
            'app_name' => $app->name,
            'trigger_name' => $trigger['name'],
            'trigger_type' => 'manual',
            'trigger_ref' => $ref,
            'repository' => $app->repository,
            'commit_sha' => $commitSha ?? 'HEAD',
            'author' => 'manual',
            'strategy' => $app->strategy,
            'envoy_story' => $app->getEnvoyStory($trigger),
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
    }

    public static function createRollback(
        AppConfig $app,
        string $targetRelease,
        ?string $commitSha = null,
    ): self {
        $story = $app->isAdvanced() ? 'advanced-rollback' : 'rollback';

        return self::create([
            'app_key' => $app->key,
            'app_name' => $app->name,
            'trigger_name' => 'rollback',
            'trigger_type' => 'rollback',
            'trigger_ref' => $targetRelease,
            'repository' => $app->repository,
            'commit_sha' => $commitSha ?? 'rollback',
            'author' => 'rollback',
            'strategy' => $app->strategy,
            'envoy_story' => $story,
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
    }

    protected static function extractCommitSha(array $payload, string $triggerType): string
    {
        if ($triggerType === 'push') {
            return $payload['after'] ?? $payload['head_commit']['id'] ?? 'unknown';
        }

        if ($triggerType === 'release') {
            return $payload['release']['target_commitish'] ?? 'unknown';
        }

        return 'unknown';
    }

    /**
     * Find a deployment by its approval token using hash lookup.
     */
    public static function findByApprovalToken(string $token): ?self
    {
        if (strlen($token) !== 64) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        // First try hash lookup (new records)
        $deployment = static::where('approval_token_hash', $tokenHash)->first();

        if ($deployment) {
            return $deployment;
        }

        // Fallback to direct token lookup for legacy records
        return static::where('approval_token', $token)->first();
    }
}
