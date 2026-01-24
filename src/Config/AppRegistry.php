<?php

namespace SageGrids\ContinuousDelivery\Config;

use RuntimeException;

class AppRegistry
{
    protected array $apps = [];

    public function __construct()
    {
        $this->loadFromConfig();
    }

    protected function loadFromConfig(): void
    {
        $appsConfig = config('continuous-delivery.apps', []);

        foreach ($appsConfig as $key => $config) {
            $this->apps[$key] = AppConfig::fromArray($key, $config);
        }
    }

    public function get(string $key): ?AppConfig
    {
        return $this->apps[$key] ?? null;
    }

    public function getDefault(): AppConfig
    {
        return $this->get('default') ?? throw new RuntimeException('No default app configured');
    }

    public function all(): array
    {
        return $this->apps;
    }

    public function keys(): array
    {
        return array_keys($this->apps);
    }

    public function findByRepository(string $repository): ?AppConfig
    {
        // Normalize the repository URL for comparison
        $normalizedRepo = $this->normalizeRepository($repository);

        foreach ($this->apps as $app) {
            if (! $app->repository) {
                continue;
            }

            if ($this->normalizeRepository($app->repository) === $normalizedRepo) {
                return $app;
            }
        }

        return null;
    }

    public function findByTrigger(string $eventType, string $ref, ?string $repository = null): array
    {
        $matches = [];

        foreach ($this->apps as $app) {
            // Filter by repository if specified
            if ($repository && $app->repository) {
                if (! $this->repositoryMatches($app->repository, $repository)) {
                    continue;
                }
            }

            $trigger = $app->findTriggerByEvent($eventType, $ref);
            if ($trigger) {
                $matches[] = [
                    'app' => $app,
                    'trigger' => $trigger,
                ];
            }
        }

        return $matches;
    }

    protected function normalizeRepository(string $repository): string
    {
        // Remove .git suffix
        $repo = rtrim($repository, '/');
        $repo = preg_replace('/\.git$/', '', $repo);

        // Extract org/repo from various formats
        // SSH: git@github.com:org/repo
        // HTTPS: https://github.com/org/repo
        if (preg_match('#(?:github\.com[:/])(.+)#', $repo, $matches)) {
            return strtolower($matches[1]);
        }

        return strtolower($repo);
    }

    protected function repositoryMatches(string $appRepo, string $webhookRepo): bool
    {
        $normalizedApp = $this->normalizeRepository($appRepo);
        $normalizedWebhook = $this->normalizeRepository($webhookRepo);

        // Use exact matching after normalization for security
        return $normalizedApp === $normalizedWebhook;
    }
}
