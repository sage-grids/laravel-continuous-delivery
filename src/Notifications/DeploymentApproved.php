<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentApproved extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $approvedBy = $this->deployment->approved_by ?? 'Unknown';

        return TelegramMessage::create()
            ->content("*Deployment Approved*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "*Approved by:* {$approvedBy}\n" .
                "Deployment will begin shortly.");
    }

    public function toSlack(object $notifiable): array
    {
        $fields = $this->formatDetailsForSlack();
        $fields[] = [
            'title' => 'Approved by',
            'value' => $this->deployment->approved_by ?? 'Unknown',
            'short' => true,
        ];

        return [
            'text' => 'Deployment Approved',
            'attachments' => [
                [
                    'color' => 'good',
                    'fields' => $fields,
                    'footer' => 'Deployment will begin shortly.',
                ],
            ],
        ];
    }
}
