<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentApprovalRequired extends Notification
{
    use DeploymentNotification;

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $approveUrl = $this->deployment->getApproveUrl();
        $rejectUrl = $this->deployment->getRejectUrl();
        $expiresAt = $this->deployment->approval_expires_at->format('Y-m-d H:i:s T');

        return TelegramMessage::create()
            ->content("*Deployment Approval Required*\n\n" .
                $this->formatDetailsForTelegram() . "\n\n" .
                "*Expires:* {$expiresAt}\n\n" .
                "Please review and approve or reject this deployment.")
            ->button('Approve', $approveUrl)
            ->button('Reject', $rejectUrl);
    }

    public function toSlack(object $notifiable): array
    {
        $useBlockKit = config('continuous-delivery.notifications.slack.use_block_kit', true);

        if ($useBlockKit) {
            $message = $this->formatSlackBlocks(
                '⚠️ Deployment Approval Required',
                '#ffc107',
                includeActions: true
            );
            $message['text'] = 'Deployment Approval Required';

            // Add expiry info
            $message['blocks'][] = [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "⏰ *Expires:* {$this->deployment->approval_expires_at->format('Y-m-d H:i:s T')}",
                    ],
                ],
            ];

            return $message;
        }

        // Fallback to legacy attachment format
        return [
            'text' => 'Deployment Approval Required',
            'attachments' => [
                [
                    'color' => 'warning',
                    'fields' => $this->formatDetailsForSlack(),
                    'actions' => [
                        [
                            'type' => 'button',
                            'text' => 'Approve',
                            'url' => $this->deployment->getApproveUrl(),
                            'style' => 'primary',
                        ],
                        [
                            'type' => 'button',
                            'text' => 'Reject',
                            'url' => $this->deployment->getRejectUrl(),
                            'style' => 'danger',
                        ],
                    ],
                    'footer' => 'Expires: ' . $this->deployment->approval_expires_at->format('Y-m-d H:i:s T'),
                ],
            ],
        ];
    }
}
