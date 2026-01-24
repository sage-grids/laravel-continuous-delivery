# sage-grids/laravel-continuous-delivery

Multi-app continuous delivery for Laravel with GitHub webhooks, Laravel Envoy deployment, and human approval workflows.

## Features

- **Multi-App Support** - Deploy multiple applications from a single installation
- **Deployment Strategies** - Simple (git pull) or Advanced (releases + symlinks)
- **GitHub Webhooks** - Trigger deployments from push and release events
- **Human Approval** - Production deployments require approval via Telegram/Slack/CLI
- **Laravel Envoy** - Blade-syntax deployment scripts with built-in notifications
- **Isolated Storage** - Deployment history survives database refreshes
- **Rollback Support** - Revert to previous releases with a single command

---

## How It Works

### Staging Pipeline (Automatic)

```
Push to develop → Webhook → Auto-deploy → Notify
```

### Production Pipeline (Approval Required)

```
Create release → Webhook → Approval request → Approve → Deploy → Notify
```

---

## Quick Start

### 1. Install

```bash
composer require sage-grids/laravel-continuous-delivery
php artisan vendor:publish --tag=continuous-delivery
php artisan deployer:migrate
```

### 2. Configure

Edit `config/continuous-delivery.php`:

```php
'apps' => [
    'default' => [
        'name' => 'My App',
        'repository' => 'owner/repo',
        'path' => '/var/www/my-app',
        'strategy' => 'simple', // or 'advanced'
        
        // Strategy-specific settings
        'advanced' => [
            'keep_releases' => 5,
        ],

        'triggers' => [
            [
                'name' => 'staging',
                'on' => 'push',
                'branch' => 'develop',
                'auto_deploy' => true,
            ],
            [
                'name' => 'production',
                'on' => 'release',
                'tag_pattern' => '/^v\d+\.\d+\.\d+$/',
                'auto_deploy' => false,
                'approval_timeout' => 2,
            ],
        ],
    ],
],
```

Add to `.env`:

```env
GITHUB_WEBHOOK_SECRET=your-secret-here

# Notifications
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_TOKEN=your-bot-token
CD_TELEGRAM_CHAT_ID=your-chat-id
```

### 3. Set Up Server

```bash
# Create isolated database directory
sudo mkdir -p /var/lib/sage-grids-cd
sudo chown www-data:www-data /var/lib/sage-grids-cd
```

### 4. Configure GitHub Webhook

Go to **Repository Settings → Webhooks → Add webhook**:

- **Payload URL**: `https://your-app.com/api/deploy/github`
- **Content type**: `application/json`
- **Secret**: Same as `GITHUB_WEBHOOK_SECRET`
- **Events**: Push events and Releases

### 5. Start Queue Worker

```bash
php artisan queue:work
```

---

## Deployment Strategies

### Simple Strategy

Git-based in-place deployment:

```
git pull → composer install → migrate → cache:clear
```

Best for: Development, staging, simple applications.

### Advanced Strategy

Release-based deployment with symlinks:

```
releases/
  20240115120000/   # Old release
  20240116140000/   # Current release
shared/
  storage/
  .env
current -> releases/20240116140000
```

Best for: Production, zero-downtime deployments, easy rollbacks.

---

## CLI Commands

```bash
# App management
php artisan deployer:apps                    # List configured apps
php artisan deployer:setup default           # Set up app directories
php artisan deployer:releases default        # List releases (advanced)

# Triggering deployments
php artisan deployer:trigger default staging # Trigger staging deploy
php artisan deployer:trigger default production --ref=v1.2.3

# Approval workflow
php artisan deployer:pending                 # List pending approvals
php artisan deployer:approve abc123          # Approve by UUID
php artisan deployer:reject abc123           # Reject by UUID

# Status and management
php artisan deployer:status                  # Recent deployments
php artisan deployer:status abc123           # Single deployment
php artisan deployer:rollback default        # Rollback to previous

# Maintenance
php artisan deployer:expire                  # Expire pending approvals
php artisan deployer:cleanup --days=90       # Clean old records
```

---

## Approval Workflow

When a production release is created, you'll receive a notification:

```
🚀 Production Deploy Request

App: My App
Trigger: production:v1.2.3
Commit: abc1234

[✅ Approve] [❌ Reject]

⏰ Expires in 2 hours
```

### Approval Methods

**1. Click notification links** - Telegram/Slack buttons

**2. CLI commands:**
```bash
php artisan deployer:pending           # List pending
php artisan deployer:approve abc123    # Approve
php artisan deployer:reject abc123     # Reject
php artisan deployer:status abc123     # Check status
```

---

## Multi-App Configuration

Deploy multiple applications:

```php
'apps' => [
    'api' => [
        'name' => 'API Server',
        'repository' => 'company/api',
        'path' => '/var/www/api',
        'strategy' => 'advanced',
        'triggers' => [
            ['name' => 'staging', 'on' => 'push', 'branch' => 'develop'],
            ['name' => 'production', 'on' => 'release', 'auto_deploy' => false],
        ],
    ],
    'web' => [
        'name' => 'Web Frontend',
        'repository' => 'company/web',
        'path' => '/var/www/web',
        'strategy' => 'simple',
        'triggers' => [
            ['name' => 'staging', 'on' => 'push', 'branch' => 'main'],
        ],
    ],
],
```

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/deploy/github` | GitHub webhook receiver |
| GET | `/api/deploy/status/{uuid}` | Check deployment status |
| GET | `/api/deploy/approve/{token}` | Approve deployment |
| GET | `/api/deploy/reject/{token}` | Reject deployment |
| GET | `/api/deploy/health` | Health check endpoint |

---

## Documentation

- [Configuration Reference](docs/configuration.md)
- [Notifications Setup](docs/notifications.md)
- [Approval Workflow](docs/approval-workflow.md)
- [Server Setup Guide](docs/server-setup.md)

---

## Security

- Webhook signatures verified using HMAC-SHA256
- Approval tokens are 64 random characters with hash storage
- Tokens expire after configurable timeout
- All actions logged with approver identity
- Isolated database prevents data loss on db refresh

---

## Troubleshooting

### Webhook Not Triggering

1. Check GitHub webhook delivery logs
2. Verify `GITHUB_WEBHOOK_SECRET` matches
3. Check Laravel logs: `storage/logs/laravel.log`

### Deployment Stuck

```bash
php artisan deployer:pending      # Check pending deployments
php artisan deployer:expire       # Expire old ones
php artisan queue:failed          # Check failed jobs
```

### Notifications Not Sending

1. Verify bot ID and chat ID are correct
2. Check if notification packages are installed
3. Test with `php artisan tinker`

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- Queue driver (Redis, database, etc.)

---

## License

MIT
