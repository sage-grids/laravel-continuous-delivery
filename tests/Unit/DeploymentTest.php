<?php

namespace SageGrids\ContinuousDelivery\Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class DeploymentTest extends TestCase
{
    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        $this->assertNotNull($deployment->uuid);
        $this->assertTrue(Str::isUuid($deployment->uuid));
    }

    #[Test]
    public function it_uses_provided_uuid(): void
    {
        $uuid = (string) Str::uuid();

        $deployment = Deployment::create([
            'uuid' => $uuid,
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        $this->assertEquals($uuid, $deployment->uuid);
    }

    #[Test]
    public function it_correctly_identifies_pending_approval_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_PENDING_APPROVAL]);

        $this->assertTrue($deployment->isPendingApproval());
        $this->assertFalse($deployment->isApproved());
        $this->assertFalse($deployment->isRejected());
        $this->assertFalse($deployment->isComplete());
        $this->assertTrue($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_queued_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_QUEUED]);

        $this->assertTrue($deployment->isQueued());
        $this->assertTrue($deployment->isActive());
        $this->assertFalse($deployment->isComplete());
    }

    #[Test]
    public function it_correctly_identifies_running_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_RUNNING]);

        $this->assertTrue($deployment->isRunning());
        $this->assertTrue($deployment->isActive());
        $this->assertFalse($deployment->isComplete());
    }

    #[Test]
    public function it_correctly_identifies_success_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_SUCCESS]);

        $this->assertTrue($deployment->isSuccess());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_failed_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_FAILED]);

        $this->assertTrue($deployment->isFailed());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_rejected_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_REJECTED]);

        $this->assertTrue($deployment->isRejected());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_expired_status(): void
    {
        $deployment = new Deployment(['status' => Deployment::STATUS_EXPIRED]);

        $this->assertTrue($deployment->isExpired());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_can_approve_pending_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHours(2),
        ]);

        $deployment->approve('test@example.com');

        $this->assertEquals(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertEquals('test@example.com', $deployment->approved_by);
        $this->assertNotNull($deployment->approved_at);
        $this->assertNotNull($deployment->queued_at);
    }

    #[Test]
    public function it_cannot_approve_expired_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($deployment->canBeApproved());
        $this->assertTrue($deployment->hasExpired());

        $this->expectException(\RuntimeException::class);
        $deployment->approve('test@example.com');
    }

    #[Test]
    public function it_can_reject_pending_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);

        $deployment->reject('test@example.com', 'Not ready for release');

        $this->assertEquals(Deployment::STATUS_REJECTED, $deployment->status);
        $this->assertEquals('test@example.com', $deployment->rejected_by);
        $this->assertEquals('Not ready for release', $deployment->rejection_reason);
        $this->assertNotNull($deployment->rejected_at);
    }

    #[Test]
    public function it_cannot_reject_already_approved_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_APPROVED,
        ]);

        $this->assertFalse($deployment->canBeRejected());

        $this->expectException(\RuntimeException::class);
        $deployment->reject('test@example.com');
    }

    #[Test]
    public function it_can_mark_running(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        $deployment->markRunning();

        $this->assertEquals(Deployment::STATUS_RUNNING, $deployment->status);
        $this->assertNotNull($deployment->started_at);
    }

    #[Test]
    public function it_can_mark_success(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        $deployment->markSuccess('Deployment output here');

        $this->assertEquals(Deployment::STATUS_SUCCESS, $deployment->status);
        $this->assertEquals('Deployment output here', $deployment->output);
        $this->assertEquals(0, $deployment->exit_code);
        $this->assertNotNull($deployment->completed_at);
        $this->assertNotNull($deployment->duration_seconds);
    }

    #[Test]
    public function it_can_mark_failed(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        $deployment->markFailed('Error: deployment failed', 1);

        $this->assertEquals(Deployment::STATUS_FAILED, $deployment->status);
        $this->assertEquals('Error: deployment failed', $deployment->output);
        $this->assertEquals(1, $deployment->exit_code);
        $this->assertNotNull($deployment->completed_at);
    }

    #[Test]
    public function it_can_expire_pending_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);

        $deployment->expire();

        $this->assertEquals(Deployment::STATUS_EXPIRED, $deployment->status);
    }

    #[Test]
    public function expire_does_nothing_for_non_pending(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        $deployment->expire();

        $this->assertEquals(Deployment::STATUS_QUEUED, $deployment->status);
    }

    #[Test]
    public function it_computes_short_commit_sha(): void
    {
        $deployment = new Deployment([
            'commit_sha' => 'abc1234567890def',
        ]);

        $this->assertEquals('abc1234', $deployment->short_commit_sha);
    }

    #[Test]
    public function it_computes_duration_for_humans(): void
    {
        $deployment = new Deployment(['duration_seconds' => 45]);
        $this->assertEquals('45 seconds', $deployment->duration_for_humans);

        $deployment = new Deployment(['duration_seconds' => 125]);
        $this->assertEquals('2m 5s', $deployment->duration_for_humans);

        $deployment = new Deployment(['duration_seconds' => null]);
        $this->assertNull($deployment->duration_for_humans);
    }

    #[Test]
    public function it_creates_from_webhook_without_approval(): void
    {
        $payload = [
            'after' => 'abc1234567890',
            'head_commit' => ['message' => 'Test commit'],
            'sender' => ['login' => 'testuser'],
            'repository' => ['full_name' => 'owner/repo'],
        ];

        $deployment = Deployment::createFromWebhook(
            'staging',
            'branch_push',
            'develop',
            $payload,
            requiresApproval: false
        );

        $this->assertEquals('staging', $deployment->environment);
        $this->assertEquals(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertNull($deployment->approval_token);
        $this->assertNull($deployment->approval_expires_at);
        $this->assertNotNull($deployment->queued_at);
    }

    #[Test]
    public function it_creates_from_webhook_with_approval(): void
    {
        $payload = [
            'release' => [
                'target_commitish' => 'abc1234567890',
                'body' => 'Release notes',
            ],
            'sender' => ['login' => 'releaser'],
            'repository' => ['full_name' => 'owner/repo'],
        ];

        $deployment = Deployment::createFromWebhook(
            'production',
            'release',
            'v1.0.0',
            $payload,
            requiresApproval: true,
            approvalTimeoutHours: 4
        );

        $this->assertEquals('production', $deployment->environment);
        $this->assertEquals(Deployment::STATUS_PENDING_APPROVAL, $deployment->status);
        $this->assertNotNull($deployment->approval_token);
        $this->assertEquals(64, strlen($deployment->approval_token));
        $this->assertNotNull($deployment->approval_expires_at);
        $this->assertNull($deployment->queued_at);
    }

    #[Test]
    public function pending_scope_returns_only_pending_approvals(): void
    {
        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);

        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'def456',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        $pending = Deployment::pending()->get();

        $this->assertCount(1, $pending);
        $this->assertEquals(Deployment::STATUS_PENDING_APPROVAL, $pending->first()->status);
    }

    #[Test]
    public function active_scope_returns_active_deployments(): void
    {
        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);

        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'def456',
            'status' => Deployment::STATUS_RUNNING,
        ]);

        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'ghi789',
            'status' => Deployment::STATUS_SUCCESS,
        ]);

        $active = Deployment::active()->get();

        $this->assertCount(2, $active);
    }

    #[Test]
    public function for_environment_scope_filters_by_environment(): void
    {
        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'def456',
            'status' => Deployment::STATUS_QUEUED,
        ]);

        $production = Deployment::forEnvironment('production')->get();

        $this->assertCount(1, $production);
        $this->assertEquals('production', $production->first()->environment);
    }

    #[Test]
    public function expired_scope_returns_expired_pending(): void
    {
        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.1',
            'commit_sha' => 'def456',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHour(),
        ]);

        $expired = Deployment::expired()->get();

        $this->assertCount(1, $expired);
        $this->assertEquals('v1.0.0', $expired->first()->trigger_ref);
    }
}
