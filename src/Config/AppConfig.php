<?php

namespace SageGrids\ContinuousDelivery\Config;

class AppConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $repository,
        public readonly string $path,
        public readonly string $strategy,  // 'simple' or 'advanced'
        public readonly array $strategyConfig,
        public readonly array $triggers,
        public readonly array $notifications,
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        $strategy = $config['strategy'] ?? 'simple';

        return new self(
            key: $key,
            name: $config['name'] ?? $key,
            repository: $config['repository'] ?? null,
            path: $config['path'] ?? base_path(),
            strategy: $strategy,
            strategyConfig: $config[$strategy] ?? [],
            triggers: $config['triggers'] ?? [],
            notifications: $config['notifications'] ?? [],
        );
    }

    public function isSimple(): bool
    {
        return $this->strategy === 'simple';
    }

    public function isAdvanced(): bool
    {
        return $this->strategy === 'advanced';
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
        return $this->path.'/'.($this->strategyConfig['releases_path'] ?? 'releases');
    }

    public function getSharedPath(): string
    {
        return $this->path.'/'.($this->strategyConfig['shared_path'] ?? 'shared');
    }

    public function getCurrentLink(): string
    {
        return $this->path.'/'.($this->strategyConfig['current_link'] ?? 'current');
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
