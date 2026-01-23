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
     * Format details for Slack (legacy attachment format).
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

    /**
     * Format Slack Block Kit message.
     */
    protected function formatSlackBlocks(string $title, string $color = '#36a64f', bool $includeActions = false): array
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $title,
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Environment:*\n{$this->deployment->environment}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Trigger:*\n{$this->deployment->trigger_type}:{$this->deployment->trigger_ref}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Commit:*\n`{$this->deployment->short_commit_sha}`",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Author:*\n{$this->deployment->author}",
                    ],
                ],
            ],
        ];

        // Add commit message if available
        if ($this->deployment->commit_message) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Message:*\n{$this->deployment->commit_message}",
                ],
            ];
        }

        // Add actions block for approval notifications
        if ($includeActions) {
            $blocks[] = $this->formatSlackActionsBlock();
        }

        // Add context block with timestamp
        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => "UUID: `{$this->deployment->uuid}` | Status: *{$this->deployment->status}*",
                ],
            ],
        ];

        return [
            'blocks' => $blocks,
            'attachments' => [
                [
                    'color' => $color,
                    'blocks' => [],
                ],
            ],
        ];
    }

    /**
     * Format Slack actions block with approve/reject buttons.
     */
    protected function formatSlackActionsBlock(): array
    {
        $approveUrl = $this->deployment->getApproveUrl();
        $rejectUrl = $this->deployment->getRejectUrl();

        return [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '✅ Approve',
                        'emoji' => true,
                    ],
                    'style' => 'primary',
                    'url' => $approveUrl,
                    'action_id' => 'approve_deployment',
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '❌ Reject',
                        'emoji' => true,
                    ],
                    'style' => 'danger',
                    'url' => $rejectUrl,
                    'action_id' => 'reject_deployment',
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '📊 Status',
                        'emoji' => true,
                    ],
                    'url' => $this->deployment->getStatusUrl(),
                    'action_id' => 'view_status',
                ],
            ],
        ];
    }

    /**
     * Get the color for the notification based on status/type.
     */
    protected function getNotificationColor(): string
    {
        return match ($this->deployment->status) {
            Deployment::STATUS_SUCCESS => '#28a745',      // Green
            Deployment::STATUS_FAILED => '#dc3545',       // Red
            Deployment::STATUS_PENDING_APPROVAL => '#ffc107', // Yellow/Warning
            Deployment::STATUS_REJECTED => '#6c757d',     // Gray
            Deployment::STATUS_EXPIRED => '#6c757d',      // Gray
            Deployment::STATUS_RUNNING => '#17a2b8',      // Info blue
            default => '#007bff',                         // Primary blue
        };
    }
}
