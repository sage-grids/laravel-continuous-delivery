# Task 15: Create Documentation

**Phase:** 5 - Documentation & Cleanup
**Priority:** P2
**Estimated Effort:** Medium
**Depends On:** All previous tasks

---

## Objective

Create detailed documentation files under `docs/` for configuration, notifications, approval workflow, and server setup.

---

## Files to Create

1. `docs/configuration.md` - Complete configuration reference
2. `docs/notifications.md` - Notification setup guide
3. `docs/approval-workflow.md` - Production approval process
4. `docs/server-setup.md` - Server deployment guide

---

## File: `docs/configuration.md`

```markdown
# Configuration Reference

## Publishing Configuration

```bash
php artisan vendor:publish --tag=continuous-delivery-config
```

This creates `config/continuous-delivery.php`.

---

## Complete Configuration

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Route
    |--------------------------------------------------------------------------
    */
    'route' => [
        'path' => env('CD_WEBHOOK_PATH', '/deploy/github'),
        'middleware' => ['api', 'throttle:10,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Settings
    |--------------------------------------------------------------------------
    */
    'github' => [
        // Webhook secret for signature verification (required)
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET', ''),

        // Lock to specific repository (optional)
        'only_repo_full_name' => env('GITHUB_REPO_FULL_NAME', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Deployment records use an isolated SQLite database that survives
    | application database refreshes (e.g., migrate:fresh on staging).
    |
    */
    'storage' => [
        'database' => env('CD_DATABASE_PATH', '/var/lib/sage-grids-cd/deployments.sqlite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    */
    'environments' => [
        'staging' => [
            'enabled' => env('CD_STAGING_ENABLED', true),
            'trigger' => 'branch',
            'branch' => env('CD_STAGING_BRANCH', 'develop'),
            'approval_required' => false,
            'envoy_story' => 'staging',
        ],
        'production' => [
            'enabled' => env('CD_PRODUCTION_ENABLED', false),
            'trigger' => 'release',
            'tag_pattern' => env('CD_PRODUCTION_TAG_PATTERN', '/^v\d+\.\d+\.\d+$/'),
            'approval_required' => env('CD_PRODUCTION_APPROVAL', true),
            'approval_timeout_hours' => env('CD_PRODUCTION_APPROVAL_TIMEOUT', 2),
            'envoy_story' => 'production',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'telegram' => [
            'enabled' => env('CD_TELEGRAM_ENABLED', false),
            'bot_id' => env('CD_TELEGRAM_BOT_ID'),
            'chat_id' => env('CD_TELEGRAM_CHAT_ID'),
        ],
        'slack' => [
            'enabled' => env('CD_SLACK_ENABLED', false),
            'webhook_url' => env('CD_SLACK_WEBHOOK'),
            'channel' => env('CD_SLACK_CHANNEL', '#deploys'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('CD_QUEUE_CONNECTION'),
        'queue' => env('CD_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval Settings
    |--------------------------------------------------------------------------
    */
    'approval' => [
        'token_length' => 64,
        'auto_expire' => env('CD_AUTO_EXPIRE', true),
        'notify_on_expire' => env('CD_NOTIFY_ON_EXPIRE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Envoy
    |--------------------------------------------------------------------------
    */
    'envoy' => [
        'path' => env('CD_ENVOY_PATH', base_path('Envoy.blade.php')),
        'timeout' => env('CD_ENVOY_TIMEOUT', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Directory
    |--------------------------------------------------------------------------
    */
    'app_dir' => env('CD_APP_DIR', base_path()),
];
```

---

## Environment Variables Quick Reference

### Required

| Variable | Description |
|----------|-------------|
| `GITHUB_WEBHOOK_SECRET` | Shared secret for webhook verification |
| `CD_APP_DIR` | Absolute path to application root |

### Staging

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_STAGING_ENABLED` | `true` | Enable staging deployments |
| `CD_STAGING_BRANCH` | `develop` | Branch that triggers staging |

### Production

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_PRODUCTION_ENABLED` | `false` | Enable production deployments |
| `CD_PRODUCTION_TAG_PATTERN` | `/^v\d+\.\d+\.\d+$/` | Regex for valid tags |
| `CD_PRODUCTION_APPROVAL` | `true` | Require human approval |
| `CD_PRODUCTION_APPROVAL_TIMEOUT` | `2` | Hours before approval expires |

### Notifications

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_TELEGRAM_ENABLED` | `false` | Enable Telegram notifications |
| `CD_TELEGRAM_BOT_ID` | - | Telegram bot ID |
| `CD_TELEGRAM_CHAT_ID` | - | Telegram chat/group ID |
| `CD_SLACK_ENABLED` | `false` | Enable Slack notifications |
| `CD_SLACK_WEBHOOK` | - | Slack incoming webhook URL |
| `CD_SLACK_CHANNEL` | `#deploys` | Slack channel |

### Advanced

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_DATABASE_PATH` | `/var/lib/sage-grids-cd/deployments.sqlite` | Isolated database path |
| `CD_QUEUE_CONNECTION` | default | Queue connection name |
| `CD_QUEUE_NAME` | default | Queue name |
| `CD_ENVOY_PATH` | `Envoy.blade.php` | Custom Envoy file path |
| `CD_ENVOY_TIMEOUT` | `1800` | Envoy execution timeout (seconds) |
| `CD_PHP_PATH` | `php` | PHP binary path for Envoy |
| `CD_COMPOSER_PATH` | `composer` | Composer binary path |

---

## Custom Environments

You can add custom environments in the config:

```php
'environments' => [
    'staging' => [...],
    'production' => [...],

    // Custom environment
    'qa' => [
        'enabled' => env('CD_QA_ENABLED', false),
        'trigger' => 'branch',
        'branch' => env('CD_QA_BRANCH', 'qa'),
        'approval_required' => false,
        'envoy_story' => 'staging', // Can reuse existing stories
    ],
],
```
```

---

## File: `docs/notifications.md`

See detailed content in implementation.

---

## File: `docs/approval-workflow.md`

See detailed content in implementation.

---

## File: `docs/server-setup.md`

See detailed content in implementation.

---

## Acceptance Criteria

- [ ] Configuration reference is complete
- [ ] All environment variables documented
- [ ] Examples are accurate and tested
- [ ] Cross-references between docs
- [ ] Navigation structure is clear

---

## Notes

- Keep docs DRY - reference README where appropriate
- Include real-world examples
- Add troubleshooting tips
