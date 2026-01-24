<?php

namespace SageGrids\ContinuousDelivery\Deployers;

use Illuminate\Support\Facades\Process;
use SageGrids\ContinuousDelivery\Config\AppConfig;
use SageGrids\ContinuousDelivery\Config\DeployerResult;
use SageGrids\ContinuousDelivery\Contracts\DeployerStrategy;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Models\DeployerRelease;

class AdvancedDeployer implements DeployerStrategy
{
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
        $timeout = config('continuous-delivery.envoy.timeout', 1800);

        $result = Process::timeout($timeout)->run($command);

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
                'size_bytes' => $this->getDirectorySize($releasePath),
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
        // Find target release
        $release = $targetRelease
            ? DeployerRelease::where('app_key', $app->key)->where('name', $targetRelease)->first()
            : DeployerRelease::where('app_key', $app->key)
                ->where('is_active', false)
                ->orderByDesc('created_at')
                ->first();

        if (! $release) {
            return new DeployerResult(
                success: false,
                output: 'No release to rollback to',
                exitCode: 1,
            );
        }

        // Verify the release directory exists
        if (! is_dir($release->path)) {
            return new DeployerResult(
                success: false,
                output: "Release directory not found: {$release->path}",
                exitCode: 1,
            );
        }

        // Atomic symlink switch
        $currentLink = $app->getCurrentLink();
        $tempLink = $currentLink.'.new';

        $commands = [
            sprintf('ln -sfn %s %s', escapeshellarg($release->path), escapeshellarg($tempLink)),
            sprintf('mv -Tf %s %s', escapeshellarg($tempLink), escapeshellarg($currentLink)),
        ];

        $result = Process::run(implode(' && ', $commands));

        if ($result->successful()) {
            // Update active status
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
            ->skip($keepReleases - 1) // Keep one less since the active one is separate
            ->get();

        $deleted = 0;

        foreach ($releases as $release) {
            // Delete the directory
            if (is_dir($release->path)) {
                Process::run(sprintf('rm -rf %s', escapeshellarg($release->path)));
            }

            // Delete the record
            $release->delete();
            $deleted++;
        }

        return $deleted;
    }

    protected function buildEnvoyCommand(AppConfig $app, DeployerDeployment $deployment, string $releaseName): string
    {
        $envoyBinary = $this->getEnvoyBinary();
        $envoyPath = config('continuous-delivery.envoy.path', base_path('Envoy.blade.php'));

        $vars = [
            'app' => $app->key,
            'strategy' => 'advanced',
            'path' => $app->path,
            'ref' => $deployment->trigger_ref,
            'releaseName' => $releaseName,
            'releasesPath' => $app->getReleasesPath(),
            'sharedPath' => $app->getSharedPath(),
            'currentLink' => $app->getCurrentLink(),
            'keepReleases' => $app->getKeepReleases(),
            'repository' => $app->repository ?? '',
            'php' => 'php',
            'composer' => 'composer',
        ];

        // Add shared dirs and files as JSON
        $vars['sharedDirs'] = json_encode($app->getSharedDirs());
        $vars['sharedFiles'] = json_encode($app->getSharedFiles());

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

    protected function generateReleaseName(DeployerDeployment $deployment): string
    {
        return date('Ymd_His').'_'.substr($deployment->commit_sha, 0, 7);
    }

    protected function getEnvoyBinary(): string
    {
        $configBinary = config('continuous-delivery.envoy.binary');
        if ($configBinary && file_exists($configBinary)) {
            return $configBinary;
        }

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

    protected function getDirectorySize(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }

        $result = Process::run(sprintf('du -sb %s 2>/dev/null | cut -f1', escapeshellarg($path)));

        if ($result->successful()) {
            return (int) trim($result->output());
        }

        return null;
    }
}
