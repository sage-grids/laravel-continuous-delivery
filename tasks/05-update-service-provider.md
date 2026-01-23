# Task 05: Update Service Provider

**Phase:** 1 - Foundation
**Priority:** P0
**Estimated Effort:** Medium
**Depends On:** 02, 03, 04

---

## Objective

Update the service provider to register the isolated database connection, migrations, commands, and routes.

---

## File: `src/ContinuousDeliveryServiceProvider.php`

```php
<?php

namespace SageGrids\ContinuousDelivery;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use SageGrids\ContinuousDelivery\Console\ApproveCommand;
use SageGrids\ContinuousDelivery\Console\ExpireCommand;
use SageGrids\ContinuousDelivery\Console\InstallCommand;
use SageGrids\ContinuousDelivery\Console\PendingCommand;
use SageGrids\ContinuousDelivery\Console\RejectCommand;
use SageGrids\ContinuousDelivery\Console\StatusCommand;

class ContinuousDeliveryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/continuous-delivery.php',
            'continuous-delivery'
        );

        $this->registerDatabaseConnection();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerMigrations();
        $this->registerCommands();
        $this->registerViews();
    }

    /**
     * Register the isolated SQLite database connection.
     */
    protected function registerDatabaseConnection(): void
    {
        $this->app->booted(function () {
            $dbPath = config('continuous-delivery.storage.database');

            if (!$dbPath) {
                return;
            }

            // Ensure directory exists
            $dir = dirname($dbPath);
            if (!is_dir($dir) && is_writable(dirname($dir))) {
                mkdir($dir, 0755, true);
            }

            // Create empty database file if it doesn't exist
            if (!file_exists($dbPath) && is_writable($dir)) {
                touch($dbPath);
            }

            // Register the connection
            config(['database.connections.continuous-delivery' => [
                'driver' => 'sqlite',
                'database' => $dbPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        });
    }

    /**
     * Register publishable resources.
     */
    protected function registerPublishables(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__ . '/../config/continuous-delivery.php' => config_path('continuous-delivery.php'),
        ], 'continuous-delivery-config');

        // Envoy template
        $this->publishes([
            __DIR__ . '/../resources/Envoy.blade.php' => base_path('Envoy.blade.php'),
        ], 'continuous-delivery-envoy');

        // Views (for approval confirmation pages)
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/continuous-delivery'),
        ], 'continuous-delivery-views');

        // All publishables
        $this->publishes([
            __DIR__ . '/../config/continuous-delivery.php' => config_path('continuous-delivery.php'),
            __DIR__ . '/../resources/Envoy.blade.php' => base_path('Envoy.blade.php'),
        ], 'continuous-delivery');
    }

    /**
     * Register package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        });
    }

    /**
     * Get route group configuration.
     */
    protected function routeConfiguration(): array
    {
        return [
            'prefix' => 'api',
            'middleware' => config('continuous-delivery.route.middleware', ['api', 'throttle:10,1']),
        ];
    }

    /**
     * Register package migrations.
     */
    protected function registerMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }

    /**
     * Register Artisan commands.
     */
    protected function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            PendingCommand::class,
            ApproveCommand::class,
            RejectCommand::class,
            StatusCommand::class,
            ExpireCommand::class,
        ]);
    }

    /**
     * Register package views.
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'continuous-delivery');
    }
}
```

---

## Directory Structure After Update

```
src/
├── Console/
│   ├── ApproveCommand.php
│   ├── ExpireCommand.php
│   ├── InstallCommand.php
│   ├── PendingCommand.php
│   ├── RejectCommand.php
│   └── StatusCommand.php
├── Http/
│   └── Controllers/
│       ├── ApprovalController.php
│       └── DeployController.php
├── Jobs/
│   └── RunDeployJob.php
├── Models/
│   └── Deployment.php
├── Notifications/
│   ├── DeploymentApprovalRequired.php
│   ├── DeploymentApproved.php
│   ├── DeploymentExpired.php
│   ├── DeploymentFailed.php
│   ├── DeploymentRejected.php
│   └── DeploymentSucceeded.php
└── ContinuousDeliveryServiceProvider.php
```

---

## Publish Commands

```bash
# Publish everything
php artisan vendor:publish --tag=continuous-delivery

# Publish config only
php artisan vendor:publish --tag=continuous-delivery-config

# Publish Envoy template only
php artisan vendor:publish --tag=continuous-delivery-envoy

# Publish views only
php artisan vendor:publish --tag=continuous-delivery-views
```

---

## Acceptance Criteria

- [ ] Isolated SQLite connection is registered when configured
- [ ] Database directory is created automatically
- [ ] Config can be published separately
- [ ] Envoy template can be published
- [ ] Views can be published and overridden
- [ ] All commands are registered
- [ ] Routes are loaded with correct middleware

---

## Notes

- Directory creation is wrapped in `is_writable()` checks for safety
- Views use the `continuous-delivery` namespace for override support
- Commands are only registered in console context
