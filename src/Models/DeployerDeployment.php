<?php

namespace SageGrids\ContinuousDelivery\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Enums\DeploymentStatus;
use SageGrids\ContinuousDelivery\Enums\DeploymentStrategy;
use SageGrids\ContinuousDelivery\Enums\TriggerType;
use SageGrids\ContinuousDelivery\Events\DeploymentApproved;
use SageGrids\ContinuousDelivery\Events\DeploymentCompleted;
use SageGrids\ContinuousDelivery\Events\DeploymentCreated;
use SageGrids\ContinuousDelivery\Events\DeploymentFailed as DeploymentFailedEvent;
use SageGrids\ContinuousDelivery\Events\DeploymentRejected;
use SageGrids\ContinuousDelivery\Events\DeploymentStarted;

class DeployerDeployment extends Model
{
    use Notifiable;

    protected $table = 'deployer_deployments';

    /**
     * Transient property to hold the plaintext token for URL generation.
     * This is never persisted to the database.
     */
    protected ?string $plaintextApprovalToken = null;

    protected $fillable = [
        'uuid', 'app_key', 'app_name',
        'trigger_name', 'trigger_type', 'trigger_ref',
        'repository', 'commit_sha', 'commit_message', 'author',
        'strategy', 'release_name', 'release_path',
        'status', 'envoy_story',
        'approval_token_hash', 'approval_expires_at',
        'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'queued_at', 'started_at', 'completed_at',
        'output', 'exit_code', 'duration_seconds',
        'github_delivery_id',
        'payload', 'metadata',
    ];

    protected $casts = [
        'status' => DeploymentStatus::class,
        'strategy' => DeploymentStrategy::class,
        'trigger_type' => TriggerType::class,
        'approval_expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload' => 'array',
        'metadata' => 'array',
    ];

    // Status constants (deprecated, use DeploymentStatus enum directly)
    /** @deprecated Use DeploymentStatus::PendingApproval */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    /** @deprecated Use DeploymentStatus::Approved */
    public const STATUS_APPROVED = 'approved';

    /** @deprecated Use DeploymentStatus::Rejected */
    public const STATUS_REJECTED = 'rejected';

    /** @deprecated Use DeploymentStatus::Expired */
    public const STATUS_EXPIRED = 'expired';

    /** @deprecated Use DeploymentStatus::Queued */
    public const STATUS_QUEUED = 'queued';

    /** @deprecated Use DeploymentStatus::Running */
    public const STATUS_RUNNING = 'running';

    /** @deprecated Use DeploymentStatus::Success */
    public const STATUS_SUCCESS = 'success';

    /** @deprecated Use DeploymentStatus::Failed */
    public const STATUS_FAILED = 'failed';

    /**
     * Get the deployment database connection name.
     */
    public static function getDeploymentConnection(): ?string
    {
        $connection = config('continuous-delivery.database.connection');

        if ($connection === 'default') {
            return null;
        }

        if ($connection === 'sqlite') {
            return 'continuous-delivery';
        }

        return $connection;
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnectionName(): ?string
    {
        return static::getDeploymentConnection();
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
        });

        static::created(function (DeployerDeployment $deployment) {
            event(new DeploymentCreated($deployment));
        });
    }

    /**
     * Set the approval token (stores hash only, keeps plaintext transiently for URL generation).
     */
    public function setApprovalToken(string $token): self
    {
        $this->plaintextApprovalToken = $token;
        $this->approval_token_hash = hash('sha256', $token);

        return $this;
    }

    /**
     * Get the plaintext approval token (only available immediately after creation).
     */
    public function getPlaintextApprovalToken(): ?string
    {
        return $this->plaintextApprovalToken;
    }

    /**
     * Check if the plaintext token is available for URL generation.
     */
    public function hasPlaintextToken(): bool
    {
        return $this->plaintextApprovalToken !== null;
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
        return $this->status === DeploymentStatus::PendingApproval;
    }

    public function isApproved(): bool
    {
        return $this->status === DeploymentStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === DeploymentStatus::Rejected;
    }

    public function isExpired(): bool
    {
        return $this->status === DeploymentStatus::Expired;
    }

    public function isQueued(): bool
    {
        return $this->status === DeploymentStatus::Queued;
    }

    public function isRunning(): bool
    {
        return $this->status === DeploymentStatus::Running;
    }

    public function isSuccess(): bool
    {
        return $this->status === DeploymentStatus::Success;
    }

    public function isFailed(): bool
    {
        return $this->status === DeploymentStatus::Failed;
    }

    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isSimpleStrategy(): bool
    {
        return $this->strategy === DeploymentStrategy::Simple;
    }

    public function isAdvancedStrategy(): bool
    {
        return $this->strategy === DeploymentStrategy::Advanced;
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
            'status' => DeploymentStatus::Queued,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'queued_at' => now(),
        ]);

        event(new DeploymentApproved($this, $approvedBy));

        return $this;
    }

    public function reject(string $rejectedBy, ?string $reason = null): self
    {
        if (! $this->canBeRejected()) {
            throw new \RuntimeException('Deployment cannot be rejected');
        }

        $this->update([
            'status' => DeploymentStatus::Rejected,
            'rejected_by' => $rejectedBy,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        event(new DeploymentRejected($this, $rejectedBy, $reason));

        return $this;
    }

    public function expire(): self
    {
        if (! $this->isPendingApproval()) {
            return $this;
        }

        $this->update([
            'status' => DeploymentStatus::Expired,
        ]);

        return $this;
    }

    public function markQueued(): self
    {
        $this->update([
            'status' => DeploymentStatus::Queued,
            'queued_at' => now(),
        ]);

        return $this;
    }

    public function markRunning(): self
    {
        $this->update([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
        ]);

        event(new DeploymentStarted($this));

        return $this;
    }

    public function markSuccess(string $output, ?string $releaseName = null): self
    {
        $data = [
            'status' => DeploymentStatus::Success,
            'completed_at' => now(),
            'output' => $output,
            'exit_code' => 0,
            'duration_seconds' => $this->started_at?->diffInSeconds(now()),
        ];

        if ($releaseName) {
            $data['release_name'] = $releaseName;
        }

        $this->update($data);

        event(new DeploymentCompleted($this, success: true, releaseName: $releaseName));

        return $this;
    }

    public function markFailed(string $output, int $exitCode): self
    {
        $this->update([
            'status' => DeploymentStatus::Failed,
            'completed_at' => now(),
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_seconds' => $this->started_at?->diffInSeconds(now()),
        ]);

        event(new DeploymentFailedEvent($this, $output, $exitCode));
        event(new DeploymentCompleted($this, success: false));

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | URL Generation
    |--------------------------------------------------------------------------
    */

    public function getApproveUrl(): string
    {
        $token = $this->plaintextApprovalToken;

        if (! $token) {
            throw new \RuntimeException(
                'Approval URL can only be generated immediately after deployment creation. '.
                'The plaintext token is not stored for security reasons.'
            );
        }

        return \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.approve.confirm',
            ['token' => $token],
            $this->approval_expires_at
        );
    }

    public function getRejectUrl(): string
    {
        $token = $this->plaintextApprovalToken;

        if (! $token) {
            throw new \RuntimeException(
                'Rejection URL can only be generated immediately after deployment creation. '.
                'The plaintext token is not stored for security reasons.'
            );
        }

        return \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.reject.confirm',
            ['token' => $token],
            $this->approval_expires_at
        );
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
        return $query->where('status', DeploymentStatus::PendingApproval);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            DeploymentStatus::PendingApproval,
            DeploymentStatus::Approved,
            DeploymentStatus::Queued,
            DeploymentStatus::Running,
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
        return $query->where('status', DeploymentStatus::PendingApproval)
            ->where('approval_expires_at', '<', now());
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', DeploymentStatus::Success);
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
        ?string $githubDeliveryId = null,
    ): self {
        $requiresApproval = $app->requiresApproval($trigger);
        $approvalTimeoutHours = $app->getApprovalTimeout($trigger);

        $deployment = new self([
            'app_key' => $app->key,
            'app_name' => $app->name,
            'trigger_name' => $trigger['name'],
            'trigger_type' => TriggerType::tryFrom($triggerType) ?? $triggerType,
            'trigger_ref' => $triggerRef,
            'repository' => $payload['repository']['full_name'] ?? $app->repository,
            'commit_sha' => self::extractCommitSha($payload, $triggerType),
            'commit_message' => $payload['head_commit']['message'] ?? $payload['release']['body'] ?? null,
            'author' => $payload['sender']['login'] ?? 'unknown',
            'strategy' => DeploymentStrategy::tryFrom($app->strategy) ?? $app->strategy,
            'envoy_story' => $app->getEnvoyStory($trigger),
            'status' => $requiresApproval ? DeploymentStatus::PendingApproval : DeploymentStatus::Queued,
            'approval_expires_at' => $requiresApproval ? now()->addHours($approvalTimeoutHours) : null,
            'queued_at' => $requiresApproval ? null : now(),
            'github_delivery_id' => $githubDeliveryId,
            'payload' => $payload,
        ]);

        // Set approval token (stores hash, keeps plaintext transiently)
        if ($requiresApproval) {
            $deployment->setApprovalToken(Str::random(64));
        }

        $deployment->save();

        return $deployment;
    }

    /**
     * Find a deployment by its GitHub delivery ID.
     */
    public static function findByGithubDeliveryId(string $deliveryId): ?self
    {
        return static::where('github_delivery_id', $deliveryId)->first();
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
            'trigger_type' => TriggerType::Manual,
            'trigger_ref' => $ref,
            'repository' => $app->repository,
            'commit_sha' => $commitSha ?? 'HEAD',
            'author' => 'manual',
            'strategy' => DeploymentStrategy::tryFrom($app->strategy) ?? $app->strategy,
            'envoy_story' => $app->getEnvoyStory($trigger),
            'status' => DeploymentStatus::Queued,
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
            'trigger_type' => TriggerType::Rollback,
            'trigger_ref' => $targetRelease,
            'repository' => $app->repository,
            'commit_sha' => $commitSha ?? 'rollback',
            'author' => 'rollback',
            'strategy' => DeploymentStrategy::tryFrom($app->strategy) ?? $app->strategy,
            'envoy_story' => $story,
            'status' => DeploymentStatus::Queued,
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
        $tokenLength = config('continuous-delivery.approval.token_length', 64);

        if (strlen($token) !== $tokenLength) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        return static::where('approval_token_hash', $tokenHash)->first();
    }
}
