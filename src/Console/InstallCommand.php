<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use SageGrids\ContinuousDelivery\Models\Deployment;

class InstallCommand extends Command
{
    protected $signature = 'deploy:install
                            {--all : Publish everything}
                            {--config : Publish the config file only}
                            {--envoy : Publish the Envoy template only}
                            {--views : Publish the views only}';

    protected $description = 'Install the Continuous Delivery package.';

    public function handle(): int
    {
        $this->info('Installing Continuous Delivery package...');
        $this->newLine();

        $all = $this->option('all');
        $anyOption = $this->option('config') || $this->option('envoy') || $this->option('views');

        // If no options specified, default to --all
        if (!$anyOption && !$all) {
            $all = true;
        }

        // Publish config
        if ($all || $this->option('config')) {
            $this->callSilent('vendor:publish', [
                '--tag' => 'continuous-delivery-config',
                '--force' => true,
            ]);
            $this->line('  <fg=green>✓</> Published config: <comment>config/continuous-delivery.php</comment>');
        }

        // Publish Envoy template
        if ($all || $this->option('envoy')) {
            $this->callSilent('vendor:publish', [
                '--tag' => 'continuous-delivery-envoy',
                '--force' => false,
            ]);
            $this->line('  <fg=green>✓</> Published Envoy template: <comment>Envoy.blade.php</comment>');
        }

        // Publish views
        if ($all || $this->option('views')) {
            $this->callSilent('vendor:publish', [
                '--tag' => 'continuous-delivery-views',
                '--force' => false,
            ]);
            $this->line('  <fg=green>✓</> Published views: <comment>resources/views/vendor/continuous-delivery/</comment>');
        }

        // Run migrations (idempotent check)
        $this->newLine();
        $this->info('Running migrations...');

        try {
            $connection = Deployment::resolveConnection();

            // Check if deployments table already exists
            if (Schema::connection($connection)->hasTable('deployments')) {
                $this->line('  <fg=yellow>!</> Deployments table already exists, checking for updates...');

                // Check for missing columns and run migrations if needed
                if (!Schema::connection($connection)->hasColumn('deployments', 'approval_token_hash')) {
                    Artisan::call('migrate', [
                        '--path' => 'vendor/sage-grids/laravel-continuous-delivery/database/migrations',
                        '--force' => true,
                    ]);
                    $this->line('  <fg=green>✓</> Applied pending migrations');
                } else {
                    $this->line('  <fg=green>✓</> Database is up to date');
                }
            } else {
                Artisan::call('migrate', [
                    '--path' => 'vendor/sage-grids/laravel-continuous-delivery/database/migrations',
                    '--force' => true,
                ]);
                $this->line('  <fg=green>✓</> Migrations completed');
            }
        } catch (\Throwable $e) {
            $this->warn('  <fg=yellow>!</> Migrations skipped: ' . $e->getMessage());
            $this->line('     Run migrations manually if needed');
        }

        // Show next steps
        $this->newLine();
        $this->info('Next steps:');
        $this->newLine();

        $this->line('1. Add environment variables to <comment>.env</comment>:');
        $this->newLine();
        $this->line('   <fg=gray># Required</>');
        $this->line('   GITHUB_WEBHOOK_SECRET=<your-github-webhook-secret>');
        $this->newLine();
        $this->line('   <fg=gray># Optional - restrict to specific repo</>');
        $this->line('   CD_REPO_FULL_NAME=owner/repo');
        $this->newLine();
        $this->line('   <fg=gray># Optional - Telegram notifications</>');
        $this->line('   CD_TELEGRAM_BOT_TOKEN=<bot-token>');
        $this->line('   CD_TELEGRAM_CHAT_ID=<chat-id>');
        $this->newLine();

        $this->line('2. Configure your environments in <comment>config/continuous-delivery.php</comment>');
        $this->newLine();

        $this->line('3. Customize <comment>Envoy.blade.php</comment> for your deployment workflow');
        $this->newLine();

        $this->line('4. Add GitHub webhook:');
        $this->line('   URL: <comment>https://your-domain/api/deploy/github</comment>');
        $this->line('   Content type: <comment>application/json</comment>');
        $this->line('   Events: <comment>push</comment> and <comment>release</comment>');
        $this->newLine();

        $this->line('5. Configure queue worker for deployment jobs');
        $this->newLine();

        $this->info('CLI Commands:');
        $this->line('   <comment>php artisan deploy:pending</comment>     List pending approvals');
        $this->line('   <comment>php artisan deploy:approve</comment>     Approve a deployment');
        $this->line('   <comment>php artisan deploy:reject</comment>      Reject a deployment');
        $this->line('   <comment>php artisan deploy:status</comment>      View deployment status');
        $this->line('   <comment>php artisan deploy:cleanup</comment>     Clean up old deployments');
        $this->line('   <comment>php artisan deploy:rollback</comment>    Rollback to previous deployment');
        $this->newLine();

        return self::SUCCESS;
    }
}
