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
        $dbPath = config('continuous-delivery.storage.database');

        return $dbPath ? 'continuous-delivery' : null;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create('deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Environment & trigger info
            $table->string('environment', 50);
            $table->string('trigger_type', 50);
            $table->string('trigger_ref', 255);

            // Git info
            $table->string('commit_sha', 40);
            $table->text('commit_message')->nullable();
            $table->string('author', 255)->nullable();
            $table->string('repository', 255)->nullable();

            // Deployment config
            $table->string('mode', 50)->default('simple');
            $table->string('envoy_story', 50);

            // Status workflow
            $table->string('status', 50)->default('pending_approval');

            // Approval workflow
            $table->string('approval_token', 64)->nullable()->index();
            $table->timestamp('approval_expires_at')->nullable();
            $table->string('approved_by', 255)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_by', 255)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Execution tracking
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Metadata
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['environment', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('deployments');
    }
};
