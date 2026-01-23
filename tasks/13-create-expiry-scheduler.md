# Task 13: Create Expiry Scheduler

**Phase:** 4 - Notifications
**Priority:** P1
**Estimated Effort:** Small
**Depends On:** 11

---

## Objective

Set up scheduled task to automatically expire pending deployments that have passed their timeout.

---

## Implementation

The `ExpireCommand` created in Task 11 handles the actual expiry logic. This task covers the scheduler integration.

---

## Option 1: Package-Level Scheduler (Recommended)

### File: `src/ContinuousDeliveryServiceProvider.php`

Add to the `boot()` method:

```php
protected function registerScheduler(): void
{
    if (!$this->app->runningInConsole()) {
        return;
    }

    $this->app->booted(function () {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        if (config('continuous-delivery.approval.auto_expire', true)) {
            $schedule->command('deploy:expire')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        }
    });
}
```

Call this method in `boot()`:

```php
public function boot(): void
{
    $this->registerPublishables();
    $this->registerRoutes();
    $this->registerMigrations();
    $this->registerCommands();
    $this->registerViews();
    $this->registerScheduler(); // Add this
}
```

---

## Option 2: Application-Level Scheduler

If users prefer to manage the schedule themselves, document in README:

### `routes/console.php` (Laravel 11+)

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('deploy:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

### `app/Console/Kernel.php` (Laravel 10)

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('deploy:expire')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}
```

---

## Configuration

Add to `config/continuous-delivery.php`:

```php
'approval' => [
    'token_length' => 64,
    'auto_expire' => env('CD_AUTO_EXPIRE', true),
    'notify_on_expire' => env('CD_NOTIFY_ON_EXPIRE', true),
],
```

---

## How It Works

```
Every 5 minutes:
  └─ deploy:expire runs
     └─ Finds deployments where:
        - status = pending_approval
        - approval_expires_at < now()
     └─ For each expired deployment:
        - Update status to 'expired'
        - Send DeploymentExpired notification
        - Log the expiry
```

---

## Testing

```bash
# Dry run to see what would expire
php artisan deploy:expire --dry-run

# Force run the scheduler
php artisan schedule:run

# Check scheduler is registered
php artisan schedule:list
```

---

## Acceptance Criteria

- [ ] Scheduler runs every 5 minutes
- [ ] Expired deployments are updated
- [ ] Notifications are sent when configured
- [ ] `--dry-run` shows what would be expired
- [ ] `withoutOverlapping` prevents duplicate runs

---

## Notes

- Package registers scheduler automatically if `auto_expire` is enabled
- Users can disable and manage manually via config
- 5 minutes is a balance between responsiveness and performance
