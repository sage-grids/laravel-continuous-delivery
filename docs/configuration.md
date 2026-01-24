# Configuration Reference

Complete configuration guide for sage-grids/laravel-continuous-delivery.

## Publishing Configuration

```bash
php artisan vendor:publish --tag=continuous-delivery-config
```

This creates `config/continuous-delivery.php` in your application.

---

## App Configuration

The core of the package is the `apps` array. Each app defines:

```php
'apps' => [
    'default' => [
        // Display name
        'name' => 'My Application',

        // GitHub repository (owner/repo)
        'repository' => 'company/my-app',

        // Deployment path
        'path' => '/var/www/my-app',

        // Deployment strategy: 'simple' or 'advanced'
        'strategy' => 'simple',

        // Strategy-specific settings
        'simple' => [
            // No additional config needed for simple mode
        ],
        
        'advanced' => [
            'keep_releases' => 5,
        ],

        // Deployment triggers
        'triggers' => [
            [
                'name' => 'staging',
                'on' => 'push',
                'branch' => 'develop',
                'auto_deploy' => true,
                'story' => 'staging',
            ],
            [
                'name' => 'production',
                'on' => 'release',
                'tag_pattern' => '/^v\d+\.\d+\.\d+$/',
                'auto_deploy' => false,
                'approval_timeout' => 2,
                'story' => 'production',
            ],
        ],

        // Per-app notifications (optional, overrides global)
        'notifications' => [
            'telegram' => [
                'enabled' => true,
                'chat_id' => '-100123456789',
            ],
        ],
    ],
],
```

---

## Deployment Strategies

### Simple Strategy

In-place git deployment:

```php
'strategy' => 'simple',
```

Deployment flow:
1. `git pull origin {ref}`
2. `composer install`
3. `php artisan migrate`
4. `php artisan cache:clear`

### Advanced Strategy

Release-based deployment with symlinks:

```php
'strategy' => 'advanced',
'advanced' => [
    'keep_releases' => 5,      // Number of releases to keep
    'shared_dirs' => [
        'storage',              // Shared directories
    ],
    'shared_files' => [
        '.env',                 // Shared files
    ],
],
```

Directory structure:
```
/var/www/my-app/
├── releases/
│   ├── 20240115120000/
│   └── 20240116140000/
├── shared/
│   ├── storage/
│   └── .env
└── current -> releases/20240116140000
```

---

## Trigger Configuration

### Branch Push Trigger

```php
[
    'name' => 'staging',
    'on' => 'push',
    'branch' => 'develop',           // Branch name
    'auto_deploy' => true,
    'story' => 'staging',            // Envoy story to run
],
```

### Release Trigger

```php
[
    'name' => 'production',
    'on' => 'release',
    'tag_pattern' => '/^v\d+\.\d+\.\d+$/',  // Regex for valid tags
    'auto_deploy' => false,
    'approval_timeout' => 2,
    'story' => 'production',
],
```

---

## Environment Variables

### GitHub

| Variable | Default | Description |
|----------|---------|-------------|
| `GITHUB_WEBHOOK_SECRET` | - | Webhook signature verification secret |

### Database

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_DATABASE_CONNECTION` | `sqlite` | `sqlite` for isolated, `default` for app DB |
| `CD_DATABASE_PATH` | `/var/lib/sage-grids-cd/deployments.sqlite` | SQLite file path |

### Notifications

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_TELEGRAM_ENABLED` | `false` | Enable Telegram notifications |
| `CD_TELEGRAM_BOT_TOKEN` | - | Telegram bot token |
| `CD_TELEGRAM_CHAT_ID` | - | Telegram chat/group ID |
| `CD_SLACK_ENABLED` | `false` | Enable Slack notifications |
| `CD_SLACK_WEBHOOK_URL` | - | Slack incoming webhook URL |

### Queue

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_QUEUE_CONNECTION` | (default) | Laravel queue connection name |
| `CD_QUEUE_NAME` | (default) | Queue name for deployment jobs |

### Envoy

| Variable | Default | Description |
|----------|---------|-------------|
| `CD_ENVOY_BINARY` | (auto) | Path to Envoy binary |
| `CD_ENVOY_TIMEOUT` | `1800` | Max execution time in seconds |

---

## Multi-App Configuration

### Same Repository, Different Triggers

```php
'apps' => [
    'default' => [
        'name' => 'Main App',
        'repository' => 'company/app',
        'path' => '/var/www/app',
        'strategy' => 'advanced',
        'triggers' => [
            [
                'name' => 'staging',
                'on' => 'push',
                'branch' => 'develop',
            ],
            [
                'name' => 'production',
                'on' => 'release',
                'auto_deploy' => false,
            ],
        ],
    ],
],
```

### Multiple Repositories

```php
'apps' => [
    'api' => [
        'name' => 'API Server',
        'repository' => 'company/api',
        'path' => '/var/www/api',
        // ...
    ],
    'web' => [
        'name' => 'Web Frontend',
        'repository' => 'company/web',
        'path' => '/var/www/web',
        // ...
    ],
    'admin' => [
        'name' => 'Admin Panel',
        'repository' => 'company/admin',
        'path' => '/var/www/admin',
        // ...
    ],
],
```

---

## Isolated Database

The package uses a separate SQLite database to store deployment records:

### Default Location

```
/var/lib/sage-grids-cd/deployments.sqlite
```

### Custom Location

```env
CD_DATABASE_PATH=/custom/path/to/deployments.sqlite
```

### Using Main Database

Set connection to `default` to use your main database:

```env
CD_DATABASE_CONNECTION=default
```

**Warning:** Deployment records will be lost on `migrate:fresh`.

---

## Complete Example

```php
<?php

return [
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
    ],

    'apps' => [
        'default' => [
            'name' => env('APP_NAME', 'My App'),
            'repository' => env('CD_REPOSITORY'),
            'path' => env('CD_APP_PATH', base_path()),
            'strategy' => env('CD_STRATEGY', 'simple'),
            'simple' => [],
            'advanced' => [
                'keep_releases' => 5,
            ],
            'triggers' => [
                [
                    'name' => 'staging',
                    'on' => 'push',
                    'branch' => 'develop',
                    'auto_deploy' => true,
                    'story' => 'staging',
                ],
                [
                    'name' => 'production',
                    'on' => 'release',
                    'tag_pattern' => '/^v\d+\.\d+\.\d+$/',
                    'auto_deploy' => false,
                    'approval_timeout' => 2,
                    'story' => 'production',
                ],
            ],
        ],
    ],

    'database' => [
        'connection' => env('CD_DATABASE_CONNECTION', 'sqlite'),
        'sqlite_path' => env('CD_DATABASE_PATH', '/var/lib/sage-grids-cd/deployments.sqlite'),
    ],

    'approval' => [
        'default_timeout_hours' => 2,
        'auto_expire' => true,
    ],

    'notifications' => [
        'telegram' => [
            'enabled' => env('CD_TELEGRAM_ENABLED', false),
            'bot_token' => env('CD_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('CD_TELEGRAM_CHAT_ID'),
        ],
        'slack' => [
            'enabled' => env('CD_SLACK_ENABLED', false),
            'webhook_url' => env('CD_SLACK_WEBHOOK_URL'),
        ],
    ],

    'queue' => [
        'connection' => env('CD_QUEUE_CONNECTION'),
        'queue' => env('CD_QUEUE_NAME'),
    ],

    'envoy' => [
        'binary' => env('CD_ENVOY_BINARY'),
        'timeout' => env('CD_ENVOY_TIMEOUT', 1800),
    ],
];
```
