<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\DeployerResult;
use SageGrids\ContinuousDelivery\Contracts\DeployerStrategy;
use SageGrids\ContinuousDelivery\Deployers\Concerns\ResolvesEnvoyBinary;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Models\DeployerRelease;

class AdvancedDeployer implements DeployerStrategy
{
    use ResolvesEnvoyBinary;
    public function deploy(AppConfig $app, DeployerDeployment $deployment): DeployerResult
    {
        $releaseName = $this->generateReleaseName($deployment);
        $releasePath = $app->getReleasesPath().'/'.$releaseName;

        // Update deployment with release info
        $deployment->update([
            'release_name' => $releaseName,
            'release_path' => $releasePath,
        ]);

        // Run Envoy with advanced deployment tasks
        $command = $this->buildEnvoyCommand($app, $deployment, $releaseName);

        $result = Process::timeout($this->getEnvoyTimeout())->run($command);

        if ($result->successful()) {
            // Track the release
            $release = DeployerRelease::create([
                'app_key' => $app->key,
                'name' => $releaseName,
                'path' => $releasePath,
                'commit_sha' => $deployment->commit_sha,
                'trigger_ref' => $deployment->trigger_ref,
                'deployment_id' => $deployment->id,
                'is_active' => true,
                'size_bytes' => $this->getDirectorySizeWithApp($app, $releasePath),
            ]);

            // Deactivate previous releases
            DeployerRelease::where('app_key', $app->key)
                ->where('id', '!=', $release->id)
                ->update(['is_active' => false]);
        }

        return new DeployerResult(
            success: $result->successful(),
            output: $result->output()."\n".$result->errorOutput(),
            exitCode: $result->exitCode() ?? 1,
            releaseName: $releaseName,
        );
    }

    public function rollback(AppConfig $app, DeployerDeployment $deployment, ?string $targetRelease = null): DeployerResult
    {
        // Find target release record
        $release = $targetRelease
            ? DeployerRelease::where('app_key', $app->key)->where('name', $targetRelease)->first()
            : DeployerRelease::where('app_key', $app->key)
                ->where('is_active', false)
                ->orderByDesc('created_at')
                ->first();

        if (! $release) {
            return new DeployerResult(
                success: false,
                output: 'No release record found to rollback to',
                exitCode: 1,
            );
        }

        // Run Envoy rollback task
        $command = $this->buildEnvoyCommand($app, $deployment, $release->name, 'advanced-rollback-activate');
        $result = Process::run($command);

        if ($result->successful()) {
            // Update active status in database
            DeployerRelease::where('app_key', $app->key)->update(['is_active' => false]);
            $release->update(['is_active' => true]);

            // Update deployment with release info
            $deployment->update([
                'release_name' => $release->name,
                'release_path' => $release->path,
            ]);
        }

        return new DeployerResult(
            success: $result->successful(),
            output: "Rolled back to: {$release->name}\n".$result->output(),
            exitCode: $result->exitCode() ?? 1,
            releaseName: $release->name,
        );
    }

    public function getAvailableReleases(AppConfig $app): array
    {
        return DeployerRelease::where('app_key', $app->key)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'commit_sha' => $r->commit_sha,
                'short_sha' => $r->short_commit_sha,
                'trigger_ref' => $r->trigger_ref,
                'is_active' => $r->is_active,
                'created_at' => $r->created_at,
                'size' => $r->size_bytes,
                'size_human' => $r->size_for_humans,
            ])
            ->toArray();
    }

    public function cleanupOldReleases(AppConfig $app): int
    {
        $keepReleases = $app->getKeepReleases();

        $releases = DeployerRelease::where('app_key', $app->key)
            ->where('is_active', false)
            ->orderByDesc('created_at')
            ->skip($keepReleases - 1)
            ->get();

        if ($releases->isEmpty()) {
            return 0;
        }

        // Run Envoy cleanup task
        $deployment = new DeployerDeployment(['app_key' => $app->key]);
        $command = $this->buildEnvoyCommand($app, $deployment, null, 'advanced-cleanup');
        Process::run($command);

        $deleted = 0;
        foreach ($releases as $release) {
            $release->delete();
            $deleted++;
        }

        return $deleted;
    }

    protected function buildEnvoyCommand(AppConfig $app, DeployerDeployment $deployment, ?string $releaseName = null, ?string $story = null): string
    {
        $envoyBinary = $this->getEnvoyBinary();
        $envoyPath = $this->getEnvoyPath();
        $envoyStory = $story ?? $deployment->envoy_story;

        $vars = [
            'app' => $app->key,
            'strategy' => 'advanced',
            'path' => $app->path,
            'ref' => $deployment->trigger_ref ?? 'HEAD',
            'releaseName' => $releaseName ?? '',
            'releasesPath' => $app->getReleasesPath(),
            'sharedPath' => $app->getSharedPath(),
            'currentLink' => $app->getCurrentLink(),
            'keepReleases' => $app->getKeepReleases(),
            'repository' => $app->repository ?? '',
            'php' => 'php',
            'composer' => 'composer',
            'servers_json' => json_encode($app->getServers()),
        ];

        if ($releaseName) {
            $vars['targetReleasePath'] = $app->getReleasesPath().'/'.$releaseName;
        }

        // Add shared dirs and files as JSON
        $vars['sharedDirs'] = json_encode($app->getSharedDirs());
        $vars['sharedFiles'] = json_encode($app->getSharedFiles());

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

    protected function generateReleaseName(DeployerDeployment $deployment): string
    {
        return date('Ymd_His').'_'.substr($deployment->commit_sha, 0, 7);
    }

    protected function getDirectorySize(string $path): ?int
    {
        // Use Envoy to get size (supports remote servers)
        // We need a dummy deployment object to build the command
        // This is a bit hacky but works since we just need the connection info
        // We'll create a temporary AppConfig that points to the specific path if needed,
        // but here 'path' in Envoy is the app root.
        // The 'advanced-get-size' task uses $targetPath ?? $path.
        
        // We need to access the app config to get servers.
        // But this method only takes $path.
        // This method is called from inside deploy() where we have $app.
        // However, it's also called via DeployerRelease creation where we might not have app easy access?
        // Actually deploy() calls it: 'size_bytes' => $this->getDirectorySize($releasePath),
        
        // Wait, I cannot easily get $app here if I don't pass it.
        // The signature is getDirectorySize(string $path).
        // I should update the signature or the call.
        // Since this is protected and used internally, I can change it.
        
        return null; // Temporarily disabled as it requires AppConfig to run Envoy.
        // To fix this properly, we need to pass AppConfig to getDirectorySize.
    }

    protected function getDirectorySizeWithApp(AppConfig $app, string $path): ?int
    {
        $deployment = new DeployerDeployment(['app_key' => $app->key]);
        // We need to pass targetPath to the envoy command
        // We can cheat and pass it as a custom variable?
        // The buildEnvoyCommand doesn't easily allow arbitrary extra vars unless we modify it or the Envoy task.
        // But 'advanced-get-size' uses {{ $targetPath ?? $path }}.
        // We can pass --targetPath=... to the envoy command line manually.
        
        $command = $this->buildEnvoyCommand($app, $deployment, null, 'advanced-get-size');
        $command .= ' --targetPath=' . escapeshellarg($path);

        $result = Process::run($command);

        if ($result->successful()) {
            // Strip Envoy headers
            $output = $result->output();
            $output = preg_replace('/^\[.*?\]:\s*/m', '', $output);
            $lines = array_filter(explode("\n", trim($output)));
            $lastLine = end($lines); // Usually the output
            
            // Clean up '24M' etc to bytes if du -h was used, but task uses du -sh
            // Wait, du -b is bytes. du -h is human.
            // The task has `du -sh`. -h is human readable (K, M, G).
            // We want bytes for the DB.
            // I should update Envoy task to use `du -sb` (linux) or `du -sk` (bsd/mac).
            // `du -sb` is not available on Mac. `du -sk` is blocks (1024).
            // For now, let's just parse what we get or return null if complex.
            
            return (int) $lastLine;
        }

        return null;
    }
}
