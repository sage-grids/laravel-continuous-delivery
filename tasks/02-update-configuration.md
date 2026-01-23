# Task 02: Update Configuration

**Phase:** 1 - Foundation
**Priority:** P0
**Estimated Effort:** Medium
**Depends On:** 01

---

## Objective

Restructure `config/continuous-delivery.php` to support multi-environment deployments with staging (auto-deploy) and production (approval required).

---

## File: `config/continuous-delivery.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Route Configuration
    |--------------------------------------------------------------------------
    */
    'route' => [
        'path' => env('CD_WEBHOOK_PATH', '/deploy/github'),
        'middleware' => ['api', 'throttle:10,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Integration
    |--------------------------------------------------------------------------
    */
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET', ''),
        'only_repo_full_name' => env('GITHUB_REPO_FULL_NAME', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Isolated Storage (SQLite)
    |--------------------------------------------------------------------------
    |
    | Deployment records are stored in a separate SQLite database to survive
    | application database refreshes (e.g., migrate:fresh on staging).
    |
    */
    'storage' => [
        'database' => env('CD_DATABASE_PATH', '/var/lib/sage-grids-cd/deployments.sqlite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Configuration
    |--------------------------------------------------------------------------
    |
    | Define deployment environments with their triggers and approval settings.
    |
    | Triggers:
    |   - 'branch': Deploy on push to specified branch
    |   - 'release': Deploy on GitHub release (tag) creation
    |
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
    | Notification Channels
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
    | Queue Configuration
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
        'auto_expire' => true,
        'notify_on_expire' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Envoy Configuration
    |--------------------------------------------------------------------------
    */
    'envoy' => [
        'path' => env('CD_ENVOY_PATH', base_path('Envoy.blade.php')),
        'timeout' => env('CD_ENVOY_TIMEOUT', 1800), // 30 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Directory
    |--------------------------------------------------------------------------
    |
    | The directory where the application is deployed. Used by Envoy tasks.
    |
    */
    'app_dir' => env('CD_APP_DIR', base_path()),
];
```

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_DATABASE_PATH` | `/var/lib/sage-grids-cd/deployments.sqlite` | Isolated SQLite path |
| `CD_STAGING_ENABLED` | `true` | Enable staging deployments |
| `CD_STAGING_BRANCH` | `develop` | Branch to trigger staging |
| `CD_PRODUCTION_ENABLED` | `false` | Enable production deployments |
| `CD_PRODUCTION_TAG_PATTERN` | `/^v\d+\.\d+\.\d+$/` | Semver tag pattern |
| `CD_PRODUCTION_APPROVAL` | `true` | Require approval for production |
| `CD_PRODUCTION_APPROVAL_TIMEOUT` | `2` | Hours before approval expires |
| `CD_TELEGRAM_ENABLED` | `false` | Enable Telegram notifications |
| `CD_TELEGRAM_BOT_ID` | - | Telegram bot ID |
| `CD_TELEGRAM_CHAT_ID` | - | Telegram chat ID |
| `CD_SLACK_ENABLED` | `false` | Enable Slack notifications |
| `CD_SLACK_WEBHOOK` | - | Slack webhook URL |
| `CD_SLACK_CHANNEL` | `#deploys` | Slack channel |
| `CD_APP_DIR` | `base_path()` | Application directory |

---

## Acceptance Criteria

- [ ] All settings are configurable via `.env`
- [ ] Sensible defaults for local development
- [ ] Production disabled by default (safety)
- [ ] Config can be published with `vendor:publish`

---

## Notes

- Production is disabled by default to prevent accidental deployments
- The `tag_pattern` uses regex to match semver tags only
- Approval timeout is in hours (not minutes) for flexibility
