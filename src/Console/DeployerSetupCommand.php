<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Config\AppRegistry;

class DeployerSetupCommand extends Command
{
    protected $signature = 'deployer:setup
                            {app=default : The app key}
                            {--strategy= : Override strategy (simple/advanced)}
                            {--migrate-storage : Move existing storage to shared}
                            {--release= : Release directory name to link as current}';

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

        // Copy .env to shared (check multiple sources)
        $this->copyEnvToShared($app);

        // Create 'current' symlink if --release option provided or auto-detect
        $this->createCurrentSymlink($app);

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

    protected function copyEnvToShared($app): void
    {
        $envDest = $app->getSharedPath().'/.env';

        if (file_exists($envDest)) {
            $this->line("  <fg=gray>Exists:</> {$envDest}");

            return;
        }

        // Try multiple sources for .env
        $envSources = [
            $app->path.'/.env',  // App root
            base_path('.env'),   // Where artisan is running from (likely for bootstrap)
        ];

        foreach ($envSources as $envSource) {
            if (file_exists($envSource)) {
                if (copy($envSource, $envDest)) {
                    $this->line("  <fg=green>Copied:</> {$envSource} to shared/.env");
                } else {
                    $this->warn("  Failed to copy {$envSource} to shared/.env");
                }

                return;
            }
        }

        $this->warn('  No .env found to copy. Create shared/.env manually.');
    }

    protected function createCurrentSymlink($app): void
    {
        $currentLink = $app->getCurrentLink();

        // Skip if current already exists
        if (is_link($currentLink) || file_exists($currentLink)) {
            $target = is_link($currentLink) ? readlink($currentLink) : $currentLink;
            $this->line("  <fg=gray>Exists:</> current -> {$target}");

            return;
        }

        $releasesPath = $app->getReleasesPath();
        $releaseName = $this->option('release');

        // If --release option provided, use it
        if ($releaseName) {
            $releasePath = $releasesPath.'/'.$releaseName;

            if (! is_dir($releasePath)) {
                $this->error("  Release directory not found: {$releasePath}");

                return;
            }
        } else {
            // Auto-detect: check if we're running from inside a release directory
            $basePath = base_path();

            if (str_starts_with($basePath, $releasesPath.'/')) {
                $releasePath = $basePath;
            } else {
                $this->warn('  Skipped "current" symlink: use --release=<name> to specify which release to link');

                return;
            }
        }

        if (symlink($releasePath, $currentLink)) {
            $this->line("  <fg=green>Created:</> current -> {$releasePath}");
        } else {
            $this->error("  Failed to create current symlink");
        }
    }
}
