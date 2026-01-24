<?php

namespace SageGrids\ContinuousDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeployerRelease extends Model
{
    protected $table = 'deployer_releases';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'size_bytes' => 'integer',
    ];

    /**
     * Use isolated database connection if configured.
     */
    public function getConnectionName(): ?string
    {
        return DeployerDeployment::getDeploymentConnection();
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DeployerDeployment::class, 'deployment_id');
    }

    /**
     * Scope to get releases for a specific app.
     */
    public function scopeForApp($query, string $appKey)
    {
        return $query->where('app_key', $appKey);
    }

    /**
     * Scope to get active release.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the previous release before this one.
     */
    public function getPreviousRelease(): ?self
    {
        return static::where('app_key', $this->app_key)
            ->where('created_at', '<', $this->created_at)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Mark this release as active.
     */
    public function activate(): self
    {
        // Deactivate all other releases for this app
        static::where('app_key', $this->app_key)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->update(['is_active' => true]);

        return $this;
    }

    /**
     * Deactivate this release.
     */
    public function deactivate(): self
    {
        $this->update(['is_active' => false]);

        return $this;
    }

    /**
     * Get the short commit SHA.
     */
    public function getShortCommitShaAttribute(): string
    {
        return substr($this->commit_sha, 0, 7);
    }

    /**
     * Format size in human readable format.
     */
    public function getSizeForHumansAttribute(): ?string
    {
        if (! $this->size_bytes) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size_bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2).' '.$units[$unitIndex];
    }
}
