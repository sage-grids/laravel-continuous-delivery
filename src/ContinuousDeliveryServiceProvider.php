<?php

namespace SageGrids\ContinuousDelivery;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Console\ApproveCommand;
use SageGrids\ContinuousDelivery\Console\CleanupCommand;
use SageGrids\ContinuousDelivery\Console\DeployerAppsCommand;
use SageGrids\ContinuousDelivery\Console\DeployerReleasesCommand;
use SageGrids\ContinuousDelivery\Console\DeployerSetupCommand;
use SageGrids\ContinuousDelivery\Console\DeployerTriggerCommand;
use SageGrids\ContinuousDelivery\Console\ExpireCommand;
use SageGrids\ContinuousDelivery\Console\InstallCommand;
use SageGrids\ContinuousDelivery\Console\MigrateCommand;
use SageGrids\ContinuousDelivery\Console\PendingCommand;
use SageGrids\ContinuousDelivery\Console\RejectCommand;
use SageGrids\ContinuousDelivery\Console\RollbackCommand;
use SageGrids\ContinuousDelivery\Console\StatusCommand;
use SageGrids\ContinuousDelivery\Deployers\DeployerFactory;

class ContinuousDeliveryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/continuous-delivery.php',
            'continuous-delivery'
        );

        $this->registerDatabaseConnection();
        $this->registerServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureDatabaseExists();
        $this->registerRateLimiting();
        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerMigrations();
        $this->registerCommands();
        $this->registerViews();
        $this->registerScheduler();
    }

    /**
     * Register core services.
     */
    protected function registerServices(): void
    {
        // Register AppRegistry as singleton
        $this->app->singleton(AppRegistry::class, function () {
            return new AppRegistry;
        });

        // Register DeployerFactory as singleton
        $this->app->singleton(DeployerFactory::class, function () {
            return new DeployerFactory;
        });
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
        $connection = config('continuous-delivery.database.connection');

        // Only set up SQLite if using isolated storage
        if ($connection !== 'sqlite') {
            return;
        }

        $dbPath = config('continuous-delivery.database.sqlite_path');

        if (! $dbPath) {
            return;
        }

        // Register the connection
        config(['database.connections.continuous-delivery' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * Ensure the SQLite database file and directory exist.
     */
    protected function ensureDatabaseExists(): void
    {
        $connection = config('continuous-delivery.database.connection');

        if ($connection !== 'sqlite') {
            return;
        }

        $dbPath = config('continuous-delivery.database.sqlite_path');

        if (! $dbPath || $dbPath === ':memory:') {
            return;
        }

        // Ensure directory exists
        $dir = dirname($dbPath);
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }

        // Create empty database file if it doesn't exist
        if (! file_exists($dbPath)) {
            if (file_put_contents($dbPath, '') === false) {
                throw new \RuntimeException(sprintf('Database file "%s" could not be created', $dbPath));
            }
        }
    }

    /**
     * Register publishable resources.
     */
    protected function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__.'/../config/continuous-delivery.php' => config_path('continuous-delivery.php'),
        ], 'continuous-delivery-config');

        // Envoy template
        $this->publishes([
            __DIR__.'/../resources/Envoy.blade.php' => base_path('Envoy.blade.php'),
        ], 'continuous-delivery-envoy');

        // Views (for approval confirmation pages)
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/continuous-delivery'),
        ], 'continuous-delivery-views');

        // All publishables
        $this->publishes([
            __DIR__.'/../config/continuous-delivery.php' => config_path('continuous-delivery.php'),
            __DIR__.'/../resources/Envoy.blade.php' => base_path('Envoy.blade.php'),
        ], 'continuous-delivery');
    }

    /**
     * Register package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }

    /**
     * Get route group configuration.
     */
    protected function routeConfiguration(): array
    {
        return [
            'prefix' => config('continuous-delivery.route.prefix'),
            'middleware' => config('continuous-delivery.route.middleware', ['api', 'throttle:10,1']),
        ];
    }

    /**
     * Register package migrations.
     */
    protected function registerMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register Artisan commands.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            // Setup commands
            InstallCommand::class,
            MigrateCommand::class,

            // Deployer commands (new multi-app)
            DeployerAppsCommand::class,
            DeployerTriggerCommand::class,
            DeployerSetupCommand::class,
            DeployerReleasesCommand::class,

            // Deployment management
            StatusCommand::class,
            PendingCommand::class,
            ApproveCommand::class,
            RejectCommand::class,
            RollbackCommand::class,

            // Maintenance
            ExpireCommand::class,
            CleanupCommand::class,
        ]);
    }

    /**
     * Register package views.
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'continuous-delivery');
    }

    /**
     * Register scheduled tasks.
     */
    protected function registerScheduler(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function () {
            if (! config('continuous-delivery.approval.auto_expire', true)) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);
            $schedule->command('deployer:expire')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
