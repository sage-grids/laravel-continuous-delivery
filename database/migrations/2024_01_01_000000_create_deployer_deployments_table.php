<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the database connection for this migration.
     */
    public function getConnection(): ?string
    {
        $connection = config('continuous-delivery.database.connection');

        return $connection === 'sqlite' ? 'continuous-delivery' : null;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create('deployer_deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // App identification
            $table->string('app_key', 50)->default('default');
            $table->string('app_name');

            // Trigger info
            $table->string('trigger_name', 50);      // 'staging', 'production'
            $table->string('trigger_type', 50);      // 'push', 'release', 'manual', 'rollback'
            $table->string('trigger_ref');           // 'develop', 'v1.2.3', etc.

            // Git info
            $table->string('repository')->nullable();
            $table->string('commit_sha', 40);
            $table->text('commit_message')->nullable();
            $table->string('author')->nullable();

            // Strategy info
            $table->string('strategy', 20);          // 'simple' or 'advanced'
            $table->string('release_name', 50)->nullable();  // For advanced: '20240115_120000_abc1234'
            $table->string('release_path', 500)->nullable();

            // Status tracking
            $table->string('status', 30);
            $table->string('envoy_story', 50);

            // Approval workflow (only hash is stored for security)
            $table->string('approval_token_hash', 64)->nullable()->index();
            $table->timestamp('approval_expires_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Execution tracking
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Webhook idempotency
            $table->string('github_delivery_id', 64)->nullable()->unique();

            // Metadata
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['app_key', 'status']);
            $table->index(['app_key', 'created_at']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('deployer_deployments');
    }
};
