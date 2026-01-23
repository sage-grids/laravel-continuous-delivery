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
        $useBlockKit = config('continuous-delivery.notifications.slack.use_block_kit', true);
        $duration = $this->deployment->duration_for_humans ?? 'Unknown';

        if ($useBlockKit) {
            $message = $this->formatSlackBlocks('✅ Deployment Succeeded', '#28a745');
            $message['text'] = 'Deployment Succeeded';

            // Add duration block
            $message['blocks'][] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "⏱️ *Duration:* {$duration}",
                ],
            ];

            return $message;
        }

        // Fallback to legacy format
        $fields = $this->formatDetailsForSlack();
        $fields[] = [
            'title' => 'Duration',
            'value' => $duration,
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
