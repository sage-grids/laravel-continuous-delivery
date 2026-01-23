# Task 11: Create CLI Commands

**Phase:** 3 - Approval Workflow
**Priority:** P1
**Estimated Effort:** Medium
**Depends On:** 04, 08

---

## Objective

Create Artisan commands for managing deployments via CLI as a fallback to web-based approval.

---

## Commands to Create

1. `deploy:pending` - List pending deployments
2. `deploy:approve` - Approve a deployment
3. `deploy:reject` - Reject a deployment
4. `deploy:status` - Check deployment status
5. `deploy:expire` - Expire old pending deployments (scheduled)

---

## File: `src/Console/PendingCommand.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\Deployment;

class PendingCommand extends Command
{
    protected $signature = 'deploy:pending
                            {--environment= : Filter by environment}
                            {--all : Show all active deployments, not just pending}';

    protected $description = 'List pending deployment approvals';

    public function handle(): int
    {
        $query = Deployment::query()
            ->orderBy('created_at', 'desc');

        if ($this->option('all')) {
            $query->active();
        } else {
            $query->pending();
        }

        if ($env = $this->option('environment')) {
            $query->forEnvironment($env);
        }

        $deployments = $query->take(20)->get();

        if ($deployments->isEmpty()) {
            $this->info('No pending deployments found.');
            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'Environment', 'Ref', 'Status', 'Expires', 'Created'],
            $deployments->map(fn ($d) => [
                substr($d->uuid, 0, 8) . '...',
                $d->environment,
                $d->trigger_ref,
                $d->status,
                $d->time_until_expiry ?? '-',
                $d->created_at->diffForHumans(),
            ])
        );

        $this->newLine();
        $this->line('To approve: <info>php artisan deploy:approve {uuid}</info>');
        $this->line('To reject:  <info>php artisan deploy:reject {uuid}</info>');

        return self::SUCCESS;
    }
}
```

---

## File: `src/Console/ApproveCommand.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApproved;

class ApproveCommand extends Command
{
    protected $signature = 'deploy:approve
                            {uuid : Deployment UUID (partial match supported)}
                            {--force : Skip confirmation}';

    protected $description = 'Approve a pending deployment';

    public function handle(): int
    {
        $deployment = $this->findDeployment($this->argument('uuid'));

        if (!$deployment) {
            $this->error('Deployment not found.');
            return self::FAILURE;
        }

        if (!$deployment->canBeApproved()) {
            $this->error("Cannot approve deployment. Status: {$deployment->status}");
            if ($deployment->hasExpired()) {
                $this->warn('This deployment has expired.');
            }
            return self::FAILURE;
        }

        // Show deployment info
        $this->showDeploymentInfo($deployment);

        // Confirm
        if (!$this->option('force')) {
            if (!$this->confirm('Approve this deployment?')) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        // Approve
        $approvedBy = "cli:" . (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown');
        $deployment->approve($approvedBy);

        Log::info('[continuous-delivery] Deployment approved via CLI', [
            'uuid' => $deployment->uuid,
            'approved_by' => $approvedBy,
        ]);

        // Dispatch job
        dispatch(new RunDeployJob($deployment));

        // Notify
        try {
            $deployment->notify(new DeploymentApproved($deployment));
        } catch (\Throwable $e) {
            $this->warn("Notification failed: {$e->getMessage()}");
        }

        $this->info('Deployment approved and queued.');
        $this->line("UUID: {$deployment->uuid}");

        return self::SUCCESS;
    }

    protected function findDeployment(string $uuid): ?Deployment
    {
        // Try exact match first
        $deployment = Deployment::where('uuid', $uuid)->first();

        if ($deployment) {
            return $deployment;
        }

        // Try partial match (start of UUID)
        return Deployment::where('uuid', 'like', $uuid . '%')
            ->pending()
            ->first();
    }

    protected function showDeploymentInfo(Deployment $deployment): void
    {
        $this->newLine();
        $this->line("Environment: <info>{$deployment->environment}</info>");
        $this->line("Reference:   <info>{$deployment->trigger_ref}</info>");
        $this->line("Commit:      <info>{$deployment->short_commit_sha}</info>");
        $this->line("Author:      <info>{$deployment->author}</info>");
        $this->line("Created:     <info>{$deployment->created_at->diffForHumans()}</info>");
        if ($deployment->commit_message) {
            $this->line("Message:     {$deployment->commit_message}");
        }
        $this->newLine();
    }
}
```

---

## File: `src/Console/RejectCommand.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentRejected;

class RejectCommand extends Command
{
    protected $signature = 'deploy:reject
                            {uuid : Deployment UUID (partial match supported)}
                            {--reason= : Rejection reason}
                            {--force : Skip confirmation}';

    protected $description = 'Reject a pending deployment';

    public function handle(): int
    {
        $deployment = $this->findDeployment($this->argument('uuid'));

        if (!$deployment) {
            $this->error('Deployment not found.');
            return self::FAILURE;
        }

        if (!$deployment->canBeRejected()) {
            $this->error("Cannot reject deployment. Status: {$deployment->status}");
            return self::FAILURE;
        }

        // Show deployment info
        $this->line("Environment: <info>{$deployment->environment}</info>");
        $this->line("Reference:   <info>{$deployment->trigger_ref}</info>");
        $this->newLine();

        // Get reason
        $reason = $this->option('reason');
        if (!$reason && !$this->option('force')) {
            $reason = $this->ask('Rejection reason (optional)');
        }

        // Confirm
        if (!$this->option('force')) {
            if (!$this->confirm('Reject this deployment?')) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        // Reject
        $rejectedBy = "cli:" . (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown');
        $deployment->reject($rejectedBy, $reason);

        Log::info('[continuous-delivery] Deployment rejected via CLI', [
            'uuid' => $deployment->uuid,
            'rejected_by' => $rejectedBy,
            'reason' => $reason,
        ]);

        // Notify
        try {
            $deployment->notify(new DeploymentRejected($deployment));
        } catch (\Throwable $e) {
            $this->warn("Notification failed: {$e->getMessage()}");
        }

        $this->info('Deployment rejected.');

        return self::SUCCESS;
    }

    protected function findDeployment(string $uuid): ?Deployment
    {
        $deployment = Deployment::where('uuid', $uuid)->first();

        if ($deployment) {
            return $deployment;
        }

        return Deployment::where('uuid', 'like', $uuid . '%')
            ->pending()
            ->first();
    }
}
```

---

## File: `src/Console/StatusCommand.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Models\Deployment;

class StatusCommand extends Command
{
    protected $signature = 'deploy:status
                            {uuid : Deployment UUID}
                            {--output : Show deployment output}';

    protected $description = 'Check deployment status';

    public function handle(): int
    {
        $deployment = Deployment::where('uuid', $this->argument('uuid'))
            ->orWhere('uuid', 'like', $this->argument('uuid') . '%')
            ->first();

        if (!$deployment) {
            $this->error('Deployment not found.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("UUID:        <info>{$deployment->uuid}</info>");
        $this->line("Environment: <info>{$deployment->environment}</info>");
        $this->line("Reference:   <info>{$deployment->trigger_ref}</info>");
        $this->line("Commit:      <info>{$deployment->short_commit_sha}</info>");
        $this->line("Status:      <info>{$deployment->status}</info>");
        $this->line("Created:     <info>{$deployment->created_at}</info>");

        if ($deployment->approved_at) {
            $this->line("Approved:    <info>{$deployment->approved_at}</info> by {$deployment->approved_by}");
        }

        if ($deployment->rejected_at) {
            $this->line("Rejected:    <comment>{$deployment->rejected_at}</comment> by {$deployment->rejected_by}");
            if ($deployment->rejection_reason) {
                $this->line("Reason:      {$deployment->rejection_reason}");
            }
        }

        if ($deployment->started_at) {
            $this->line("Started:     <info>{$deployment->started_at}</info>");
        }

        if ($deployment->completed_at) {
            $this->line("Completed:   <info>{$deployment->completed_at}</info>");
            $this->line("Duration:    <info>{$deployment->duration_for_humans}</info>");
            $this->line("Exit Code:   " . ($deployment->exit_code === 0 ? '<info>0</info>' : "<error>{$deployment->exit_code}</error>"));
        }

        if ($this->option('output') && $deployment->output) {
            $this->newLine();
            $this->line('<comment>--- Output ---</comment>');
            $this->line($deployment->output);
        }

        return self::SUCCESS;
    }
}
```

---

## File: `src/Console/ExpireCommand.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentExpired;

class ExpireCommand extends Command
{
    protected $signature = 'deploy:expire
                            {--dry-run : Show what would be expired without making changes}';

    protected $description = 'Expire pending deployments that have passed their timeout';

    public function handle(): int
    {
        $expired = Deployment::expired()->get();

        if ($expired->isEmpty()) {
            $this->info('No expired deployments found.');
            return self::SUCCESS;
        }

        $this->info("Found {$expired->count()} expired deployment(s).");

        foreach ($expired as $deployment) {
            $this->line("- {$deployment->uuid} ({$deployment->trigger_ref})");

            if ($this->option('dry-run')) {
                continue;
            }

            $deployment->expire();

            Log::info('[continuous-delivery] Deployment expired', [
                'uuid' => $deployment->uuid,
            ]);

            // Notify
            if (config('continuous-delivery.approval.notify_on_expire', true)) {
                try {
                    $deployment->notify(new DeploymentExpired($deployment));
                } catch (\Throwable $e) {
                    $this->warn("Notification failed for {$deployment->uuid}");
                }
            }
        }

        if (!$this->option('dry-run')) {
            $this->info('Deployments expired successfully.');
        }

        return self::SUCCESS;
    }
}
```

---

## Scheduler Integration

Add to `routes/console.php` or `app/Console/Kernel.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('deploy:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

---

## Acceptance Criteria

- [ ] `deploy:pending` lists pending deployments with useful info
- [ ] `deploy:approve` approves and dispatches deployment
- [ ] `deploy:reject` rejects with optional reason
- [ ] `deploy:status` shows full deployment details
- [ ] `deploy:expire` handles expired deployments
- [ ] Partial UUID matching works
- [ ] CLI user is recorded as approver
- [ ] All commands have helpful output

---

## Notes

- Partial UUID matching allows `php artisan deploy:approve abc123` instead of full UUID
- CLI approver is recorded as `cli:username` for audit trail
- `--force` flag bypasses all confirmations
