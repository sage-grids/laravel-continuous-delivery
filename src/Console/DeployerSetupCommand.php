<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Config\AppRegistry;

class DeployerSetupCommand extends Command
{
    protected $signature = 'deployer:setup
                            {app=default : The app key}
                            {--strategy= : Override strategy (simple/advanced)}
                            {--migrate-storage : Move existing storage to shared}';

    protected $description = 'Initialize deployment structure for an application';

    public function handle(AppRegistry $registry): int
    {
        $app = $registry->get($this->argument('app'));

        if (! $app) {
            $this->error("App not found: {$this->argument('app')}");

            return self::FAILURE;
        }

        $strategy = $this->option('strategy') ?? $app->strategy;

        if ($strategy === 'simple') {
            $this->info("Simple strategy requires no setup.");
            $this->line("Just ensure git is initialized in: {$app->path}");

            return self::SUCCESS;
        }

        $this->info("Setting up advanced deployment for: {$app->name}");
        $this->newLine();

        // Create directories
        $dirs = [
            $app->getReleasesPath(),
            $app->getSharedPath(),
            $app->getSharedPath().'/storage/app/public',
            $app->getSharedPath().'/storage/framework/cache',
            $app->getSharedPath().'/storage/framework/sessions',
            $app->getSharedPath().'/storage/framework/views',
            $app->getSharedPath().'/storage/logs',
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                if (mkdir($dir, 0755, true)) {
                    $this->line("  <fg=green>Created:</> {$dir}");
                } else {
                    $this->error("  Failed to create: {$dir}");
                }
            } else {
                $this->line("  <fg=gray>Exists:</> {$dir}");
            }
        }

        // Migrate storage if requested
        if ($this->option('migrate-storage')) {
            $this->migrateStorage($app);
        }

        // Copy .env to shared
        $envSource = $app->path.'/.env';
        $envDest = $app->getSharedPath().'/.env';

        if (file_exists($envSource) && ! file_exists($envDest)) {
            if (copy($envSource, $envDest)) {
                $this->line("  <fg=green>Copied:</> .env to shared/");
            } else {
                $this->warn("  Failed to copy .env to shared/");
            }
        }

        $this->newLine();
        $this->info('Setup complete!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line("  1. Verify {$app->getSharedPath()}/.env has correct settings");
        $this->line("  2. Deploy: php artisan deployer:trigger {$app->key}");

        return self::SUCCESS;
    }

    protected function migrateStorage($app): void
    {
        $sourceStorage = $app->path.'/storage';
        $targetStorage = $app->getSharedPath().'/storage';

        if (! is_dir($sourceStorage)) {
            $this->warn('No storage directory found to migrate.');

            return;
        }

        $this->line('  Migrating storage...');

        // Copy storage contents
        $dirs = ['app', 'framework', 'logs'];
        foreach ($dirs as $dir) {
            $source = $sourceStorage.'/'.$dir;
            $target = $targetStorage.'/'.$dir;

            if (is_dir($source)) {
                $this->copyDirectory($source, $target);
                $this->line("    <fg=green>Migrated:</> storage/{$dir}");
            }
        }
    }

    protected function copyDirectory(string $source, string $target): void
    {
        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $target.'/'.$iterator->getSubPathname();

            if ($item->isDir()) {
                if (! is_dir($targetPath)) {
                    mkdir($targetPath, 0755);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }
}
