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
    $telegramBot = env('CD_TELEGRAM_BOT_TOKEN');
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
    {{ $php }} artisan event:cache || true
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
