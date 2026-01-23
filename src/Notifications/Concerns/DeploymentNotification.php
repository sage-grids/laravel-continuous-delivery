<?php

namespace SageGrids\ContinuousDelivery\Notifications\Concerns;

use SageGrids\ContinuousDelivery\Models\Deployment;

trait DeploymentNotification
{
    public function __construct(
        public Deployment $deployment
    ) {}

    /**
     * Get notification channels.
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (config('continuous-delivery.notifications.telegram.enabled')) {
            $channels[] = 'telegram';
        }

        if (config('continuous-delivery.notifications.slack.enabled')) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    /**
     * Get deployment summary for notifications.
     */
    protected function getDeploymentSummary(): string
    {
        return sprintf(
            '%s (%s)',
            $this->deployment->environment,
            $this->deployment->short_commit_sha
        );
    }

    /**
     * Get deployment details array.
     */
    protected function getDeploymentDetails(): array
    {
        return [
            'Environment' => $this->deployment->environment,
            'Trigger' => "{$this->deployment->trigger_type}:{$this->deployment->trigger_ref}",
            'Commit' => $this->deployment->short_commit_sha,
            'Author' => $this->deployment->author,
        ];
    }

    /**
     * Format details for Telegram.
     */
    protected function formatDetailsForTelegram(): string
    {
        $lines = [];
        foreach ($this->getDeploymentDetails() as $key => $value) {
            $lines[] = "*{$key}:* {$value}";
        }
        return implode("\n", $lines);
    }

    /**
     * Format details for Slack.
     */
    protected function formatDetailsForSlack(): array
    {
        $fields = [];
        foreach ($this->getDeploymentDetails() as $key => $value) {
            $fields[] = [
                'title' => $key,
                'value' => $value,
                'short' => true,
            ];
        }
        return $fields;
    }
}
