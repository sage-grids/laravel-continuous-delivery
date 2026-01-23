<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentExpired extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->content("*Deployment Expired*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "The approval window has expired. Please create a new release to deploy.");
    }

    public function toSlack(object $notifiable): array
    {
        return [
            'text' => 'Deployment Expired',
            'attachments' => [
                [
                    'color' => 'warning',
                    'fields' => $this->formatDetailsForSlack(),
                    'footer' => 'The approval window has expired. Create a new release to deploy.',
                ],
            ],
        ];
    }
}
