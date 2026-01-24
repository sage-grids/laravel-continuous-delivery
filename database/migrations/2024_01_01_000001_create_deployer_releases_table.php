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
        Schema::connection($this->getConnection())->create('deployer_releases', function (Blueprint $table) {
            $table->id();
            $table->string('app_key', 50);
            $table->string('name', 50);              // '20240115_120000_abc1234'
            $table->string('path', 500);
            $table->string('commit_sha', 40);
            $table->string('trigger_ref');
            $table->foreignId('deployment_id')
                ->constrained('deployer_deployments')
                ->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->bigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->unique(['app_key', 'name']);
            $table->index(['app_key', 'is_active']);
            $table->index(['app_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('deployer_releases');
    }
};
