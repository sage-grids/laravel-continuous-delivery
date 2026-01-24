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
        // For simple mode, rollback means checkout previous commit or specific ref
        $ref = $targetRelease ?? 'HEAD~1';
        
        $command = $this->buildEnvoyCommand($app, $deployment, 'rollback', $ref);
        $result = Process::run($command);

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output()."\n".$result->errorOutput(),
            exitCode: $result->exitCode() ?? 1,
        );
    }

    public function getAvailableReleases(AppConfig $app): array
    {
        // This still runs locally which is a limitation for SimpleDeployer on remote servers
        // unless we wrap this in Envoy too. For now, we'll keep it as is or improve later.
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

    protected function buildEnvoyCommand(AppConfig $app, DeployerDeployment $deployment, ?string $story = null, ?string $ref = null): string
    {
        $envoyBinary = $this->getEnvoyBinary();
        $envoyPath = config('continuous-delivery.envoy.path', base_path('Envoy.blade.php'));
        $envoyStory = $story ?? $deployment->envoy_story;

        $vars = [
            'app' => $app->key,
            'strategy' => 'simple',
            'path' => $app->path,
            'ref' => $ref ?? $deployment->trigger_ref ?? 'HEAD',
            'php' => 'php',
            'composer' => 'composer',
        ];

        $varString = collect($vars)
            ->map(fn ($value, $key) => sprintf('--%s=%s', $key, escapeshellarg($value)))
            ->implode(' ');

        return sprintf(
            '%s run %s --path=%s %s 2>&1',
            $envoyBinary,
            $envoyStory,
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
