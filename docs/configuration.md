# Configuration Reference

Complete configuration guide for sage-grids/continuous-delivery.

## Publishing Configuration

```bash
php artisan vendor:publish --tag=continuous-delivery-config
```

This creates `config/continuous-delivery.php` in your application.

---

## Environment Variables

### Required

| Variable | Description |
|----------|-------------|
| `GITHUB_WEBHOOK_SECRET` | Shared secret for webhook signature verification |
| `CD_APP_DIR` | Absolute path to application root directory |

### Staging Environment

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_STAGING_ENABLED` | `true` | Enable staging deployments |
| `CD_STAGING_BRANCH` | `develop` | Branch that triggers staging deploys |

### Production Environment

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_PRODUCTION_ENABLED` | `false` | Enable production deployments |
| `CD_PRODUCTION_TAG_PATTERN` | `/^v\d+\.\d+\.\d+$/` | Regex pattern for valid release tags |
| `CD_PRODUCTION_APPROVAL` | `true` | Require human approval before deploy |
| `CD_PRODUCTION_APPROVAL_TIMEOUT` | `2` | Hours before approval request expires |

### Notifications

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_TELEGRAM_ENABLED` | `false` | Enable Telegram notifications |
| `CD_TELEGRAM_BOT_ID` | - | Telegram bot ID from BotFather |
| `CD_TELEGRAM_CHAT_ID` | - | Telegram chat/group ID |
| `CD_SLACK_ENABLED` | `false` | Enable Slack notifications |
| `CD_SLACK_WEBHOOK` | - | Slack incoming webhook URL |
| `CD_SLACK_CHANNEL` | `#deploys` | Slack channel for notifications |

### Storage

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_DATABASE_PATH` | `/var/lib/sage-grids-cd/deployments.sqlite` | Isolated SQLite database path |

### Queue

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_QUEUE_CONNECTION` | (default) | Laravel queue connection name |
| `CD_QUEUE_NAME` | (default) | Queue name for deployment jobs |

### Envoy

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_ENVOY_PATH` | `Envoy.blade.php` | Path to Envoy file |
| `CD_ENVOY_TIMEOUT` | `1800` | Max execution time in seconds (30 min) |
| `CD_PHP_PATH` | `php` | PHP binary path |
| `CD_COMPOSER_PATH` | `composer` | Composer binary path |

---

## Example Configurations

### Staging Server

```env
# Required
GITHUB_WEBHOOK_SECRET=your-32-char-random-secret
CD_APP_DIR=/home/staging.example.com/app/current

# Storage
CD_DATABASE_PATH=/var/lib/sage-grids-cd/deployments.sqlite

# Staging only
CD_STAGING_ENABLED=true
CD_STAGING_BRANCH=develop
CD_PRODUCTION_ENABLED=false

# Notifications
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_ID=123456789
CD_TELEGRAM_CHAT_ID=-100123456789
```

### Production Server

```env
# Required
GITHUB_WEBHOOK_SECRET=your-32-char-random-secret
CD_APP_DIR=/home/example.com/app/current

# Storage
CD_DATABASE_PATH=/var/lib/sage-grids-cd/deployments.sqlite

# Production only
CD_STAGING_ENABLED=false
CD_PRODUCTION_ENABLED=true
CD_PRODUCTION_APPROVAL=true
CD_PRODUCTION_APPROVAL_TIMEOUT=2

# Notifications (both channels)
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_ID=123456789
CD_TELEGRAM_CHAT_ID=-100123456789

CD_SLACK_ENABLED=true
CD_SLACK_WEBHOOK=https://hooks.slack.com/services/xxx/yyy/zzz
CD_SLACK_CHANNEL=#production-deploys
```

---

## Custom Environments

Add custom environments in `config/continuous-delivery.php`:

```php
'environments' => [
    'staging' => [...],
    'production' => [...],

    // QA environment - branch-triggered, no approval
    'qa' => [
        'enabled' => env('CD_QA_ENABLED', false),
        'trigger' => 'branch',
        'branch' => env('CD_QA_BRANCH', 'qa'),
        'approval_required' => false,
        'envoy_story' => 'staging',
    ],

    // Beta environment - tag-triggered with approval
    'beta' => [
        'enabled' => env('CD_BETA_ENABLED', false),
        'trigger' => 'release',
        'tag_pattern' => '/^v\d+\.\d+\.\d+-beta\.\d+$/',
        'approval_required' => true,
        'approval_timeout_hours' => 4,
        'envoy_story' => 'production',
    ],
],
```

---

## Isolated Database

The package uses a separate SQLite database to store deployment records. This ensures:

- Deployment history survives `migrate:fresh` on staging
- No pollution of your main application database
- Easy backup and management

### Default Location

```
/var/lib/sage-grids-cd/deployments.sqlite
```

### Custom Location

```env
CD_DATABASE_PATH=/custom/path/to/deployments.sqlite
```

### Using Main Database (Not Recommended)

Set to empty to use your main database connection:

```env
CD_DATABASE_PATH=
```

**Warning:** Deployment records will be lost on `migrate:fresh`.
