# Task 07: Create Envoy Template

**Phase:** 2 - Webhook & Envoy
**Priority:** P0
**Estimated Effort:** Medium
**Depends On:** 01

---

## Objective

Create the `Envoy.blade.php` template with staging and production stories that replace the bash scripts.

---

## File: `resources/Envoy.blade.php`

```blade
@servers(['localhost' => '127.0.0.1'])

@setup
    // Application directory (required)
    $appDir = env('CD_APP_DIR');
    if (empty($appDir)) {
        throw new Exception('CD_APP_DIR environment variable is required');
    }

    // Git reference (branch or tag) - passed from CLI
    $ref = $ref ?? env('CD_BRANCH', 'develop');

    // Notification settings (optional)
    $telegramBot = env('CD_TELEGRAM_BOT_ID');
    $telegramChat = env('CD_TELEGRAM_CHAT_ID');
    $slackWebhook = env('CD_SLACK_WEBHOOK');

    // PHP binary path (for systems with multiple PHP versions)
    $php = env('CD_PHP_PATH', 'php');

    // Composer binary path
    $composer = env('CD_COMPOSER_PATH', 'composer');
@endsetup

{{-- ============================================================
     STAGING STORY
     Fast incremental deploy for development/staging servers.
     Triggered by: Push to develop branch
     ============================================================ --}}
@story('staging')
    pull-code
    install-dependencies
    cache-config
    migrate
@endstory

{{-- ============================================================
     PRODUCTION STORY
     Full clean deploy with vendor refresh for production servers.
     Triggered by: Release tag (after approval)
     ============================================================ --}}
@story('production')
    maintenance-on
    pull-code
    clean-vendor
    install-dependencies
    clear-cache
    cache-config
    migrate
    storage-link
    maintenance-off
@endstory

{{-- ============================================================
     ROLLBACK STORY
     Quick rollback to previous commit.
     ============================================================ --}}
@story('rollback')
    maintenance-on
    rollback-code
    install-dependencies
    cache-config
    migrate-rollback
    maintenance-off
@endstory

{{-- ============================================================
     INDIVIDUAL TASKS
     ============================================================ --}}

@task('pull-code')
    echo "==> Pulling code (ref: {{ $ref }})"
    cd {{ $appDir }}
    git fetch origin --tags --prune
    git checkout {{ $ref }}
    git reset --hard {{ $ref }}
    echo "==> Current commit: $(git rev-parse --short HEAD)"
@endtask

@task('rollback-code')
    echo "==> Rolling back to previous commit"
    cd {{ $appDir }}
    git checkout HEAD~1
    echo "==> Rolled back to: $(git rev-parse --short HEAD)"
@endtask

@task('clean-vendor')
    echo "==> Cleaning vendor directory and caches"
    cd {{ $appDir }}
    rm -rf vendor
    rm -rf bootstrap/cache/*.php
@endtask

@task('install-dependencies')
    echo "==> Installing dependencies"
    cd {{ $appDir }}
    {{ $composer }} install --no-dev --optimize-autoloader --no-interaction --prefer-dist
@endtask

@task('clear-cache')
    echo "==> Clearing all caches"
    cd {{ $appDir }}
    {{ $php }} artisan optimize:clear
@endtask

@task('cache-config')
    echo "==> Caching configuration"
    cd {{ $appDir }}
    {{ $php }} artisan config:cache
    {{ $php }} artisan route:cache
    {{ $php }} artisan view:cache
    {{ $php }} artisan event:cache
@endtask

@task('migrate')
    echo "==> Running migrations"
    cd {{ $appDir }}
    {{ $php }} artisan migrate --force
@endtask

@task('migrate-rollback')
    echo "==> Rolling back last migration batch"
    cd {{ $appDir }}
    {{ $php }} artisan migrate:rollback --force
@endtask

@task('storage-link')
    echo "==> Creating storage symlink"
    cd {{ $appDir }}
    {{ $php }} artisan storage:link || true
@endtask

@task('maintenance-on')
    echo "==> Enabling maintenance mode"
    cd {{ $appDir }}
    {{ $php }} artisan down --retry=60 || true
@endtask

@task('maintenance-off')
    echo "==> Disabling maintenance mode"
    cd {{ $appDir }}
    {{ $php }} artisan up
@endtask

@task('queue-restart')
    echo "==> Restarting queue workers"
    cd {{ $appDir }}
    {{ $php }} artisan queue:restart
@endtask

{{-- ============================================================
     LIFECYCLE HOOKS
     ============================================================ --}}

@finished
    @if ($telegramBot && $telegramChat)
        @telegram($telegramBot, $telegramChat)
    @endif
@endfinished

@error
    @if ($slackWebhook)
        @slack($slackWebhook, '#alerts', 'Deployment FAILED!')
    @endif
@enderror
```

---

## Usage Examples

```bash
# Deploy staging (develop branch)
php vendor/bin/envoy run staging

# Deploy production (specific tag)
php vendor/bin/envoy run production --ref=v1.2.3

# Rollback
php vendor/bin/envoy run rollback

# Run single task
php vendor/bin/envoy run migrate
```

---

## Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `CD_APP_DIR` | Yes | - | Application root directory |
| `CD_BRANCH` | No | `develop` | Default branch for staging |
| `CD_PHP_PATH` | No | `php` | PHP binary path |
| `CD_COMPOSER_PATH` | No | `composer` | Composer binary path |
| `CD_TELEGRAM_BOT_ID` | No | - | Telegram bot ID |
| `CD_TELEGRAM_CHAT_ID` | No | - | Telegram chat ID |
| `CD_SLACK_WEBHOOK` | No | - | Slack webhook URL |

---

## Story Differences

| Feature | Staging | Production |
|---------|---------|------------|
| Maintenance mode | No | Yes |
| Clean vendor | No | Yes |
| Clear caches | No | Yes |
| Storage link | No | Yes |
| Speed | Fast | Slower, thorough |

---

## Acceptance Criteria

- [ ] `staging` story runs without errors on staging server
- [ ] `production` story runs without errors on production server
- [ ] `rollback` story successfully reverts to previous commit
- [ ] Telegram notifications work when configured
- [ ] Slack notifications work when configured
- [ ] Maintenance mode is properly enabled/disabled
- [ ] Template can be published to app root

---

## Notes

- Maintenance mode has `--retry=60` to tell clients to retry after 60 seconds
- `storage:link` uses `|| true` to prevent failure if link exists
- `event:cache` is included (Laravel 11+)
- PHP/Composer paths are configurable for systems with multiple versions
