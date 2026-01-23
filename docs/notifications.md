# Notifications Setup

Configure Telegram and Slack notifications for deployment events.

## Notification Events

| Event | Description | Staging | Production |
|-------|-------------|---------|------------|
| Approval Required | Production deploy needs approval | - | Yes |
| Approved | Deployment was approved | - | Yes |
| Rejected | Deployment was rejected | - | Yes |
| Started | Deployment has begun | Yes | Yes |
| Succeeded | Deployment completed | Yes | Yes |
| Failed | Deployment failed | Yes | Yes |
| Expired | Approval timeout reached | - | Yes |

---

## Telegram Setup

### 1. Create a Bot

1. Open Telegram and search for [@BotFather](https://t.me/botfather)
2. Send `/newbot` and follow the prompts
3. Copy the **HTTP API token** (this is your Bot ID)

### 2. Get Chat ID

**For personal chat:**
1. Search for [@userinfobot](https://t.me/userinfobot)
2. Send any message
3. Copy your **User ID**

**For group chat:**
1. Add your bot to the group
2. Send a message in the group
3. Visit: `https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates`
4. Find the `chat.id` (negative number for groups)

### 3. Install Package

```bash
composer require laravel-notification-channels/telegram
```

### 4. Configure Environment

```env
CD_TELEGRAM_ENABLED=true
CD_TELEGRAM_BOT_ID=123456789:ABCdefGHIjklMNOpqrsTUVwxyz
CD_TELEGRAM_CHAT_ID=-100123456789
```

### 5. Test

```bash
php artisan tinker
```

```php
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentStarted;

$deployment = Deployment::first();
$deployment->notify(new DeploymentStarted($deployment));
```

---

## Slack Setup

### 1. Create Incoming Webhook

1. Go to [Slack API](https://api.slack.com/apps)
2. Click **Create New App** → **From scratch**
3. Go to **Incoming Webhooks** → Enable
4. Click **Add New Webhook to Workspace**
5. Select a channel and authorize
6. Copy the **Webhook URL**

### 2. Configure Environment

```env
CD_SLACK_ENABLED=true
CD_SLACK_WEBHOOK
CD_SLACK_CHANNEL=#deploys
```

### 3. Test

```bash
php artisan tinker
```

```php
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentSucceeded;

$deployment = Deployment::first();
$deployment->notify(new DeploymentSucceeded($deployment));
```

---

## Message Examples

### Telegram: Approval Required

```
🚀 Production Deploy Request

Environment: production
Version: v1.2.3
Commit: abc1234
Author: developer
Message: Fix critical bug in checkout

[✅ Approve] [❌ Reject]

⏰ Expires in 2 hours
```

### Telegram: Success

```
✅ Deployment Successful

Environment: production
Version: v1.2.3
Commit: abc1234
Author: developer

Duration: 45 seconds
```

### Telegram: Failed

```
❌ Deployment Failed

Environment: staging
Version: develop
Commit: def5678
Author: developer

Exit Code: 1

Check server logs for details.
```

### Slack: Approval Required

Formatted as a warning attachment with:
- Version as title
- Environment, Commit, Author, Expires as fields
- Approve/Reject action buttons

### Slack: Success

Formatted as a success (green) attachment with:
- Environment, Duration, Commit as fields

### Slack: Failed

Formatted as an error (red) attachment with:
- Environment, Exit Code, Duration as fields

---

## Customizing Notifications

### Override Notification Classes

Create your own notification classes that extend the package ones:

```php
namespace App\Notifications;

use SageGrids\ContinuousDelivery\Notifications\DeploymentSucceeded as BaseNotification;

class DeploymentSucceeded extends BaseNotification
{
    public function toTelegram(object $notifiable): TelegramMessage
    {
        // Your custom implementation
    }
}
```

### Add Additional Channels

Override the `via()` method to add more channels:

```php
public function via(object $notifiable): array
{
    $channels = parent::via($notifiable);

    // Add email for failures
    if ($this->deployment->isFailed()) {
        $channels[] = 'mail';
    }

    return $channels;
}
```

---

## Troubleshooting

### Telegram Not Working

1. **Bot not in chat:** Ensure the bot is added to the group
2. **Invalid token:** Double-check the bot token format
3. **Privacy mode:** Disable privacy mode in BotFather settings
4. **Rate limits:** Telegram has rate limits; don't spam

```bash
# Test bot connection
curl "https://api.telegram.org/bot<TOKEN>/getMe"
```

### Slack Not Working

1. **Webhook URL expired:** Regenerate if app was reinstalled
2. **Channel archived:** Check if channel still exists
3. **Permissions:** Ensure webhook has channel permissions

```bash
# Test webhook
curl -X POST -H 'Content-type: application/json' \
  --data '{"text":"Test message"}' \
  YOUR_WEBHOOK_URL
```

### Notifications Not Sending

1. Check logs: `storage/logs/laravel.log`
2. Verify environment variables are set
3. Ensure queue worker is running
4. Test with `php artisan tinker`
