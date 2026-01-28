<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use Illuminate\Support\Facades\Log;
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
        Log::debug('[continuous-delivery] Starting simple deployment', [
            'uuid' => $deployment->uuid,
            'app_key' => $app->key,
            'path' => $app->path,
            'trigger_ref' => $deployment->trigger_ref,
            'commit_sha' => $deployment->commit_sha,
            'envoy_story' => $deployment->envoy_story,
            'servers' => $app->getServers(),
        ]);

        $command = $this->buildEnvoyCommand($app, $deployment);

        Log::debug('[continuous-delivery] Executing Envoy command', [
            'uuid' => $deployment->uuid,
            'command' => $command,
            'envoy_path' => $this->getEnvoyPath(),
            'envoy_binary' => $this->getEnvoyBinary(),
            'envoy_path_exists' => file_exists($this->getEnvoyPath()),
            'envoy_binary_exists' => file_exists($this->getEnvoyBinary()),
            'timeout_seconds' => $this->getEnvoyTimeout(),
        ]);

        $result = Process::timeout($this->getEnvoyTimeout())->run($command);

        Log::debug('[continuous-delivery] Envoy command result', [
            'uuid' => $deployment->uuid,
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ]);

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

        Log::debug('[continuous-delivery] Starting simple rollback', [
            'uuid' => $deployment->uuid,
            'app_key' => $app->key,
            'target_release' => $targetRelease,
            'ref' => $ref,
        ]);

        $command = $this->buildEnvoyCommand($app, $deployment, 'rollback', $ref);

        Log::debug('[continuous-delivery] Executing rollback command', [
            'uuid' => $deployment->uuid,
            'command' => $command,
        ]);

        $result = Process::run($command);

        Log::debug('[continuous-delivery] Rollback command result', [
            'uuid' => $deployment->uuid,
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ]);

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output()."\n".$result->errorOutput(),
            exitCode: $result->exitCode() ?? 1,
        );
    }

    public function getAvailableReleases(AppConfig $app): array
    {
        Log::debug('[continuous-delivery] Getting available releases (simple)', [
            'app_key' => $app->key,
        ]);

        // Use Envoy to list releases (supports remote servers)
        // Note: For multiple servers, this will output for all of them.
        // We'll take the output and try to parse it.
        $deployment = new DeployerDeployment(['app_key' => $app->key]);
        $command = $this->buildEnvoyCommand($app, $deployment, 'simple-list-releases');

        Log::debug('[continuous-delivery] Executing list-releases command', [
            'app_key' => $app->key,
            'command' => $command,
        ]);

        $result = Process::run($command);

        Log::debug('[continuous-delivery] List-releases command result', [
            'app_key' => $app->key,
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ]);

        if (! $result->successful()) {
            Log::warning('[continuous-delivery] Failed to get releases list', [
                'app_key' => $app->key,
            ]);

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

        Log::debug('[continuous-delivery] Parsed releases', [
            'app_key' => $app->key,
            'count' => count($releases),
        ]);

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
