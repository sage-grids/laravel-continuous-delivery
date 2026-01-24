<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\DeployerResult;
use SageGrids\ContinuousDelivery\Contracts\DeployerStrategy;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class SimpleDeployer implements DeployerStrategy
{
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult
    {
        $command = $this->buildEnvoyCommand($app, $deployment);
        $timeout = config('continuous-delivery.envoy.timeout', 1800);

        $result = Process::timeout($timeout)->run($command);

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output()."\n".$result->errorOutput(),
            exitCode: $result->exitCode() ?? 1,
        );
    }

    public function rollback(AppConfig $app, DeployerDeployment $deployment, ?string $targetRelease = null): DeployerResult
    {
        // For simple mode, rollback means checkout previous commit
        $steps = $targetRelease ? (int) $targetRelease : 1;

        $command = sprintf(
            'cd %s && git checkout HEAD~%d',
            escapeshellarg($app->path),
            $steps
        );

        $result = Process::run($command);

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output()."\n".$result->errorOutput(),
            exitCode: $result->exitCode() ?? 1,
        );
    }

    public function getAvailableReleases(AppConfig $app): array
    {
        $command = sprintf(
            'cd %s && git log --oneline -20',
            escapeshellarg($app->path)
        );

        $result = Process::run($command);

        if (! $result->successful()) {
            return [];
        }

        return collect(explode("\n", trim($result->output())))
            ->filter()
            ->map(function ($line) {
                $parts = explode(' ', $line, 2);

                return [
                    'name' => $parts[0],
                    'sha' => $parts[0],
                    'message' => $parts[1] ?? '',
                    'is_active' => false,
                ];
            })
            ->toArray();
    }

    protected function buildEnvoyCommand(AppConfig $app, DeployerDeployment $deployment): string
    {
        $envoyBinary = $this->getEnvoyBinary();
        $envoyPath = config('continuous-delivery.envoy.path', base_path('Envoy.blade.php'));

        $vars = [
            'app' => $app->key,
            'strategy' => 'simple',
            'path' => $app->path,
            'ref' => $deployment->trigger_ref,
            'php' => 'php',
            'composer' => 'composer',
        ];

        $varString = collect($vars)
            ->map(fn ($value, $key) => sprintf('--%s=%s', $key, escapeshellarg($value)))
            ->implode(' ');

        return sprintf(
            '%s run %s --path=%s %s 2>&1',
            $envoyBinary,
            $deployment->envoy_story,
            escapeshellarg($envoyPath),
            $varString
        );
    }

    protected function getEnvoyBinary(): string
    {
        $configBinary = config('continuous-delivery.envoy.binary');
        if ($configBinary && file_exists($configBinary)) {
            return $configBinary;
        }

        // Try common locations
        $locations = [
            base_path('vendor/bin/envoy'),
            '/usr/local/bin/envoy',
            'envoy',
        ];

        foreach ($locations as $location) {
            if ($location === 'envoy' || file_exists($location)) {
                return $location;
            }
        }

        return 'envoy';
    }
}
