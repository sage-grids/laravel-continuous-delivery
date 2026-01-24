# Server Setup Guide

Complete guide to setting up continuous delivery on your servers.

## Prerequisites

- Laravel 10, 11, or 12 application
- PHP 8.2+
- Composer
- Git
- Queue worker (Redis, database, or any Laravel queue driver)
- SSH access to server

---

## Quick Setup

### 1. Install Package

```bash
composer require sage-grids/laravel-continuous-delivery
```

### 2. Publish Assets

```bash
php artisan vendor:publish --tag=continuous-delivery
```

### 3. Create Database Directory

```bash
sudo mkdir -p /var/lib/sage-grids-cd
sudo chown www-data:www-data /var/lib/sage-grids-cd
sudo chmod 755 /var/lib/sage-grids-cd
```

### 4. Run Migrations

```bash
php artisan deployer:migrate
```

### 5. Configure Environment

Add to `.env`:

```env
GITHUB_WEBHOOK_SECRET=your-random-secret-here
CD_APP_DIR=/path/to/your/app
```

### 6. Set Up Queue Worker

See Queue Worker section below.

### 7. Configure GitHub Webhook

See GitHub Webhook section below.

---

## Detailed Setup

### Database Directory

The package stores deployment records in an isolated SQLite database:

```bash
# Create directory
sudo mkdir -p /var/lib/sage-grids-cd

# Set ownership (use your web server user)
sudo chown www-data:www-data /var/lib/sage-grids-cd

# Set permissions
sudo chmod 755 /var/lib/sage-grids-cd
```

**Alternative locations:**
- `/var/lib/continuous-delivery/`
- `/opt/sage-grids-cd/`
- `$HOME/.sage-grids-cd/`

Update `CD_DATABASE_PATH` accordingly.

### Remote Servers Configuration

If deploying to remote servers instead of the local machine running the queue worker:

1.  **Configure Servers:** Update `config/continuous-delivery.php` to include the `servers` array in your app config:
    ```php
    'servers' => [
        'web1' => 'deployer@10.0.0.1',
        'web2' => 'deployer@10.0.0.2',
    ],
    ```
2.  **SSH Access:** Ensure the user running the queue worker (e.g., `www-data`) has SSH access to the target servers.
    *   Generate SSH key for `www-data`: `sudo -u www-data ssh-keygen -t ed25519`
    *   Copy public key to targets: `ssh-copy-id -i /var/www/.ssh/id_ed25519.pub deployer@10.0.0.1`
    *   Verify connection: `sudo -u www-data ssh deployer@10.0.0.1 echo ok`

### Queue Worker

Deployments run asynchronously via Laravel queues. Set up a persistent worker:

#### Supervisor (Recommended)

Create `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan queue:work --sleep=3 --tries=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laravel-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

#### Systemd

Create `/etc/systemd/system/laravel-queue.service`:

```ini
[Unit]
Description=Laravel Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /path/to/app/artisan queue:work --sleep=3 --tries=1

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable laravel-queue
sudo systemctl start laravel-queue
```

#### Laravel Horizon

If using Horizon:

```bash
composer require laravel/horizon
php artisan horizon:install
```

Configure supervisor to run `php artisan horizon` instead.

### Scheduler (Optional)

For auto-expiring pending approvals:

```bash
# Add to crontab
crontab -e
```

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## GitHub Webhook Setup

### 1. Get Webhook URL

Your webhook URL is:

```
https://your-domain.com/api/deploy/github
```

### 2. Generate Secret

```bash
# Generate random secret
openssl rand -hex 32
```

Add to `.env`:

```env
GITHUB_WEBHOOK_SECRET=your-generated-secret
```

### 3. Configure GitHub

1. Go to your repository → **Settings** → **Webhooks**
2. Click **Add webhook**
3. Fill in:
   - **Payload URL**: `https://your-domain.com/api/deploy/github`
   - **Content type**: `application/json`
   - **Secret**: Same as `GITHUB_WEBHOOK_SECRET`
4. Select events:
   - **Staging**: Check "Push events"
   - **Production**: Check "Releases"
5. Click **Add webhook**

### 4. Verify Webhook

1. Push a commit or create a release
2. Check GitHub webhook delivery logs
3. Check Laravel logs: `tail -f storage/logs/laravel.log`

---

## Envoy Configuration

### Publish Template

```bash
php artisan vendor:publish --tag=continuous-delivery-envoy
```

This creates `Envoy.blade.php` in your project root.

### Customize for Your Server

Edit `Envoy.blade.php`:

```blade
@setup
    $appDir = env('CD_APP_DIR');
    $ref = $ref ?? 'develop';

    // Add custom variables
    $nodeVersion = '20';
    $npmBuild = true;
@endsetup

@task('install-dependencies')
    cd {{ $appDir }}

    // Use specific PHP version
    /usr/bin/php8.2 /usr/local/bin/composer install --no-dev

    // Add npm build if needed
    @if($npmBuild)
        nvm use {{ $nodeVersion }}
        npm ci
        npm run build
    @endif
@endtask
```

### Test Envoy Locally

```bash
# Test staging story
php vendor/bin/envoy run staging --pretend

# Test with specific ref
php vendor/bin/envoy run production --ref=v1.2.3 --pretend
```

---

## Environment Examples

### Staging Server

```env
# Application
APP_ENV=staging
APP_DEBUG=false

# Continuous Delivery
GITHUB_WEBHOOK_SECRET=staging-secret-here
CD_APP_DIR=/home/staging.example.com/app/current
CD_DATABASE_PATH=/var/lib/sage-grids-cd/deployments.sqlite

# Environment (staging only)
CD_STAGING_BRANCH=develop
CD_STRATEGY=simple

# Notifications
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_TOKEN=123456789
CD_TELEGRAM_CHAT_ID=-100123456789

# Queue
QUEUE_CONNECTION=redis
```

### Production Server

```env
# Application
APP_ENV=production
APP_DEBUG=false

# Continuous Delivery
GITHUB_WEBHOOK_SECRET=production-secret-here
CD_APP_DIR=/home/example.com/app/current
CD_DATABASE_PATH=/var/lib/sage-grids-cd/deployments.sqlite

# Environment (production only)
CD_STRATEGY=advanced
CD_PRODUCTION_APPROVAL_TIMEOUT=2
CD_KEEP_RELEASES=5

# Notifications (both channels for production)
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_TOKEN=123456789
CD_TELEGRAM_CHAT_ID=-100123456789
CD_SLACK_ENABLED=true
CD_SLACK_WEBHOOK=https://hooks.slack.com/...
CD_SLACK_CHANNEL=#production-deploys

# Queue
QUEUE_CONNECTION=redis
```

---

## Security Hardening

### Firewall Rules

Restrict webhook endpoint to GitHub IPs:

```bash
# GitHub webhook IPs (check current list at https://api.github.com/meta)
sudo ufw allow from 192.30.252.0/22 to any port 443
sudo ufw allow from 185.199.108.0/22 to any port 443
sudo ufw allow from 140.82.112.0/20 to any port 443
```

### Web Server Configuration

#### Nginx

```nginx
# Rate limit webhook endpoint
location /api/deploy/github {
    limit_req zone=webhook burst=5 nodelay;
    proxy_pass http://php-fpm;
}
```

#### Apache

```apache
<Location "/api/deploy/github">
    SetEnvIf X-Hub-Signature-256 "^sha256=" VALID_GITHUB
    Order deny,allow
    Deny from all
    Allow from env=VALID_GITHUB
</Location>
```

### Audit Logging

Enable detailed logging:

```php
// config/logging.php
'channels' => [
    'continuous-delivery' => [
        'driver' => 'daily',
        'path' => storage_path('logs/continuous-delivery.log'),
        'level' => 'debug',
        'days' => 30,
    ],
],
```

---

## Troubleshooting

### Webhook Returns 403

1. Check `GITHUB_WEBHOOK_SECRET` matches exactly
2. Verify payload format is `application/json`
3. Check Laravel logs for signature validation errors

### Deployment Doesn't Start

1. Verify queue worker is running: `php artisan queue:work --once`
2. Check failed jobs: `php artisan queue:failed`
3. Review logs: `tail -f storage/logs/laravel.log`

### Envoy Fails

1. Test Envoy manually: `php vendor/bin/envoy run staging`
2. Check `CD_APP_DIR` is correct
3. Verify PHP/Composer are in PATH
4. Check file permissions

### Database Connection Error

1. Verify directory exists and is writable
2. Check path in `CD_DATABASE_PATH`
3. Test connection: `sqlite3 /var/lib/sage-grids-cd/deployments.sqlite ".tables"`
