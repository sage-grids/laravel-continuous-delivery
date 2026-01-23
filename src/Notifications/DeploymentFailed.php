<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentFailed extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $exitCode = $this->deployment->exit_code ?? 'Unknown';
        $output = $this->deployment->output ?? 'No output captured';

        // Truncate output for Telegram
        if (strlen($output) > 500) {
            $output = substr($output, -500) . '...';
        }

        return TelegramMessage::create()
            ->content("*Deployment Failed*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "*Exit Code:* {$exitCode}\n" .
                "*Output:*\n```\n{$output}\n```")
            ->button('View Details', $this->deployment->getStatusUrl());
    }

    public function toSlack(object $notifiable): array
    {
        $fields = $this->formatDetailsForSlack();
        $fields[] = [
            'title' => 'Exit Code',
            'value' => (string) ($this->deployment->exit_code ?? 'Unknown'),
            'short' => true,
        ];

        $output = $this->deployment->output ?? 'No output captured';
        if (strlen($output) > 500) {
            $output = substr($output, -500) . '...';
        }

        return [
            'text' => 'Deployment Failed',
            'attachments' => [
                [
                    'color' => 'danger',
                    'fields' => $fields,
                    'text' => "```{$output}```",
                    'mrkdwn_in' => ['text'],
                    'actions' => [
                        [
                            'type' => 'button',
                            'text' => 'View Details',
                            'url' => $this->deployment->getStatusUrl(),
                        ],
                    ],
                ],
            ],
        ];
    }
}
