<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentSucceeded extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $duration = $this->deployment->duration_for_humans ?? 'Unknown';

        return TelegramMessage::create()
            ->content("*Deployment Succeeded*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "*Duration:* {$duration}");
    }

    public function toSlack(object $notifiable): array
    {
        $fields = $this->formatDetailsForSlack();
        $fields[] = [
            'title' => 'Duration',
            'value' => $this->deployment->duration_for_humans ?? 'Unknown',
            'short' => true,
        ];

        return [
            'text' => 'Deployment Succeeded',
            'attachments' => [
                [
                    'color' => 'good',
                    'fields' => $fields,
                ],
            ],
        ];
    }
}
