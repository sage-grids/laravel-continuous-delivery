<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Config\AppRegistry;

class DeployerAppsCommand extends Command
{
    protected $signature = 'deployer:apps';

    protected $description = 'List all configured applications';

    public function handle(AppRegistry $registry): int
    {
        $apps = $registry->all();

        if (empty($apps)) {
            $this->warn('No apps configured.');
            $this->line('Add apps to your config/continuous-delivery.php file.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($apps as $key => $app) {
            $triggerNames = collect($app->triggers)->pluck('name')->join(', ');
            $rows[] = [
                $key,
                $app->name,
                $app->strategy,
                $app->path,
                $triggerNames ?: '-',
            ];
        }

        $this->table(
            ['Key', 'Name', 'Strategy', 'Path', 'Triggers'],
            $rows
        );

        return self::SUCCESS;
    }
}
