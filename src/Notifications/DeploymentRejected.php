<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentRejected extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $rejectedBy = $this->deployment->rejected_by ?? 'Unknown';
        $reason = $this->deployment->rejection_reason ?? 'No reason provided';

        return TelegramMessage::create()
            ->content("*Deployment Rejected*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "*Rejected by:* {$rejectedBy}\n" .
                "*Reason:* {$reason}");
    }

    public function toSlack(object $notifiable): array
    {
        $fields = $this->formatDetailsForSlack();
        $fields[] = [
            'title' => 'Rejected by',
            'value' => $this->deployment->rejected_by ?? 'Unknown',
            'short' => true,
        ];
        $fields[] = [
            'title' => 'Reason',
            'value' => $this->deployment->rejection_reason ?? 'No reason provided',
            'short' => false,
        ];

        return [
            'text' => 'Deployment Rejected',
            'attachments' => [
                [
                    'color' => 'danger',
                    'fields' => $fields,
                ],
            ],
        ];
    }
}
