<?php

namespace SageGrids\ContinuousDelivery\Config;

use SageGrids\ContinuousDelivery\Enums\DeploymentStrategy;
use SageGrids\ContinuousDelivery\Exceptions\InvalidConfigurationException;

class AppConfig
{
    /**
     * Valid deployment strategies.
     */
    private const VALID_STRATEGIES = ['simple', 'advanced'];

    /**
     * Valid trigger event types.
     */
    private const VALID_TRIGGER_EVENTS = ['push', 'release'];
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $repository,
        public readonly string $path,
        public readonly array $servers,
        public readonly string $strategy,  // 'simple' or 'advanced'
        public readonly array $strategyConfig,
        public readonly array $triggers,
        public readonly array $notifications,
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        $errors = self::validate($key, $config);

        if (! empty($errors)) {
            throw new InvalidConfigurationException($key, $errors);
        }

        $strategy = $config['strategy'] ?? 'simple';

        return new self(
            key: $key,
            name: $config['name'] ?? $key,
            repository: $config['repository'] ?? null,
            path: $config['path'] ?? base_path(),
            servers: $config['servers'] ?? ['localhost' => '127.0.0.1'],
            strategy: $strategy,
            strategyConfig: $config[$strategy] ?? [],
            triggers: $config['triggers'] ?? [],
            notifications: $config['notifications'] ?? [],
        );
    }

    /**
     * Validate app configuration.
     *
     * @return array<string> Validation errors
     */
    public static function validate(string $key, array $config): array
    {
        $errors = [];

        // Validate strategy
        $strategy = $config['strategy'] ?? 'simple';
        if (! in_array($strategy, self::VALID_STRATEGIES, true)) {
            $errors[] = sprintf(
                "Invalid strategy '%s'. Must be one of: %s",
                $strategy,
                implode(', ', self::VALID_STRATEGIES)
            );
        }

        // Validate triggers
        $triggers = $config['triggers'] ?? [];
        foreach ($triggers as $index => $trigger) {
            $triggerErrors = self::validateTrigger($trigger, $index);
            $errors = array_merge($errors, $triggerErrors);
        }

        // Validate path doesn't contain dangerous characters
        $path = $config['path'] ?? '';
        if ($path !== '' && preg_match('/[;&|`$]/', $path)) {
            $errors[] = "Path contains potentially dangerous characters";
        }

        return $errors;
    }

    /**
     * Validate a single trigger configuration.
     *
     * @return array<string> Validation errors
     */
    protected static function validateTrigger(array $trigger, int $index): array
    {
        $errors = [];
        $prefix = "Trigger [{$index}]";

        // Name is required
        if (empty($trigger['name'])) {
            $errors[] = "{$prefix}: 'name' is required";
        }

        // Event type is required and must be valid
        $eventType = $trigger['on'] ?? null;
        if (empty($eventType)) {
            $errors[] = "{$prefix}: 'on' (event type) is required";
        } elseif (! in_array($eventType, self::VALID_TRIGGER_EVENTS, true)) {
            $errors[] = sprintf(
                "%s: Invalid event type '%s'. Must be one of: %s",
                $prefix,
                $eventType,
                implode(', ', self::VALID_TRIGGER_EVENTS)
            );
        }

        // Push triggers should have a branch
        if ($eventType === 'push' && empty($trigger['branch'])) {
            $errors[] = "{$prefix}: 'branch' is required for push triggers";
        }

        // Release triggers should have a tag_pattern
        if ($eventType === 'release' && empty($trigger['tag_pattern'])) {
            $errors[] = "{$prefix}: 'tag_pattern' is required for release triggers";
        }

        // Validate tag_pattern is a valid regex
        if (! empty($trigger['tag_pattern'])) {
            if (@preg_match($trigger['tag_pattern'], '') === false) {
                $errors[] = "{$prefix}: 'tag_pattern' is not a valid regular expression";
            }
        }

        // Validate approval_timeout is positive
        if (isset($trigger['approval_timeout']) && $trigger['approval_timeout'] <= 0) {
            $errors[] = "{$prefix}: 'approval_timeout' must be a positive number";
        }

        return $errors;
    }

    public function isSimple(): bool
    {
        return $this->strategy === DeploymentStrategy::Simple->value;
    }

    public function isAdvanced(): bool
    {
        return $this->strategy === DeploymentStrategy::Advanced->value;
    }

    public function getServers(): array
    {
        return $this->servers;
    }

    public function getTrigger(string $name): ?array
    {
        return collect($this->triggers)->firstWhere('name', $name);
    }

    public function findTriggerByEvent(string $eventType, string $ref): ?array
    {
        foreach ($this->triggers as $trigger) {
            if ($this->triggerMatches($trigger, $eventType, $ref)) {
                return $trigger;
            }
        }

        return null;
    }

    protected function triggerMatches(array $trigger, string $eventType, string $ref): bool
    {
        if ($trigger['on'] !== $eventType) {
            return false;
        }

        if ($eventType === 'push' && isset($trigger['branch'])) {
            $branch = $trigger['branch'];

            return $ref === $branch || $ref === "refs/heads/{$branch}";
        }

        if ($eventType === 'release' && isset($trigger['tag_pattern'])) {
            return (bool) preg_match($trigger['tag_pattern'], $ref);
        }

        return true;
    }

    public function getReleasesPath(): string
    {
        return $this->normalizePath(
            $this->path,
            $this->strategyConfig['releases_path'] ?? 'releases'
        );
    }

    public function getSharedPath(): string
    {
        return $this->normalizePath(
            $this->path,
            $this->strategyConfig['shared_path'] ?? 'shared'
        );
    }

    public function getCurrentLink(): string
    {
        return $this->normalizePath(
            $this->path,
            $this->strategyConfig['current_link'] ?? 'current'
        );
    }

    /**
     * Normalize a path by joining base and suffix without double slashes.
     */
    protected function normalizePath(string $base, string $suffix): string
    {
        return rtrim($base, '/').'/'.ltrim($suffix, '/');
    }

    public function getKeepReleases(): int
    {
        return $this->strategyConfig['keep_releases'] ?? 5;
    }

    public function getSharedDirs(): array
    {
        return $this->strategyConfig['shared_dirs'] ?? ['storage'];
    }

    public function getSharedFiles(): array
    {
        return $this->strategyConfig['shared_files'] ?? ['.env'];
    }

    public function getDevDependencies(): bool
    {
        return (bool) ($this->strategyConfig['dev_dependencies'] ?? false);
    }

    public function getTelegramChatId(): ?string
    {
        return $this->notifications['telegram'] ?? null;
    }

    public function getSlackWebhook(): ?string
    {
        return $this->notifications['slack'] ?? null;
    }

    public function getNotificationWebhook(): ?string
    {
        return $this->notifications['webhook'] ?? null;
    }

    public function requiresApproval(array $trigger): bool
    {
        return ! ($trigger['auto_deploy'] ?? true);
    }

    public function getApprovalTimeout(array $trigger): int
    {
        return $trigger['approval_timeout'] ?? 2;
    }

    public function getEnvoyStory(array $trigger): string
    {
        $baseStory = $trigger['story'] ?? $trigger['name'];

        // For advanced strategy, prefix with 'advanced-'
        if ($this->isAdvanced()) {
            return 'advanced-'.$baseStory;
        }

        return $baseStory;
    }
}
