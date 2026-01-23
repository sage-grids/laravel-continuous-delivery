# Task 12: Create Notifications

**Phase:** 4 - Notifications
**Priority:** P1
**Estimated Effort:** Large
**Depends On:** 04

---

## Objective

Create notification classes for deployment lifecycle events with Telegram and Slack support.

---

## Notifications to Create

1. `DeploymentApprovalRequired` - Request approval for production
2. `DeploymentApproved` - Confirmation of approval
3. `DeploymentRejected` - Confirmation of rejection
4. `DeploymentStarted` - Deployment has begun
5. `DeploymentSucceeded` - Deployment completed successfully
6. `DeploymentFailed` - Deployment failed
7. `DeploymentExpired` - Approval timeout reached

---

## Base Notification Trait

### File: `src/Notifications/Concerns/DeploymentNotification.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications\Concerns;

use Illuminate\Notifications\Messages\SlackMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;

trait DeploymentNotification
{
    /**
     * Get the notification's delivery channels.
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
     * Get Telegram chat ID.
     */
    protected function getTelegramChatId(): string
    {
        return config('continuous-delivery.notifications.telegram.chat_id');
    }

    /**
     * Get Slack webhook URL.
     */
    protected function getSlackWebhook(): string
    {
        return config('continuous-delivery.notifications.slack.webhook_url');
    }

    /**
     * Format deployment info for messages.
     */
    protected function formatDeploymentInfo(Deployment $deployment): string
    {
        return implode("\n", array_filter([
            "*Environment:* {$deployment->environment}",
            "*Version:* {$deployment->trigger_ref}",
            "*Commit:* `{$deployment->short_commit_sha}`",
            "*Author:* {$deployment->author}",
            $deployment->commit_message ? "*Message:* {$deployment->commit_message}" : null,
        ]));
    }
}
```

---

## File: `src/Notifications/DeploymentApprovalRequired.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentApprovalRequired extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content("🚀 *Production Deploy Request*\n\n" . $this->formatDeploymentInfo($d))
            ->button('✅ Approve', $d->getApproveUrl())
            ->button('❌ Reject', $d->getRejectUrl())
            ->line('')
            ->line("⏰ _Expires {$d->time_until_expiry}_");
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->warning()
            ->content('🚀 Production Deploy Request')
            ->attachment(function ($attachment) use ($d) {
                $attachment
                    ->title($d->trigger_ref)
                    ->fields([
                        'Environment' => $d->environment,
                        'Commit' => $d->short_commit_sha,
                        'Author' => $d->author,
                        'Expires' => $d->time_until_expiry,
                    ])
                    ->action('Approve', $d->getApproveUrl())
                    ->action('Reject', $d->getRejectUrl());
            });
    }
}
```

---

## File: `src/Notifications/DeploymentApproved.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentApproved extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content("✅ *Deployment Approved*\n\n" . $this->formatDeploymentInfo($d))
            ->line('')
            ->line("*Approved by:* {$d->approved_by}")
            ->line('')
            ->line('_Deployment is now queued and will begin shortly._');
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->success()
            ->content('✅ Deployment Approved')
            ->attachment(function ($attachment) use ($d) {
                $attachment
                    ->title($d->trigger_ref)
                    ->fields([
                        'Environment' => $d->environment,
                        'Approved By' => $d->approved_by,
                    ]);
            });
    }
}
```

---

## File: `src/Notifications/DeploymentRejected.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentRejected extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        $content = "❌ *Deployment Rejected*\n\n" . $this->formatDeploymentInfo($d);
        $content .= "\n\n*Rejected by:* {$d->rejected_by}";

        if ($d->rejection_reason) {
            $content .= "\n*Reason:* {$d->rejection_reason}";
        }

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content($content);
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->warning()
            ->content('❌ Deployment Rejected')
            ->attachment(function ($attachment) use ($d) {
                $fields = [
                    'Environment' => $d->environment,
                    'Version' => $d->trigger_ref,
                    'Rejected By' => $d->rejected_by,
                ];

                if ($d->rejection_reason) {
                    $fields['Reason'] = $d->rejection_reason;
                }

                $attachment->title($d->trigger_ref)->fields($fields);
            });
    }
}
```

---

## File: `src/Notifications/DeploymentStarted.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentStarted extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content("🔄 *Deployment Started*\n\n" . $this->formatDeploymentInfo($d));
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->content("🔄 Deployment started for {$d->trigger_ref} to {$d->environment}");
    }
}
```

---

## File: `src/Notifications/DeploymentSucceeded.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentSucceeded extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content("✅ *Deployment Successful*\n\n" . $this->formatDeploymentInfo($d))
            ->line('')
            ->line("*Duration:* {$d->duration_for_humans}");
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->success()
            ->content('✅ Deployment Successful')
            ->attachment(function ($attachment) use ($d) {
                $attachment
                    ->title($d->trigger_ref)
                    ->fields([
                        'Environment' => $d->environment,
                        'Duration' => $d->duration_for_humans,
                        'Commit' => $d->short_commit_sha,
                    ]);
            });
    }
}
```

---

## File: `src/Notifications/DeploymentFailed.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentFailed extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content("❌ *Deployment Failed*\n\n" . $this->formatDeploymentInfo($d))
            ->line('')
            ->line("*Exit Code:* {$d->exit_code}")
            ->line('')
            ->line('_Check server logs for details._');
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->error()
            ->content('❌ Deployment Failed')
            ->attachment(function ($attachment) use ($d) {
                $attachment
                    ->title($d->trigger_ref)
                    ->fields([
                        'Environment' => $d->environment,
                        'Exit Code' => (string) $d->exit_code,
                        'Duration' => $d->duration_for_humans ?? '-',
                    ])
                    ->content('Check server logs for details.');
            });
    }
}
```

---

## File: `src/Notifications/DeploymentExpired.php`

```php
<?php

namespace SageGrids\ContinuousDelivery\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\Concerns\DeploymentNotification;

class DeploymentExpired extends Notification
{
    use DeploymentNotification;

    public function __construct(
        public Deployment $deployment
    ) {}

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $d = $this->deployment;

        return TelegramMessage::create()
            ->to($this->getTelegramChatId())
            ->content("⏰ *Deployment Approval Expired*\n\n" . $this->formatDeploymentInfo($d))
            ->line('')
            ->line('_Create a new release to deploy._');
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $d = $this->deployment;

        return (new SlackMessage)
            ->warning()
            ->content("⏰ Deployment approval expired for {$d->trigger_ref}");
    }
}
```

---

## Model Notifiable Setup

Add to `Deployment` model:

```php
use Illuminate\Notifications\Notifiable;

class Deployment extends Model
{
    use Notifiable;

    /**
     * Route notifications for Telegram.
     */
    public function routeNotificationForTelegram(): ?string
    {
        return config('continuous-delivery.notifications.telegram.chat_id');
    }

    /**
     * Route notifications for Slack.
     */
    public function routeNotificationForSlack(): ?string
    {
        return config('continuous-delivery.notifications.slack.webhook_url');
    }
}
```

---

## Optional: Telegram Channel Package

For Telegram support, add to `composer.json`:

```json
{
    "suggest": {
        "laravel-notification-channels/telegram": "Required for Telegram notifications"
    }
}
```

---

## Acceptance Criteria

- [ ] All 7 notification classes created
- [ ] Telegram messages format correctly with markdown
- [ ] Slack messages show appropriate color (success/warning/error)
- [ ] Approval buttons work in Telegram
- [ ] Notifications gracefully fail if channel not configured
- [ ] Model is notifiable with correct routing

---

## Notes

- Uses Laravel's notification channels for extensibility
- Telegram uses `laravel-notification-channels/telegram` package
- Slack uses built-in Laravel Slack channel
- Each notification has emoji prefix for visual recognition
