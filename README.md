# sage-grids/laravel-continuous-delivery

Multi-environment continuous delivery for Laravel with GitHub webhooks, Laravel Envoy deployment, and human approval workflows.

## Features

- **Multi-Environment Support** - Separate staging and production pipelines
- **GitHub Webhooks** - Trigger deployments from push and release events
- **Human Approval** - Production deployments require approval via Telegram/Slack/CLI
- **Laravel Envoy** - Blade-syntax deployment scripts with built-in notifications
- **Isolated Storage** - Deployment history survives database refreshes
- **Audit Trail** - Full deployment logging with approver tracking

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
php artisan migrate --database=continuous-delivery
```

### 2. Configure

Add to `.env`:

```env
# Required
GITHUB_WEBHOOK_SECRET=your-secret-here
CD_APP_DIR=/path/to/your/app

# Staging (auto-deploy on push to develop)
CD_STAGING_ENABLED=true
CD_STAGING_BRANCH=develop

# Production (requires approval)
CD_PRODUCTION_ENABLED=true
CD_PRODUCTION_APPROVAL=true

# Notifications
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_ID=your-bot-id
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
- **Events**: Push events (staging) or Releases (production)

### 5. Start Queue Worker

```bash
php artisan queue:work
```

---

## Approval Workflow

When a production release is created, you'll receive a notification:

```
🚀 Production Deploy Request

Version: v1.2.3
Commit: abc123
Author: @developer

[✅ Approve] [❌ Reject]

⏰ Expires in 2 hours
```

### Approval Methods

**1. Click notification links** - Telegram/Slack buttons

**2. CLI commands:**
```bash
php artisan deploy:pending           # List pending
php artisan deploy:approve abc123    # Approve
php artisan deploy:reject abc123     # Reject
php artisan deploy:status abc123     # Check status
```

---

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GITHUB_WEBHOOK_SECRET` | - | GitHub webhook secret (required) |
| `CD_APP_DIR` | - | Application directory (required) |
| `CD_DATABASE_PATH` | `/var/lib/sage-grids-cd/deployments.sqlite` | Isolated database |
| `CD_STAGING_ENABLED` | `true` | Enable staging deployments |
| `CD_STAGING_BRANCH` | `develop` | Branch to trigger staging |
| `CD_PRODUCTION_ENABLED` | `false` | Enable production deployments |
| `CD_PRODUCTION_APPROVAL` | `true` | Require approval for production |
| `CD_PRODUCTION_APPROVAL_TIMEOUT` | `2` | Hours before approval expires |
| `CD_TELEGRAM_ENABLED` | `false` | Enable Telegram notifications |
| `CD_TELEGRAM_BOT_ID` | - | Telegram bot ID |
| `CD_TELEGRAM_CHAT_ID` | - | Telegram chat ID |
| `CD_SLACK_ENABLED` | `false` | Enable Slack notifications |
| `CD_SLACK_WEBHOOK` | - | Slack webhook URL |

See [docs/configuration.md](docs/configuration.md) for full reference.

---

## Envoy Deployment

The package uses Laravel Envoy for deployment scripts:

```bash
php artisan vendor:publish --tag=continuous-delivery-envoy
```

### Default Stories

- **`staging`** - Fast incremental deploy (git pull, composer install, migrate)
- **`production`** - Full deploy with maintenance mode and cache clearing
- **`rollback`** - Revert to previous commit

Customize `Envoy.blade.php` in your project root.

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/deploy/github` | GitHub webhook receiver |
| GET | `/api/deploy/status/{uuid}` | Check deployment status |
| GET | `/api/deploy/approve/{token}` | Approve deployment |
| GET | `/api/deploy/reject/{token}` | Reject deployment |

---

## Documentation

- [Configuration Reference](docs/configuration.md)
- [Notifications Setup](docs/notifications.md)
- [Approval Workflow](docs/approval-workflow.md)
- [Server Setup Guide](docs/server-setup.md)

---

## Notifications Setup

### Telegram

1. Create a bot via [@BotFather](https://t.me/botfather)
2. Get chat ID via [@userinfobot](https://t.me/userinfobot)
3. Install: `composer require laravel-notification-channels/telegram`
4. Set `CD_TELEGRAM_BOT_ID` and `CD_TELEGRAM_CHAT_ID`

### Slack

1. Create an [Incoming Webhook](https://api.slack.com/messaging/webhooks)
2. Set `CD_SLACK_WEBHOOK` and `CD_SLACK_CHANNEL`

---

## Security

- Webhook signatures verified using HMAC-SHA256
- Approval tokens are 64 random characters
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
php artisan deploy:pending      # Check pending deployments
php artisan deploy:expire       # Expire old ones
php artisan queue:failed        # Check failed jobs
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
