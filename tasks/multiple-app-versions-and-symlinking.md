Currently, the package updates the app/repo in "current" folder.
See `config/continuous-delivery.php`, `README.md`, and related code files.

while a simple git pull is enough for some cases (especially for staging server), we also need a more robust option: deploying each version to a different/dedicated folder, and then symlinking a `current` path to the latest version.

Of course, we'll need to handle `storage` folder of the app.

Create a plan to implement this feature.
Write your plan into this file.

---

AI Generated plan:

# Implementation Plan: Multi-App Deployments with Version Symlinking

## Executive Summary

This plan redesigns the package to support **multiple applications** with **per-app deployment strategies**. The core package will manage multiple apps/repos through a config-based `apps` array, with each app having its own deployment mode, directory, triggers, and settings.

**Key Design Principles:**
- Multi-app support in the core package (no separate Deployer app needed for basic use)
- Simple mode (git pull) as default - fast and familiar
- Advanced mode (releases + symlinks) as opt-in for production
- Optional standalone Deployer app for SSH-based remote deployments

---

## Part 1: Multi-App Configuration Architecture

### 1.1 New Config Structure (config/continuous-delivery.php)

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    |
    | Define one or more applications to manage. Each app has its own
    | deployment settings, triggers, and notifications.
    |
    | The 'default' app uses the current application. Add more apps
    | to deploy multiple repos from a single installation.
    |
    */
    'apps' => [
        // Default app
        'default' => [
            'name' => env('APP_NAME', 'My App'),
            'repository' => env('CD_REPOSITORY'),  // Optional: auto-detected from .git
            'path' => env('CD_APP_PATH', base_path()),

            // Deployment strategy: 'simple' or 'advanced'
            'strategy' => env('CD_STRATEGY', 'simple'),

            // Strategy-specific settings
            'simple' => [
                // Git pull in place - fast, simple
            ],

            'advanced' => [
                'releases_path' => 'releases',
                'shared_path' => 'shared',
                'current_link' => 'current',
                'keep_releases' => 5,
                'shared_dirs' => ['storage'],
                'shared_files' => ['.env'],
            ],

            // Triggers define when and how to deploy
            'triggers' => [
                [
                    'name' => 'staging',
                    'on' => 'push',
                    'branch' => env('CD_STAGING_BRANCH', 'develop'),
                    'auto_deploy' => true,
                    'story' => 'staging',
                ],
                [
                    'name' => 'production',
                    'on' => 'release',
                    'tag_pattern' => '/^v\d+\.\d+\.\d+$/',
                    'auto_deploy' => false,  // Requires approval
                    'approval_timeout' => 2, // hours
                    'story' => 'production',
                ],
            ],

            // Notifications for this app
            'notifications' => [
                'telegram' => env('CD_TELEGRAM_CHAT_ID'),
                'slack' => env('CD_SLACK_WEBHOOK'),
                'webhook' => env('CD_NOTIFY_WEBHOOK'),
            ],
        ],

        // Example: Additional app (optional)
        // 'api-service' => [
        //     'name' => 'API Service',
        //     'repository' => 'git@github.com:org/api-service.git',
        //     'path' => '/var/www/api-service',
        //     'strategy' => 'advanced',
        //     'advanced' => [
        //         'keep_releases' => 3,
        //         'shared_dirs' => ['storage', 'node_modules'],
        //         'shared_files' => ['.env'],
        //     ],
        //     'triggers' => [
        //         ['name' => 'production', 'on' => 'push', 'branch' => 'main', 'auto_deploy' => true],
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Webhook Settings
    |--------------------------------------------------------------------------
    */
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'verify_signature' => env('CD_VERIFY_SIGNATURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Storage
    |--------------------------------------------------------------------------
    */
    'database' => [
        // 'default' uses app's database, 'sqlite' uses isolated file
        'connection' => env('CD_DATABASE', 'default'),
        'sqlite_path' => env('CD_DATABASE_PATH', '/var/lib/sage-grids-cd/deployments.sqlite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('CD_QUEUE_CONNECTION'),
        'queue' => env('CD_QUEUE_NAME', 'deployments'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Envoy Settings
    |--------------------------------------------------------------------------
    */
    'envoy' => [
        'path' => env('CD_ENVOY_PATH', base_path('Envoy.blade.php')),
        'timeout' => env('CD_ENVOY_TIMEOUT', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Notification Defaults
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'telegram' => [
            'bot_token' => env('CD_TELEGRAM_BOT_TOKEN'),
        ],
        'slack' => [
            'default_channel' => env('CD_SLACK_CHANNEL', '#deployments'),
        ],
    ],
];
```

### 1.2 Environment Variables (.env)

```env
# === Core Settings ===
CD_STRATEGY=simple                    # 'simple' (default) or 'advanced'
CD_APP_PATH=/var/www/my-app           # Where the app lives

# === GitHub Webhook ===
GITHUB_WEBHOOK_SECRET=your-secret-here

# === Staging Trigger ===
CD_STAGING_BRANCH=develop

# === Notifications ===
CD_TELEGRAM_BOT_TOKEN=123456:ABC...
CD_TELEGRAM_CHAT_ID=-100123456789
CD_SLACK_WEBHOOK=https://hooks.slack.com/...

# === Advanced Mode Settings (when CD_STRATEGY=advanced) ===
CD_KEEP_RELEASES=5
```

---

## Part 2: Deployment Strategies

### 2.1 Simple Strategy (Default)

**How it works:**
```
GitHub Push → Webhook → git pull → composer install → migrate → cache
```

**Folder structure:**
```
/var/www/my-app/              # CD_APP_PATH
├── app/
├── bootstrap/
├── config/
├── storage/                  # Normal Laravel storage
├── .env                      # Normal .env file
└── ...
```

**Envoy story (staging):**
```blade
@story('staging')
    pull-code
    install-dependencies
    run-migrations
    cache-config
    restart-queue
@endstory

@task('pull-code')
    cd {{ $path }}
    git fetch origin
    git checkout {{ $ref }}
    git pull origin {{ $ref }}
@endtask
```

**Best for:**
- Development environments
- Staging servers
- Quick iteration
- Simple setups

### 2.2 Advanced Strategy (Releases + Symlinks)

**How it works:**
```
GitHub Release → Webhook → Clone to releases/{timestamp}/ → Link shared → Switch symlink
```

**Folder structure:**
```
/var/www/my-app/              # CD_APP_PATH (base directory)
├── releases/
│   ├── 20240115_120000_abc1234/
│   ├── 20240115_130000_def5678/
│   └── 20240115_140000_ghi9012/   ← latest
│
├── current → releases/20240115_140000_ghi9012/   # Symlink
│
├── shared/
│   ├── storage/
│   │   ├── app/
│   │   │   └── public/       # User uploads persist
│   │   ├── framework/
│   │   └── logs/
│   └── .env
│
└── repo/                     # Optional: bare git repo for speed
```

**Inside each release (after linking):**
```
releases/20240115_140000_ghi9012/
├── app/
├── bootstrap/
├── config/
├── public/
│   └── storage → ../../../shared/storage/app/public
├── storage → ../../shared/storage        # Symlink
├── .env → ../../shared/.env              # Symlink
└── ...
```

**Envoy story (production):**
```blade
@story('production')
    prepare-release
    clone-release
    link-shared-items
    install-dependencies
    optimize-app
    run-migrations
    activate-release
    restart-services
    cleanup-old-releases
@endstory
```

**Best for:**
- Production environments
- Zero-downtime requirements
- Easy rollbacks
- Audit trail of releases

---

## Part 3: Database Schema

### 3.1 Deployer Deployments Table

```php
Schema::create('deployer_deployments', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();

    // App identification
    $table->string('app_key', 50)->default('default');
    $table->string('app_name');

    // Trigger info
    $table->string('trigger_name', 50);      // 'staging', 'production'
    $table->string('trigger_type', 20);      // 'push', 'release', 'manual', 'rollback'
    $table->string('trigger_ref');           // 'develop', 'v1.2.3', etc.

    // Git info
    $table->string('repository')->nullable();
    $table->string('commit_sha', 40);
    $table->text('commit_message')->nullable();
    $table->string('author')->nullable();

    // Strategy info
    $table->string('strategy', 20);          // 'simple' or 'advanced'
    $table->string('release_name', 50)->nullable();  // For advanced: '20240115_120000_abc1234'
    $table->string('release_path', 500)->nullable();

    // Status tracking
    $table->string('status', 30);
    $table->string('envoy_story', 50);

    // Approval workflow
    $table->string('approval_token_hash', 64)->nullable();
    $table->timestamp('approval_expires_at')->nullable();
    $table->string('approved_by')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->string('rejected_by')->nullable();
    $table->timestamp('rejected_at')->nullable();
    $table->text('rejection_reason')->nullable();

    // Execution tracking
    $table->timestamp('queued_at')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->longText('output')->nullable();
    $table->integer('exit_code')->nullable();
    $table->integer('duration_seconds')->nullable();

    // Metadata
    $table->json('payload')->nullable();
    $table->json('metadata')->nullable();

    $table->timestamps();

    // Indexes
    $table->index(['app_key', 'status']);
    $table->index(['app_key', 'created_at']);
    $table->index('status');
});
```

### 3.2 Deployer Releases Table (New - for advanced strategy)

```php
Schema::create('deployer_releases', function (Blueprint $table) {
    $table->id();
    $table->string('app_key', 50);
    $table->string('name', 50);              // '20240115_120000_abc1234'
    $table->string('path', 500);
    $table->string('commit_sha', 40);
    $table->string('trigger_ref');
    $table->foreignId('deployment_id')
        ->constrained('deployer_deployments')
        ->cascadeOnDelete();
    $table->boolean('is_active')->default(false);
    $table->bigInteger('size_bytes')->nullable();
    $table->timestamps();

    $table->unique(['app_key', 'name']);
    $table->index(['app_key', 'is_active']);
});
```

---

## Part 4: Core Classes

### Naming Conventions

| Component | Name |
|-----------|------|
| Database Tables | `deployer_deployments`, `deployer_releases` |
| Models | `DeployerDeployment`, `DeployerRelease` |
| DTOs | `DeployerResult`, `AppConfig` |
| Commands | `deployer:*` (e.g., `deployer:apps`, `deployer:trigger`) |

### 4.1 App Configuration DTO

```php
namespace SageGrids\ContinuousDelivery\Config;

class AppConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $repository,
        public readonly string $path,
        public readonly string $strategy,  // 'simple' or 'advanced'
        public readonly array $strategyConfig,
        public readonly array $triggers,
        public readonly array $notifications,
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key: $key,
            name: $config['name'] ?? $key,
            repository: $config['repository'] ?? null,
            path: $config['path'] ?? base_path(),
            strategy: $config['strategy'] ?? 'simple',
            strategyConfig: $config[$config['strategy'] ?? 'simple'] ?? [],
            triggers: $config['triggers'] ?? [],
            notifications: $config['notifications'] ?? [],
        );
    }

    public function isAdvanced(): bool
    {
        return $this->strategy === 'advanced';
    }

    public function getTrigger(string $name): ?array
    {
        return collect($this->triggers)->firstWhere('name', $name);
    }

    public function getReleasesPath(): string
    {
        return $this->path . '/' . ($this->strategyConfig['releases_path'] ?? 'releases');
    }

    public function getSharedPath(): string
    {
        return $this->path . '/' . ($this->strategyConfig['shared_path'] ?? 'shared');
    }

    public function getCurrentLink(): string
    {
        return $this->path . '/' . ($this->strategyConfig['current_link'] ?? 'current');
    }

    public function getKeepReleases(): int
    {
        return $this->strategyConfig['keep_releases'] ?? 5;
    }
}
```

### 4.2 App Registry

```php
namespace SageGrids\ContinuousDelivery;

class AppRegistry
{
    protected array $apps = [];

    public function __construct()
    {
        $this->loadFromConfig();
    }

    protected function loadFromConfig(): void
    {
        $appsConfig = config('continuous-delivery.apps', []);

        foreach ($appsConfig as $key => $config) {
            $this->apps[$key] = AppConfig::fromArray($key, $config);
        }
    }

    public function get(string $key): ?AppConfig
    {
        return $this->apps[$key] ?? null;
    }

    public function getDefault(): AppConfig
    {
        return $this->get('default') ?? throw new \RuntimeException('No default app configured');
    }

    public function all(): array
    {
        return $this->apps;
    }

    public function findByRepository(string $repository): ?AppConfig
    {
        foreach ($this->apps as $app) {
            if ($app->repository === $repository) {
                return $app;
            }
        }
        return null;
    }

    public function findByTrigger(string $eventType, string $ref): array
    {
        $matches = [];

        foreach ($this->apps as $app) {
            foreach ($app->triggers as $trigger) {
                if ($this->triggerMatches($trigger, $eventType, $ref)) {
                    $matches[] = ['app' => $app, 'trigger' => $trigger];
                }
            }
        }

        return $matches;
    }

    protected function triggerMatches(array $trigger, string $eventType, string $ref): bool
    {
        if ($trigger['on'] !== $eventType) {
            return false;
        }

        if ($eventType === 'push' && isset($trigger['branch'])) {
            return $ref === $trigger['branch'] || $ref === "refs/heads/{$trigger['branch']}";
        }

        if ($eventType === 'release' && isset($trigger['tag_pattern'])) {
            return preg_match($trigger['tag_pattern'], $ref);
        }

        return true;
    }
}
```

### 4.3 Model Classes

```php
namespace SageGrids\ContinuousDelivery\Models;

use Illuminate\Database\Eloquent\Model;

class DeployerDeployment extends Model
{
    protected $table = 'deployer_deployments';

    protected $fillable = [
        'uuid', 'app_key', 'app_name', 'trigger_name', 'trigger_type',
        'trigger_ref', 'repository', 'commit_sha', 'commit_message',
        'author', 'strategy', 'release_name', 'release_path', 'status',
        'envoy_story', 'approval_token_hash', 'approval_expires_at',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'queued_at', 'started_at', 'completed_at',
        'output', 'exit_code', 'duration_seconds', 'payload', 'metadata',
    ];

    protected $casts = [
        'approval_expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload' => 'array',
        'metadata' => 'array',
    ];

    public function releases(): HasMany
    {
        return $this->hasMany(DeployerRelease::class, 'deployment_id');
    }
}

class DeployerRelease extends Model
{
    protected $table = 'deployer_releases';

    protected $fillable = [
        'app_key', 'name', 'path', 'commit_sha', 'trigger_ref',
        'deployment_id', 'is_active', 'size_bytes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'size_bytes' => 'integer',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DeployerDeployment::class, 'deployment_id');
    }
}
```

### 4.5 Deployer Strategy Interface

```php
namespace SageGrids\ContinuousDelivery\Contracts;

use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

interface DeployerStrategy
{
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult;
    public function rollback(AppConfig $app, DeployerDeployment $deployment, ?string $targetRelease = null): DeployerResult;
    public function getAvailableReleases(AppConfig $app): array;
}
```

### 4.6 Simple Deployer

```php
namespace SageGrids\ContinuousDelivery\Deployers;

use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class SimpleDeployer implements DeployerStrategy
{
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult
    {
        // Run Envoy with simple deployment tasks
        $command = $this->buildEnvoyCommand($app, $deployment);
        $result = Process::timeout(config('continuous-delivery.envoy.timeout'))->run($command);

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output() . "\n" . $result->errorOutput(),
            exitCode: $result->exitCode(),
        );
    }

    public function rollback(AppConfig $app, DeployerDeployment $deployment, ?string $targetRelease = null): DeployerResult
    {
        // For simple mode, rollback means checkout previous commit
        $steps = $targetRelease ? (int) $targetRelease : 1;

        $command = sprintf(
            'cd %s && git checkout HEAD~%d',
            escapeshellarg($app->path),
            $steps
        );

        $result = Process::run($command);

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output(),
            exitCode: $result->exitCode(),
        );
    }

    public function getAvailableReleases(AppConfig $app): array
    {
        // For simple mode, return recent git commits
        $command = sprintf(
            'cd %s && git log --oneline -20',
            escapeshellarg($app->path)
        );

        $result = Process::run($command);

        return collect(explode("\n", trim($result->output())))
            ->filter()
            ->map(fn($line) => [
                'sha' => substr($line, 0, 7),
                'message' => substr($line, 8),
            ])
            ->toArray();
    }
}
```

### 4.7 Advanced Deployer (Release-based)

```php
namespace SageGrids\ContinuousDelivery\Deployers;

use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Models\DeployerRelease;

class AdvancedDeployer implements DeployerStrategy
{
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult
    {
        $releaseName = $this->generateReleaseName($deployment);
        $releasePath = $app->getReleasesPath() . '/' . $releaseName;

        // Update deployment with release info
        $deployment->update([
            'release_name' => $releaseName,
            'release_path' => $releasePath,
        ]);

        // Run Envoy with advanced deployment tasks
        $command = $this->buildEnvoyCommand($app, $deployment, $releaseName);
        $result = Process::timeout(config('continuous-delivery.envoy.timeout'))->run($command);

        if ($result->successful()) {
            // Track the release
            DeployerRelease::create([
                'app_key' => $app->key,
                'name' => $releaseName,
                'path' => $releasePath,
                'commit_sha' => $deployment->commit_sha,
                'trigger_ref' => $deployment->trigger_ref,
                'deployment_id' => $deployment->id,
                'is_active' => true,
            ]);

            // Deactivate previous release
            DeployerRelease::where('app_key', $app->key)
                ->where('name', '!=', $releaseName)
                ->update(['is_active' => false]);
        }

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output() . "\n" . $result->errorOutput(),
            exitCode: $result->exitCode(),
            releaseName: $releaseName,
        );
    }

    public function rollback(AppConfig $app, DeployerDeployment $deployment, ?string $targetRelease = null): DeployerResult
    {
        // Find target release
        $release = $targetRelease
            ? DeployerRelease::where('app_key', $app->key)->where('name', $targetRelease)->first()
            : DeployerRelease::where('app_key', $app->key)
                ->where('is_active', false)
                ->orderByDesc('created_at')
                ->first();

        if (!$release) {
            return new DeployerResult(
                success: false,
                output: 'No release to rollback to',
                exitCode: 1,
            );
        }

        // Atomic symlink switch
        $currentLink = $app->getCurrentLink();
        $tempLink = $currentLink . '.new';

        $commands = [
            sprintf('ln -sfn %s %s', escapeshellarg($release->path), escapeshellarg($tempLink)),
            sprintf('mv -Tf %s %s', escapeshellarg($tempLink), escapeshellarg($currentLink)),
        ];

        $result = Process::run(implode(' && ', $commands));

        if ($result->successful()) {
            DeployerRelease::where('app_key', $app->key)->update(['is_active' => false]);
            $release->update(['is_active' => true]);
        }

        return new DeployerResult(
            success: $result->successful(),
            output: "Rolled back to: {$release->name}\n" . $result->output(),
            exitCode: $result->exitCode(),
            releaseName: $release->name,
        );
    }

    public function getAvailableReleases(AppConfig $app): array
    {
        return DeployerRelease::where('app_key', $app->key)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'name' => $r->name,
                'commit_sha' => $r->commit_sha,
                'is_active' => $r->is_active,
                'created_at' => $r->created_at,
                'size' => $r->size_bytes,
            ])
            ->toArray();
    }

    protected function generateReleaseName(DeployerDeployment $deployment): string
    {
        return date('Ymd_His') . '_' . substr($deployment->commit_sha, 0, 7);
    }
}
```

---

## Part 5: Envoy Templates

### 5.1 Complete Envoy.blade.php

```blade
@setup
    // Configuration passed from PHP
    $app = $app ?? 'default';
    $strategy = $strategy ?? 'simple';
    $path = $path ?? '/var/www/app';
    $ref = $ref ?? 'main';
    $php = $php ?? 'php';
    $composer = $composer ?? 'composer';

    // Advanced mode settings
    $releaseName = $releaseName ?? date('Ymd_His');
    $releasesPath = $releasesPath ?? $path . '/releases';
    $sharedPath = $sharedPath ?? $path . '/shared';
    $currentLink = $currentLink ?? $path . '/current';
    $releasePath = $releasesPath . '/' . $releaseName;
    $keepReleases = $keepReleases ?? 5;
    $sharedDirs = $sharedDirs ?? ['storage'];
    $sharedFiles = $sharedFiles ?? ['.env'];
    $repository = $repository ?? null;
@endsetup

@servers(['localhost' => '127.0.0.1'])

{{-- ============================================= --}}
{{-- SIMPLE STRATEGY STORIES                       --}}
{{-- ============================================= --}}

@story('staging')
    simple-pull
    simple-install
    simple-migrate
    simple-cache
    simple-restart-queue
@endstory

@story('production')
    simple-maintenance-on
    simple-pull
    simple-install
    simple-clear-cache
    simple-migrate
    simple-cache
    simple-restart-queue
    simple-maintenance-off
@endstory

@story('rollback')
    simple-rollback
    simple-cache
    simple-restart-queue
@endstory

{{-- Simple Strategy Tasks --}}
@task('simple-pull')
    echo "=== Pulling latest code ==="
    cd {{ $path }}
    git fetch origin --prune
    git checkout {{ $ref }}
    git pull origin {{ $ref }}
    echo "Now at: $(git rev-parse --short HEAD)"
@endtask

@task('simple-install')
    echo "=== Installing dependencies ==="
    cd {{ $path }}
    {{ $composer }} install --no-dev --optimize-autoloader --no-interaction
@endtask

@task('simple-migrate')
    echo "=== Running migrations ==="
    cd {{ $path }}
    {{ $php }} artisan migrate --force
@endtask

@task('simple-cache')
    echo "=== Caching configuration ==="
    cd {{ $path }}
    {{ $php }} artisan config:cache
    {{ $php }} artisan route:cache
    {{ $php }} artisan view:cache
@endtask

@task('simple-clear-cache')
    echo "=== Clearing caches ==="
    cd {{ $path }}
    {{ $php }} artisan cache:clear
    {{ $php }} artisan config:clear
    {{ $php }} artisan route:clear
    {{ $php }} artisan view:clear
@endtask

@task('simple-maintenance-on')
    echo "=== Enabling maintenance mode ==="
    cd {{ $path }}
    {{ $php }} artisan down --retry=60
@endtask

@task('simple-maintenance-off')
    echo "=== Disabling maintenance mode ==="
    cd {{ $path }}
    {{ $php }} artisan up
@endtask

@task('simple-restart-queue')
    echo "=== Restarting queue workers ==="
    cd {{ $path }}
    {{ $php }} artisan queue:restart
@endtask

@task('simple-rollback')
    echo "=== Rolling back to previous commit ==="
    cd {{ $path }}
    git checkout HEAD~1
@endtask

{{-- ============================================= --}}
{{-- ADVANCED STRATEGY STORIES                     --}}
{{-- ============================================= --}}

@story('advanced-staging')
    advanced-prepare
    advanced-clone
    advanced-link-shared
    advanced-install
    advanced-migrate
    advanced-cache
    advanced-activate
    advanced-restart-queue
    advanced-cleanup
@endstory

@story('advanced-production')
    advanced-prepare
    advanced-clone
    advanced-link-shared
    advanced-install
    advanced-clear-cache
    advanced-migrate
    advanced-cache
    advanced-public-storage
    advanced-activate
    advanced-restart-queue
    advanced-cleanup
@endstory

@story('advanced-rollback')
    advanced-rollback-activate
    advanced-restart-queue
@endstory

{{-- Advanced Strategy Tasks --}}
@task('advanced-prepare')
    echo "=== Preparing release: {{ $releaseName }} ==="
    mkdir -p {{ $releasePath }}
    mkdir -p {{ $sharedPath }}/storage/{app/public,framework/{cache,sessions,views},logs}

    # Ensure shared .env exists
    if [ ! -f "{{ $sharedPath }}/.env" ]; then
        echo "WARNING: No .env in shared path. Please create {{ $sharedPath }}/.env"
    fi
@endtask

@task('advanced-clone')
    echo "=== Cloning code to release folder ==="
    @if($repository)
        git clone --depth 1 --branch {{ $ref }} {{ $repository }} {{ $releasePath }}
    @else
        # Copy from current (for same-server deployments)
        if [ -L "{{ $currentLink }}" ]; then
            rsync -a --exclude='.git' --exclude='storage' --exclude='.env' \
                $(readlink -f {{ $currentLink }})/ {{ $releasePath }}/
            cd {{ $releasePath }}
            git fetch origin --prune
            git checkout {{ $ref }}
            git reset --hard {{ $ref }}
        else
            echo "ERROR: No current release and no repository configured"
            exit 1
        fi
    @endif
    echo "Cloned to: {{ $releasePath }}"
@endtask

@task('advanced-link-shared')
    echo "=== Linking shared directories and files ==="

    # Link shared directories
    @foreach($sharedDirs as $dir)
        rm -rf {{ $releasePath }}/{{ $dir }}
        ln -sfn {{ $sharedPath }}/{{ $dir }} {{ $releasePath }}/{{ $dir }}
        echo "Linked: {{ $dir }} → shared/{{ $dir }}"
    @endforeach

    # Link shared files
    @foreach($sharedFiles as $file)
        rm -f {{ $releasePath }}/{{ $file }}
        ln -sfn {{ $sharedPath }}/{{ $file }} {{ $releasePath }}/{{ $file }}
        echo "Linked: {{ $file }} → shared/{{ $file }}"
    @endforeach
@endtask

@task('advanced-install')
    echo "=== Installing dependencies ==="
    cd {{ $releasePath }}
    {{ $composer }} install --no-dev --optimize-autoloader --no-interaction
@endtask

@task('advanced-migrate')
    echo "=== Running migrations ==="
    cd {{ $releasePath }}
    {{ $php }} artisan migrate --force
@endtask

@task('advanced-cache')
    echo "=== Caching configuration ==="
    cd {{ $releasePath }}
    {{ $php }} artisan config:cache
    {{ $php }} artisan route:cache
    {{ $php }} artisan view:cache
@endtask

@task('advanced-clear-cache')
    echo "=== Clearing caches ==="
    cd {{ $releasePath }}
    {{ $php }} artisan cache:clear
    {{ $php }} artisan config:clear
    {{ $php }} artisan route:clear
    {{ $php }} artisan view:clear
@endtask

@task('advanced-public-storage')
    echo "=== Creating public storage symlink ==="
    cd {{ $releasePath }}
    rm -f public/storage
    ln -sfn {{ $sharedPath }}/storage/app/public {{ $releasePath }}/public/storage
@endtask

@task('advanced-activate')
    echo "=== Activating release ==="

    # Atomic symlink switch (two-step for atomicity)
    ln -sfn {{ $releasePath }} {{ $currentLink }}.new
    mv -Tf {{ $currentLink }}.new {{ $currentLink }}

    echo "Active release: $(readlink {{ $currentLink }})"
@endtask

@task('advanced-restart-queue')
    echo "=== Restarting queue workers ==="
    cd {{ $currentLink }}
    {{ $php }} artisan queue:restart
@endtask

@task('advanced-cleanup')
    echo "=== Cleaning up old releases (keeping {{ $keepReleases }}) ==="
    cd {{ $releasesPath }}
    ls -1dt */ | tail -n +{{ $keepReleases + 1 }} | xargs -r rm -rf
    echo "Remaining releases:"
    ls -1dt */
@endtask

@task('advanced-rollback-activate')
    echo "=== Rolling back to previous release ==="
    cd {{ $releasesPath }}
    PREVIOUS=$(ls -1dt */ | sed -n '2p' | tr -d '/')

    if [ -z "$PREVIOUS" ]; then
        echo "ERROR: No previous release found"
        exit 1
    fi

    echo "Rolling back to: $PREVIOUS"
    ln -sfn {{ $releasesPath }}/$PREVIOUS {{ $currentLink }}.new
    mv -Tf {{ $currentLink }}.new {{ $currentLink }}

    echo "Active release: $(readlink {{ $currentLink }})"
@endtask
```

---

## Part 6: CLI Commands

### 6.1 Command Overview

```bash
# List all configured apps
php artisan deployer:apps

# Deployment operations (defaults to 'default' app)
php artisan deployer:trigger [app] [--trigger=staging] [--ref=develop]
php artisan deployer:status [uuid]
php artisan deployer:pending [--app=default]
php artisan deployer:approve <uuid>
php artisan deployer:reject <uuid> [--reason="..."]

# Rollback
php artisan deployer:rollback [app] [--release=20240115_120000_abc1234]

# Release management (advanced mode only)
php artisan deployer:releases [app]
php artisan deployer:cleanup [app] [--keep=5] [--dry-run]
php artisan deployer:activate [app] <release-name>

# Setup
php artisan deployer:setup [app] [--strategy=advanced]
php artisan deployer:install
```

### 6.2 Deployer Apps Command

```php
namespace SageGrids\ContinuousDelivery\Console;

class DeployerAppsCommand extends Command
{
    protected $signature = 'deployer:apps';
    protected $description = 'List all configured applications';

    public function handle(AppRegistry $registry): int
    {
        $apps = $registry->all();

        $rows = [];
        foreach ($apps as $key => $app) {
            $rows[] = [
                $key,
                $app->name,
                $app->strategy,
                $app->path,
                count($app->triggers) . ' trigger(s)',
            ];
        }

        $this->table(
            ['Key', 'Name', 'Strategy', 'Path', 'Triggers'],
            $rows
        );

        return 0;
    }
}
```

### 6.3 Deployer Releases Command

```php
namespace SageGrids\ContinuousDelivery\Console;

class DeployerReleasesCommand extends Command
{
    protected $signature = 'deployer:releases {app=default}';
    protected $description = 'List releases for an application (advanced mode)';

    public function handle(AppRegistry $registry, DeployerFactory $factory): int
    {
        $app = $registry->get($this->argument('app'));

        if (!$app) {
            $this->error("App not found: {$this->argument('app')}");
            return 1;
        }

        if (!$app->isAdvanced()) {
            $this->warn("App '{$app->key}' uses simple strategy. Use 'git log' to see history.");
            return 0;
        }

        $deployer = $factory->make($app);
        $releases = $deployer->getAvailableReleases($app);

        $rows = [];
        foreach ($releases as $release) {
            $rows[] = [
                $release['name'],
                substr($release['commit_sha'], 0, 7),
                $release['is_active'] ? '→ ACTIVE' : '',
                $release['created_at']->diffForHumans(),
                $this->formatBytes($release['size'] ?? 0),
            ];
        }

        $this->table(
            ['Release', 'Commit', 'Status', 'Created', 'Size'],
            $rows
        );

        return 0;
    }
}
```

### 6.4 Deployer Setup Command

```php
namespace SageGrids\ContinuousDelivery\Console;

class DeployerSetupCommand extends Command
{
    protected $signature = 'deployer:setup
        {app=default}
        {--strategy= : Override strategy (simple/advanced)}
        {--migrate-storage : Move existing storage to shared}';

    protected $description = 'Initialize deployment structure for an application';

    public function handle(AppRegistry $registry): int
    {
        $app = $registry->get($this->argument('app'));

        if (!$app) {
            $this->error("App not found: {$this->argument('app')}");
            return 1;
        }

        $strategy = $this->option('strategy') ?? $app->strategy;

        if ($strategy === 'simple') {
            $this->info("Simple strategy requires no setup. Just ensure git is initialized.");
            return 0;
        }

        $this->info("Setting up advanced deployment for: {$app->name}");

        // Create directories
        $dirs = [
            $app->getReleasesPath(),
            $app->getSharedPath(),
            $app->getSharedPath() . '/storage/app/public',
            $app->getSharedPath() . '/storage/framework/cache',
            $app->getSharedPath() . '/storage/framework/sessions',
            $app->getSharedPath() . '/storage/framework/views',
            $app->getSharedPath() . '/storage/logs',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                $this->line("Created: $dir");
            }
        }

        // Migrate storage if requested
        if ($this->option('migrate-storage')) {
            $this->migrateStorage($app);
        }

        // Copy .env to shared
        $envSource = $app->path . '/.env';
        $envDest = $app->getSharedPath() . '/.env';

        if (file_exists($envSource) && !file_exists($envDest)) {
            copy($envSource, $envDest);
            $this->line("Copied .env to shared/");
        }

        $this->info("Setup complete!");
        $this->newLine();
        $this->line("Next steps:");
        $this->line("1. Verify shared/.env has correct settings");
        $this->line("2. Deploy: php artisan deployer:trigger {$app->key}");

        return 0;
    }
}
```

---

## Part 7: Webhook Handling

### 7.1 Updated Webhook Controller

```php
namespace SageGrids\ContinuousDelivery\Http\Controllers;

class WebhookController extends Controller
{
    public function github(Request $request, AppRegistry $registry): JsonResponse
    {
        // Verify signature
        $this->verifyGithubSignature($request);

        $event = $request->header('X-GitHub-Event');
        $payload = $request->all();

        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        // Determine event type and ref
        [$eventType, $ref] = $this->parseGithubEvent($event, $payload);

        if (!$eventType) {
            return response()->json(['message' => 'Event ignored'], 200);
        }

        // Find matching apps and triggers
        $matches = $registry->findByTrigger($eventType, $ref);

        // Also filter by repository if specified
        $repoName = $payload['repository']['full_name'] ?? null;
        $matches = array_filter($matches, function ($match) use ($repoName) {
            $appRepo = $match['app']->repository;
            if (!$appRepo) return true;  // No repo filter
            return str_contains($appRepo, $repoName);
        });

        if (empty($matches)) {
            return response()->json([
                'message' => 'No matching triggers',
                'event' => $eventType,
                'ref' => $ref,
            ], 200);
        }

        $deployments = [];

        foreach ($matches as $match) {
            $deployment = $this->createDeployment(
                $match['app'],
                $match['trigger'],
                $eventType,
                $ref,
                $payload
            );

            $deployments[] = $deployment->uuid;
        }

        return response()->json([
            'message' => count($deployments) . ' deployment(s) created',
            'deployments' => $deployments,
        ], 202);
    }
}
```

---

## Part 8: Optional Deployer App

For users who need to deploy to **remote servers via SSH** or want a **dedicated deployment dashboard**, we provide a separate Deployer App that builds on the core package.

### 8.1 When to Use Deployer App

| Use Case | Core Package | Deployer App |
|----------|--------------|--------------|
| Self-deploying Laravel app | Yes | No |
| Multiple apps on same server | Yes (multi-app config) | Optional |
| Remote SSH deployments | No | Yes |
| Centralized dashboard | No | Yes |
| Team permissions | No | Yes |
| Cross-server deployments | No | Yes |

### 8.2 Deployer App Structure

```
sage-grids/laravel-deployer/       # Separate package/app
├── app/
│   ├── Models/
│   │   ├── Server.php             # SSH connection details
│   │   ├── Target.php             # App + Server combination
│   │   └── RemoteDeployment.php   # Deployment tracking
│   ├── Services/
│   │   └── SshDeployer.php        # phpseclib3 SSH execution
│   └── Http/Controllers/Api/
├── config/deployer.php
└── ...
```

### 8.3 SSH Deployment Flow

```
1. User triggers deploy via CLI/API
2. Deployer connects to server via SSH
3. Executes deployment script on remote server
4. Captures output and tracks status
5. Sends notifications
```

The Deployer App is completely optional and separate from the core package.

---

## Part 9: Implementation Phases

### Phase 1: Multi-App Config & Registry
1. Update config structure with `apps` array
2. Create AppConfig DTO and AppRegistry
3. Update service provider to load apps
4. Write tests

### Phase 2: Strategy System
1. Create DeployerStrategy interface
2. Implement SimpleDeployer
3. Implement AdvancedDeployer
4. Create DeployerFactory
5. Update RunDeployJob to use strategies

### Phase 3: Database & Models
1. Add app_key to deployments table
2. Create releases table
3. Update Deployment model
4. Create Release model

### Phase 4: Envoy Templates
1. Create unified Envoy.blade.php
2. Add simple strategy stories
3. Add advanced strategy stories
4. Test both strategies

### Phase 5: CLI Commands
1. Update existing commands for multi-app
2. Add deployer:apps command
3. Add deployer:releases command
4. Add deployer:setup command
5. Enhance deployer:rollback

### Phase 6: Webhook Updates
1. Update webhook controller for multi-app
2. Match by repository and triggers
3. Handle multiple simultaneous deployments

### Phase 7: Documentation
1. Update README
2. Create multi-app guide
3. Create strategy comparison guide
4. Create setup guides for both strategies

---

## Summary

This architecture provides:

1. **Multi-app support** - Manage multiple repos from single config
2. **Flexible strategies** - Simple (git pull) or Advanced (releases + symlinks)
3. **Per-app configuration** - Each app has its own settings
4. **Default simplicity** - Simple mode is default, works out of the box
5. **Production-ready advanced mode** - Zero-downtime with symlinks
6. **Easy rollbacks** - Works in both strategies
7. **Optional Deployer App** - For SSH-based remote deployments

**Two main usage patterns:**

1. **Single App (Default)** - Just configure 'default' app, deploy itself
2. **Multi App** - Add more apps to config, deploy all from one place

The core package handles local deployments. For remote SSH deployments, users can optionally use the separate Deployer App.
