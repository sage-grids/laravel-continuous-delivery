<?php

namespace SageGrids\ContinuousDelivery\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApprovalRequired;
use SageGrids\ContinuousDelivery\Notifications\DeploymentApproved;
use SageGrids\ContinuousDelivery\Notifications\DeploymentExpired;
use SageGrids\ContinuousDelivery\Notifications\DeploymentFailed;
use SageGrids\ContinuousDelivery\Notifications\DeploymentRejected;
use SageGrids\ContinuousDelivery\Notifications\DeploymentStarted;
use SageGrids\ContinuousDelivery\Notifications\DeploymentSucceeded;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class NotificationTest extends TestCase
{
    protected DeployerDeployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deployment = DeployerDeployment::create([
            'app_key' => 'default',
            'app_name' => 'Default App',
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'commit_message' => 'Release v1.0.0',
            'author' => 'testuser',
            'repository' => 'owner/repo',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => 'test-token-123',
            'approval_expires_at' => now()->addHours(2),
        ]);
    }

    #[Test]
    public function it_returns_empty_channels_when_notifications_disabled(): void
    {
        config(['continuous-delivery.notifications.telegram.enabled' => false]);
        config(['continuous-delivery.notifications.slack.enabled' => false]);

        $notification = new DeploymentApprovalRequired($this->deployment);
        $channels = $notification->via($this->deployment);

        $this->assertEmpty($channels);
    }

    #[Test]
    public function it_returns_telegram_channel_when_enabled(): void
    {
        config(['continuous-delivery.notifications.telegram.enabled' => true]);
        config(['continuous-delivery.notifications.slack.enabled' => false]);

        $notification = new DeploymentApprovalRequired($this->deployment);
        $channels = $notification->via($this->deployment);

        $this->assertContains('telegram', $channels);
        $this->assertNotContains('slack', $channels);
    }

    #[Test]
    public function it_returns_slack_channel_when_enabled(): void
    {
        config(['continuous-delivery.notifications.telegram.enabled' => false]);
        config(['continuous-delivery.notifications.slack.enabled' => true]);

        $notification = new DeploymentApprovalRequired($this->deployment);
        $channels = $notification->via($this->deployment);

        $this->assertContains('slack', $channels);
        $this->assertNotContains('telegram', $channels);
    }

    #[Test]
    public function it_returns_both_channels_when_both_enabled(): void
    {
        config(['continuous-delivery.notifications.telegram.enabled' => true]);
        config(['continuous-delivery.notifications.slack.enabled' => true]);

        $notification = new DeploymentApprovalRequired($this->deployment);
        $channels = $notification->via($this->deployment);

        $this->assertContains('telegram', $channels);
        $this->assertContains('slack', $channels);
    }

    #[Test]
    public function all_notification_types_can_be_instantiated(): void
    {
        $notifications = [
            new DeploymentApprovalRequired($this->deployment),
            new DeploymentApproved($this->deployment),
            new DeploymentRejected($this->deployment),
            new DeploymentExpired($this->deployment),
            new DeploymentStarted($this->deployment),
            new DeploymentSucceeded($this->deployment),
            new DeploymentFailed($this->deployment),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(\Illuminate\Notifications\Notification::class, $notification);
            $this->assertSame($this->deployment, $notification->deployment);
        }
    }

    #[Test]
    public function deployment_routes_telegram_notification(): void
    {
        config(['continuous-delivery.notifications.telegram.chat_id' => '123456789']);

        $chatId = $this->deployment->routeNotificationForTelegram();

        $this->assertEquals('123456789', $chatId);
    }

    #[Test]
    public function deployment_routes_slack_notification(): void
    {
        config(['continuous-delivery.notifications.slack.webhook_url' => 'https://hooks.slack.com/test']);

        $webhookUrl = $this->deployment->routeNotificationForSlack();

        $this->assertEquals('https://hooks.slack.com/test', $webhookUrl);
    }

    #[Test]
    public function deployment_returns_null_for_unconfigured_telegram(): void
    {
        config(['continuous-delivery.notifications.telegram.chat_id' => null]);

        $chatId = $this->deployment->routeNotificationForTelegram();

        $this->assertNull($chatId);
    }

    #[Test]
    public function deployment_returns_null_for_unconfigured_slack(): void
    {
        config(['continuous-delivery.notifications.slack.webhook_url' => null]);

        $webhookUrl = $this->deployment->routeNotificationForSlack();

        $this->assertNull($webhookUrl);
    }
}
