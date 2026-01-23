<?php

namespace SageGrids\ContinuousDelivery;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SageGrids\ContinuousDelivery\Console\ApproveCommand;
use SageGrids\ContinuousDelivery\Console\CleanupCommand;
use SageGrids\ContinuousDelivery\Console\ExpireCommand;
use SageGrids\ContinuousDelivery\Console\InstallCommand;
use SageGrids\ContinuousDelivery\Console\PendingCommand;
use SageGrids\ContinuousDelivery\Console\RejectCommand;
use SageGrids\ContinuousDelivery\Console\RollbackCommand;
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
        $this->registerRateLimiting();
        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerMigrations();
        $this->registerCommands();
        $this->registerViews();
        $this->registerScheduler();
    }

    /**
     * Register rate limiting for approval routes.
     */
    protected function registerRateLimiting(): void
    {
        RateLimiter::for('cd-approval', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
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
                @mkdir($dir, 0755, true);
            }

            // Create empty database file if it doesn't exist
            if (!file_exists($dbPath) && is_dir($dir) && is_writable($dir)) {
                @touch($dbPath);
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
            CleanupCommand::class,
            RollbackCommand::class,
        ]);
    }

    /**
     * Register package views.
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'continuous-delivery');
    }

    /**
     * Register scheduled tasks.
     */
    protected function registerScheduler(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function () {
            if (!config('continuous-delivery.approval.auto_expire', true)) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);
            $schedule->command('deploy:expire')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
