# Task 14: Update README

**Phase:** 5 - Documentation & Cleanup
**Priority:** P2
**Estimated Effort:** Medium
**Depends On:** All previous tasks

---

## Objective

Completely rewrite the README to document the new multi-environment architecture with approval workflows.

---

## File: `README.md`

```markdown
# sage-grids/laravel-continuous-delivery

Multi-environment continuous delivery for Laravel with GitHub webhooks, Laravel Envoy deployment, and human approval workflows.

## Features

- **Multi-Environment Support**: Separate staging and production pipelines
- **GitHub Webhooks**: Trigger deployments from push and release events
- **Human Approval**: Production deployments require approval via Telegram/Slack/CLI
- **Laravel Envoy**: Blade-syntax deployment scripts with built-in notifications
- **Isolated Storage**: Deployment history survives database refreshes
- **Audit Trail**: Full deployment logging with approver tracking

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

### 3. Set Up GitHub Webhook

Go to **Repository Settings → Webhooks → Add webhook**:

- **Payload URL**: `https://your-app.com/api/deploy/github`
- **Content type**: `application/json`
- **Secret**: Same as `GITHUB_WEBHOOK_SECRET`
- **Events**:
  - Staging: *Push events*
  - Production: *Releases*

### 4. Set Up Queue Worker

Deployments run via Laravel queues. Ensure a worker is running:

```bash
php artisan queue:work
```

## How It Works

### Staging Pipeline

```
Push to develop → Webhook → Auto-deploy → Notify
```

### Production Pipeline

```
Create release → Webhook → Approval request → Approve → Deploy → Notify
```

## Approval Methods

### 1. Telegram/Slack Links (Default)

When a production release is created, you'll receive a notification with Approve/Reject buttons:

```
🚀 Production Deploy Request

Version: v1.2.3
Commit: abc123
Author: @developer

[✅ Approve] [❌ Reject]

⏰ Expires in 2 hours
```

### 2. CLI Commands

```bash
# List pending approvals
php artisan deploy:pending

# Approve
php artisan deploy:approve abc123

# Reject
php artisan deploy:reject abc123 --reason="Not ready"

# Check status
php artisan deploy:status abc123
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GITHUB_WEBHOOK_SECRET` | - | GitHub webhook secret (required) |
| `CD_DATABASE_PATH` | `/var/lib/sage-grids-cd/deployments.sqlite` | Isolated SQLite path |
| `CD_APP_DIR` | - | Application directory (required) |
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

### Full Config

Publish and customize `config/continuous-delivery.php`:

```bash
php artisan vendor:publish --tag=continuous-delivery-config
```

## Envoy Deployment Scripts

The package publishes an `Envoy.blade.php` template:

```bash
php artisan vendor:publish --tag=continuous-delivery-envoy
```

### Default Stories

- **`staging`**: Fast incremental deploy (git pull, composer install, migrate)
- **`production`**: Full deploy with maintenance mode and cache clearing
- **`rollback`**: Revert to previous commit

### Customization

Edit `Envoy.blade.php` in your project root to customize deployment steps.

## Server Setup

### 1. Create Database Directory

```bash
sudo mkdir -p /var/lib/sage-grids-cd
sudo chown www-data:www-data /var/lib/sage-grids-cd
```

### 2. Queue Worker

Use Supervisor or systemd to keep the queue worker running:

```ini
[program:queue-worker]
command=php /path/to/app/artisan queue:work --sleep=3 --tries=1
autostart=true
autorestart=true
user=www-data
```

### 3. Scheduler (Optional)

For auto-expiring pending approvals:

```bash
# Add to crontab
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Notifications Setup

### Telegram

1. Create a bot via [@BotFather](https://t.me/botfather)
2. Get your chat ID via [@userinfobot](https://t.me/userinfobot)
3. Install the notification channel:
   ```bash
   composer require laravel-notification-channels/telegram
   ```
4. Set environment variables

### Slack

1. Create an [Incoming Webhook](https://api.slack.com/messaging/webhooks)
2. Set `CD_SLACK_WEBHOOK` and `CD_SLACK_CHANNEL`

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/deploy/github` | GitHub webhook receiver |
| GET | `/api/deploy/status/{uuid}` | Check deployment status |
| GET | `/api/deploy/approve/{token}` | Approve deployment |
| GET | `/api/deploy/reject/{token}` | Reject deployment |

## Troubleshooting

### Webhook Not Triggering

1. Check GitHub webhook delivery logs
2. Verify `GITHUB_WEBHOOK_SECRET` matches
3. Check Laravel logs: `storage/logs/laravel.log`

### Deployment Stuck in Pending

```bash
# Check pending deployments
php artisan deploy:pending

# Force approve
php artisan deploy:approve {uuid} --force

# Or expire old ones
php artisan deploy:expire
```

### Notifications Not Sending

1. Verify Telegram bot ID and chat ID
2. Test with: `php artisan tinker` → send a test notification
3. Check if notification channels are installed

## Security

- Webhook signatures are verified using HMAC-SHA256
- Approval tokens are 64 random characters
- Tokens expire after configurable timeout
- All actions are logged with approver identity

## License

MIT
```

---

## Acceptance Criteria

- [ ] README covers all major features
- [ ] Quick start guide is accurate
- [ ] All environment variables documented
- [ ] Troubleshooting section addresses common issues
- [ ] Examples are copy-pasteable

---

## Notes

- Keep examples concise and practical
- Link to detailed docs for advanced configuration
- Include visual indicators (tables, code blocks)
