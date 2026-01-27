# Quickstart Guide

Step-by-step guide to deploy a fresh Laravel application using the advanced deployment strategy.

---

## Overview

This guide will set up the following directory structure on your server:

```
/var/www/my-app/
├── releases/
│   └── 20240115120000/    # Each deployment creates a timestamped release
├── shared/
│   ├── storage/           # Persistent storage (logs, uploads, cache)
│   └── .env               # Environment configuration
└── current -> releases/20240115120000   # Symlink to active release
```

**How it works:**
- Each deployment clones fresh code into `releases/{timestamp}/`
- Shared files (`.env`, `storage/`) are symlinked into each release
- The `current` symlink points to the active release
- Rollback is instant: just repoint `current` to a previous release

---

## Prerequisites

- **Server:** Linux with SSH access
- **PHP:** 8.2 or higher
- **Composer:** Latest version
- **Git:** Configured with repository access
- **Web Server:** Nginx or Apache
- **Queue Driver:** Redis, database, or any Laravel queue driver

---

## Step 1: Prepare the Server

### 1.1 Create the Application Directory

```bash
# Create the base directory for your application
sudo mkdir -p /var/www/my-app

# Set ownership to your web server user (commonly www-data)
sudo chown -R www-data:www-data /var/www/my-app
```

### 1.2 Create the Deployment Database Directory

The package stores deployment records in an isolated SQLite database:

```bash
sudo mkdir -p /var/lib/sage-grids-cd
sudo chown www-data:www-data /var/lib/sage-grids-cd
sudo chmod 755 /var/lib/sage-grids-cd
```

---

## Step 2: First Deployment (Manual Bootstrap)

The first deployment must be done manually to bootstrap the system.

### 2.1 Clone the Repository

```bash
cd /var/www/my-app

# Create a timestamped release directory
RELEASE_DIR=$(date +%Y%m%d%H%M%S)
sudo -u www-data mkdir -p releases
sudo -u www-data git clone git@github.com:your-org/your-repo.git releases/$RELEASE_DIR

cd releases/$RELEASE_DIR
```

### 2.2 Install Dependencies

```bash
sudo -u www-data composer install --no-dev --optimize-autoloader
```

### 2.3 Create Temporary .env for Setup

```bash
# Create a minimal .env so artisan commands work
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

### 2.4 Run the Setup Command

The `deployer:setup` command automatically creates all required directories:

```bash
sudo -u www-data php artisan deployer:setup default --strategy=advanced
```

This command creates:
- `releases/` directory (already exists from our clone)
- `shared/` directory
- `shared/storage/app/public/`
- `shared/storage/framework/cache/`
- `shared/storage/framework/sessions/`
- `shared/storage/framework/views/`
- `shared/storage/logs/`
- Copies `.env` to `shared/.env`

**With existing storage data:** If you have existing storage data to preserve:

```bash
sudo -u www-data php artisan deployer:setup default --strategy=advanced --migrate-storage
```

### 2.5 Configure the Environment File

Edit the shared `.env` file with your production settings:

```bash
sudo -u www-data nano /var/www/my-app/shared/.env
```

```env
APP_NAME="My App"
APP_ENV=production
APP_KEY=base64:your-app-key-here
APP_DEBUG=false
APP_URL=https://my-app.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_app
DB_USERNAME=my_user
DB_PASSWORD=secret

# Continuous Delivery
GITHUB_WEBHOOK_SECRET=your-webhook-secret
CD_DATABASE_PATH=/var/lib/sage-grids-cd/deployments.sqlite

# Add your other environment variables...
```

### 2.6 Link Shared Resources to Release

```bash
cd /var/www/my-app/releases/$RELEASE_DIR

# Remove the default storage directory
rm -rf storage

# Symlink to shared storage
ln -s /var/www/my-app/shared/storage storage

# Remove the temporary .env and symlink to shared
rm .env
ln -s /var/www/my-app/shared/.env .env
```

### 2.7 Run Migrations

```bash
# Application database migrations
sudo -u www-data php artisan migrate --force

# Continuous Delivery database setup
sudo -u www-data php artisan deployer:migrate
```

### 2.8 Build Assets (if applicable)

```bash
# If using npm/vite
npm ci
npm run build
```

### 2.9 Optimize for Production

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

### 2.10 Create the Current Symlink

```bash
cd /var/www/my-app

# Create the 'current' symlink pointing to your release
sudo -u www-data ln -s releases/$RELEASE_DIR current
```

### 2.11 Set Storage Permissions

```bash
sudo chmod -R 775 /var/www/my-app/shared/storage
sudo chown -R www-data:www-data /var/www/my-app/shared/storage
```

---

## Step 3: Configure Web Server

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name my-app.com;

    # Point to the 'current' symlink
    root /var/www/my-app/current/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName my-app.com
    DocumentRoot /var/www/my-app/current/public

    <Directory /var/www/my-app/current/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo a2ensite my-app.conf
sudo systemctl reload apache2
```

---

## Step 4: Configure the Package

### 4.1 Publish Configuration

```bash
cd /var/www/my-app/current
sudo -u www-data php artisan vendor:publish --tag=continuous-delivery
```

### 4.2 Edit Configuration

Edit `config/continuous-delivery.php`:

```php
'apps' => [
    'default' => [
        'name' => 'My App',
        'repository' => 'your-org/your-repo',
        'path' => '/var/www/my-app',
        'strategy' => 'advanced',

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

### 4.3 Publish Envoy Template

```bash
sudo -u www-data php artisan vendor:publish --tag=continuous-delivery-envoy
```

---

## Step 5: Set Up Queue Worker

Deployments run asynchronously via Laravel queues.

### Using Supervisor (Recommended)

Create `/etc/supervisor/conf.d/my-app-worker.conf`:

```ini
[program:my-app-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/my-app/current/artisan queue:work --sleep=3 --tries=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/my-app-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start my-app-worker:*
```

### Using Systemd

Create `/etc/systemd/system/my-app-queue.service`:

```ini
[Unit]
Description=My App Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/my-app/current/artisan queue:work --sleep=3 --tries=1

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable my-app-queue
sudo systemctl start my-app-queue
```

---

## Step 6: Configure GitHub Webhook

### 6.1 Generate Webhook Secret

```bash
openssl rand -hex 32
```

Add this to your `.env`:

```env
GITHUB_WEBHOOK_SECRET=your-generated-secret
```

### 6.2 Add Webhook in GitHub

1. Go to your repository **Settings** → **Webhooks** → **Add webhook**
2. Configure:
   - **Payload URL:** `https://my-app.com/api/deploy/github`
   - **Content type:** `application/json`
   - **Secret:** Same as `GITHUB_WEBHOOK_SECRET`
3. Select events:
   - Check **Push events** (for staging)
   - Check **Releases** (for production)
4. Click **Add webhook**

---

## Step 7: Set Up Scheduler (Optional)

For auto-expiring pending approvals:

```bash
crontab -e
```

Add:

```
* * * * * cd /var/www/my-app/current && php artisan schedule:run >> /dev/null 2>&1
```

---

## Step 8: Verify Setup

### Check Application Status

```bash
cd /var/www/my-app/current

# List configured apps
php artisan deployer:apps

# View deployment status
php artisan deployer:status
```

### Test Webhook

1. Push a commit to your repository
2. Check GitHub webhook delivery logs
3. Check Laravel logs: `tail -f /var/www/my-app/current/storage/logs/laravel.log`

---

## Subsequent Deployments

After the initial setup, deployments happen automatically via webhooks or can be triggered manually:

### Manual Trigger

```bash
# Trigger staging deployment
php artisan deployer:trigger default staging

# Trigger production deployment (requires approval)
php artisan deployer:trigger default production --ref=v1.0.0
```

### Approval Workflow (Production)

```bash
# List pending approvals
php artisan deployer:pending

# Approve a deployment
php artisan deployer:approve {uuid}

# Reject a deployment
php artisan deployer:reject {uuid}
```

### Rollback

```bash
# Rollback to previous release
php artisan deployer:rollback default
```

---

## Directory Structure Summary

After setup, your server should have:

```
/var/www/my-app/
├── current -> releases/20240115120000    # Symlink (web server points here)
├── releases/
│   └── 20240115120000/                   # Your first release
│       ├── app/
│       ├── bootstrap/
│       ├── config/
│       ├── public/
│       ├── storage -> /var/www/my-app/shared/storage  # Symlink
│       ├── .env -> /var/www/my-app/shared/.env        # Symlink
│       └── ...
└── shared/
    ├── storage/
    │   ├── app/public/
    │   ├── framework/
    │   │   ├── cache/
    │   │   ├── sessions/
    │   │   └── views/
    │   └── logs/
    └── .env

/var/lib/sage-grids-cd/
└── deployments.sqlite                    # Isolated deployment database
```

---

## Troubleshooting

### Permission Denied Errors

```bash
# Fix storage permissions
sudo chown -R www-data:www-data /var/www/my-app/shared/storage
sudo chmod -R 775 /var/www/my-app/shared/storage
```

### Symlink Issues

```bash
# Verify symlinks are correct
ls -la /var/www/my-app/current
ls -la /var/www/my-app/current/storage
ls -la /var/www/my-app/current/.env
```

### Queue Worker Not Processing

```bash
# Check supervisor status
sudo supervisorctl status

# Check failed jobs
php artisan queue:failed

# Process one job manually for debugging
php artisan queue:work --once
```

### Webhook Not Working

1. Check GitHub webhook delivery logs for errors
2. Verify `GITHUB_WEBHOOK_SECRET` matches exactly
3. Ensure the webhook URL is accessible from the internet
4. Check Laravel logs for signature validation errors

---

## Next Steps

- [Configuration Reference](configuration.md) - Detailed configuration options
- [Notifications Setup](notifications.md) - Configure Telegram/Slack notifications
- [Approval Workflow](approval-workflow.md) - Production deployment approvals
- [Server Setup Guide](server-setup.md) - Advanced server configuration
