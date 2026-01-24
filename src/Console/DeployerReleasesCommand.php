<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Deployers\DeployerFactory;

class DeployerReleasesCommand extends Command
{
    protected $signature = 'deployer:releases
                            {app=default : The app key}';

    protected $description = 'List releases for an application';

    public function handle(AppRegistry $registry, DeployerFactory $factory): int
    {
        $app = $registry->get($this->argument('app'));

        if (! $app) {
            $this->error("App not found: {$this->argument('app')}");

            return self::FAILURE;
        }

        $deployer = $factory->make($app);
        $releases = $deployer->getAvailableReleases($app);

        if (empty($releases)) {
            $this->info("No releases found for app: {$app->name}");

            if ($app->isSimple()) {
                $this->line('');
                $this->line("This app uses 'simple' strategy. Use 'git log' to see history.");
            }

            return self::SUCCESS;
        }

        $this->info("Releases for: {$app->name}");
        $this->newLine();

        if ($app->isAdvanced()) {
            $rows = collect($releases)->map(fn ($r) => [
                $r['name'],
                $r['short_sha'] ?? substr($r['commit_sha'] ?? '', 0, 7),
                $r['is_active'] ? '<fg=green>ACTIVE</>' : '',
                $r['created_at'] ? $r['created_at']->diffForHumans() : '-',
                $r['size_human'] ?? '-',
            ]);

            $this->table(
                ['Release', 'Commit', 'Status', 'Created', 'Size'],
                $rows
            );
        } else {
            // Simple strategy - show git commits
            $rows = collect($releases)->map(fn ($r) => [
                $r['sha'] ?? $r['name'],
                $r['message'] ?? '',
            ]);

            $this->table(
                ['Commit', 'Message'],
                $rows
            );
        }

        return self::SUCCESS;
    }
}
