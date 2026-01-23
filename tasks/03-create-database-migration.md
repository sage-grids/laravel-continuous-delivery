# Task 03: Create Database Migration

**Phase:** 1 - Foundation
**Priority:** P0
**Estimated Effort:** Medium
**Depends On:** 02

---

## Objective

Create the `deployments` table migration that uses an isolated SQLite database to survive application database refreshes.

---

## File: `database/migrations/2024_01_01_000000_create_deployments_table.php`

```php
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
            $table->string('environment', 50);      // staging, production
            $table->string('trigger_type', 50);     // branch_push, release
            $table->string('trigger_ref', 255);     // develop, v1.2.3

            // Git info
            $table->string('commit_sha', 40);
            $table->text('commit_message')->nullable();
            $table->string('author', 255)->nullable();
            $table->string('repository', 255)->nullable();

            // Deployment config
            $table->string('mode', 50)->default('simple');  // simple, fresh
            $table->string('envoy_story', 50);

            // Status workflow
            $table->string('status', 50)->default('pending_approval');
            // Values: pending_approval, approved, rejected, expired, queued, running, success, failed

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
            $table->json('payload')->nullable();  // Original webhook payload
            $table->json('metadata')->nullable(); // Additional context

            $table->timestamps();

            // Indexes for common queries
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
```

---

## Schema Details

### Status Values

| Status | Description |
|--------|-------------|
| `pending_approval` | Awaiting human approval (production) |
| `approved` | Approved, waiting to be queued |
| `rejected` | Rejected by user |
| `expired` | Approval timeout reached |
| `queued` | Queued for execution |
| `running` | Currently executing |
| `success` | Completed successfully |
| `failed` | Execution failed |

### Key Columns

| Column | Purpose |
|--------|---------|
| `uuid` | Public identifier (used in URLs) |
| `approval_token` | 64-char token for signed URL approval |
| `payload` | Full GitHub webhook payload (for debugging) |
| `output` | Envoy execution output (stdout + stderr) |
| `duration_seconds` | Calculated on completion |

---

## Migration Execution

The migration runs on the isolated SQLite connection:

```php
// In ServiceProvider or InstallCommand
Artisan::call('migrate', [
    '--database' => 'continuous-delivery',
    '--path' => 'vendor/sage-grids/continuous-delivery/database/migrations',
    '--force' => true,
]);
```

---

## Acceptance Criteria

- [ ] Migration creates table on isolated SQLite connection
- [ ] All indexes are properly created
- [ ] Migration is reversible (down method works)
- [ ] Works with both SQLite and MySQL (if main DB used)

---

## Notes

- `longText` for output since deploy logs can be large
- `json` columns for payload/metadata to store flexible data
- UUID is used for public-facing URLs (not auto-increment ID)
- Token is indexed for fast approval lookups
