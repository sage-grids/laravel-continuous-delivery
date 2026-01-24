<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\DeployerResult;
use SageGrids\ContinuousDelivery\Contracts\DeployerStrategy;
use SageGrids\ContinuousDelivery\Deployers\Concerns\ResolvesEnvoyBinary;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class SimpleDeployer implements DeployerStrategy
{
    use ResolvesEnvoyBinary;
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult
    {
        $command = $this->buildEnvoyCommand($app, $deployment);

        $result = Process::timeout($this->getEnvoyTimeout())->run($command);

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
        // Use Envoy to list releases (supports remote servers)
        // Note: For multiple servers, this will output for all of them.
        // We'll take the output and try to parse it.
        $deployment = new DeployerDeployment(['app_key' => $app->key]);
        $command = $this->buildEnvoyCommand($app, $deployment, 'simple-list-releases');

        $result = Process::run($command);

        if (! $result->successful()) {
            return [];
        }

        // Envoy output often contains headers like [localhost]: ...
        // We need to strip these or handle them.
        // For simplicity, we'll parse lines that look like git log output.
        // Git log --oneline format: <hash> <message>
        
        $lines = explode("\n", $result->output());
        $releases = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            // Remove Envoy server prefix if present e.g. [localhost]: a1b2c3d ...
            $line = preg_replace('/^\[.*?\]:\s*/', '', $line);
            
            if (empty($line) || str_starts_with($line, '===')) {
                continue;
            }
            
            $parts = explode(' ', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            
            $hash = $parts[0];
            // Check if it looks like a short hash
            if (! preg_match('/^[a-f0-9]{7,}$/i', $hash)) {
                continue;
            }

            $releases[] = [
                'name' => $hash,
                'sha' => $hash,
                'message' => $parts[1] ?? '',
                'is_active' => false, // Can't easily determine active in simple mode without more logic
            ];
        }

        return $releases;
    }

    protected function buildEnvoyCommand(AppConfig $app, DeployerDeployment $deployment, ?string $story = null, ?string $ref = null): string
    {
        $envoyBinary = $this->getEnvoyBinary();
        $envoyPath = $this->getEnvoyPath();
        $envoyStory = $story ?? $deployment->envoy_story;

        $vars = [
            'app' => $app->key,
            'strategy' => 'simple',
            'path' => $app->path,
            'ref' => $ref ?? $deployment->trigger_ref ?? 'HEAD',
            'php' => 'php',
            'composer' => 'composer',
            'servers_json' => json_encode($app->getServers()),
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

}
