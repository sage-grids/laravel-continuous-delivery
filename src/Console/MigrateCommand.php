<?php

namespace SageGrids\ContinuousDelivery\Console;

use Illuminate\Console\Command;

class MigrateCommand extends Command
{
    protected $signature = 'deployer:migrate
                            {--fresh : Drop all tables and re-run all migrations}
                            {--rollback : Rollback the last database migration}
                            {--status : Show the status of each migration}
                            {--force : Force the operation to run in production}';

    protected $description = 'Run migrations for the Continuous Delivery package.';

    public function handle(): int
    {
        $migrationPath = dirname(__DIR__, 2) . '/database/migrations';

        if ($this->option('status')) {
            return $this->runMigrationStatus($migrationPath);
        }

        if ($this->option('rollback')) {
            return $this->runRollback($migrationPath);
        }

        if ($this->option('fresh')) {
            return $this->runFresh($migrationPath);
        }

        return $this->runMigrate($migrationPath);
    }

    protected function runMigrate(string $path): int
    {
        $this->info('Running Continuous Delivery migrations...');

        $result = $this->call('migrate', [
            '--database' => 'continuous-delivery',
            '--path' => $path,
            '--realpath' => true,
            '--force' => $this->option('force'),
        ]);

        if ($result === 0) {
            $this->newLine();
            $this->line('  <fg=green>✓</> Migrations completed successfully.');
        }

        return $result;
    }

    protected function runFresh(string $path): int
    {
        if (!$this->option('force') && !$this->confirm('This will drop all tables in the continuous-delivery database. Continue?')) {
            return self::SUCCESS;
        }

        $this->info('Dropping all tables and re-running migrations...');

        $result = $this->call('migrate:fresh', [
            '--database' => 'continuous-delivery',
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($result === 0) {
            $this->newLine();
            $this->line('  <fg=green>✓</> Database reset and migrations completed.');
        }

        return $result;
    }

    protected function runRollback(string $path): int
    {
        $this->info('Rolling back Continuous Delivery migrations...');

        $result = $this->call('migrate:rollback', [
            '--database' => 'continuous-delivery',
            '--path' => $path,
            '--realpath' => true,
            '--force' => $this->option('force'),
        ]);

        if ($result === 0) {
            $this->newLine();
            $this->line('  <fg=green>✓</> Rollback completed.');
        }

        return $result;
    }

    protected function runMigrationStatus(string $path): int
    {
        $this->info('Continuous Delivery migration status:');
        $this->newLine();

        return $this->call('migrate:status', [
            '--database' => 'continuous-delivery',
            '--path' => $path,
            '--realpath' => true,
        ]);
    }
}
