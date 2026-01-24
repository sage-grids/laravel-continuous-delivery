<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class DeployerTriggerCommand extends Command
{
    protected $signature = 'deployer:trigger
                            {app=default : The app key}
                            {--trigger=staging : The trigger name}
                            {--ref= : Git reference (branch/tag)}
                            {--force : Skip confirmation}';

    protected $description = 'Manually trigger a deployment';

    public function handle(AppRegistry $registry): int
    {
        $app = $registry->get($this->argument('app'));

        if (! $app) {
            $this->error("App not found: {$this->argument('app')}");

            return self::FAILURE;
        }

        $triggerName = $this->option('trigger');
        $trigger = $app->getTrigger($triggerName);

        if (! $trigger) {
            $this->error("Trigger not found: {$triggerName}");
            $this->line('');
            $this->line('Available triggers:');
            foreach ($app->triggers as $t) {
                $this->line("  - {$t['name']}");
            }

            return self::FAILURE;
        }

        // Determine ref
        $ref = $this->option('ref') ?? $trigger['branch'] ?? $trigger['tag'] ?? 'main';

        // Show deployment info
        $this->info("Manual Deployment");
        $this->newLine();
        $this->table([], [
            ['App', $app->name],
            ['Trigger', $triggerName],
            ['Strategy', $app->strategy],
            ['Path', $app->path],
            ['Ref', $ref],
            ['Story', $app->getEnvoyStory($trigger)],
        ]);

        if (! $this->option('force') && ! $this->confirm('Proceed with deployment?')) {
            $this->warn('Deployment cancelled.');

            return self::SUCCESS;
        }

        // Check for active deployment
        $active = DeployerDeployment::forApp($app->key)
            ->forTrigger($triggerName)
            ->active()
            ->first();

        if ($active) {
            $this->error("Active deployment in progress: {$active->uuid}");

            return self::FAILURE;
        }

        // Create deployment
        $deployment = DeployerDeployment::createManual($app, $trigger, $ref);

        $this->info("Deployment created: {$deployment->uuid}");
        $this->line('Dispatching job...');

        // Dispatch job
        $job = new RunDeployJob($deployment);

        $connection = config('continuous-delivery.queue.connection');
        $queue = config('continuous-delivery.queue.queue');

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);

        $this->info('Deployment queued successfully.');
        $this->line('');
        $this->line("Track progress: php artisan deployer:status {$deployment->uuid}");

        return self::SUCCESS;
    }
}
