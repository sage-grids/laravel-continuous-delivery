<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentStarted extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->content("*Deployment Started*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "The deployment is now in progress...")
            ->button('View Status', $this->deployment->getStatusUrl());
    }

    public function toSlack(object $notifiable): array
    {
        return [
            'text' => 'Deployment Started',
            'attachments' => [
                [
                    'color' => 'warning',
                    'fields' => $this->formatDetailsForSlack(),
                    'footer' => 'Deployment in progress...',
                    'actions' => [
                        [
                            'type' => 'button',
                            'text' => 'View Status',
                            'url' => $this->deployment->getStatusUrl(),
                        ],
                    ],
                ],
            ],
        ];
    }
}
