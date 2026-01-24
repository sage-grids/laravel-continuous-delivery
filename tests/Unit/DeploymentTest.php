<?php

namespace SageGrids\ContinuousDelivery\Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Enums\DeploymentStatus;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class DeploymentTest extends TestCase
{
    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $deployment = $this->createDeployment();

        $this->assertNotNull($deployment->uuid);
        $this->assertTrue(Str::isUuid($deployment->uuid));
    }

    #[Test]
    public function it_uses_provided_uuid(): void
    {
        $uuid = (string) Str::uuid();

        $deployment = $this->createDeployment([
            'uuid' => $uuid,
        ]);

        $this->assertEquals($uuid, $deployment->uuid);
    }

    #[Test]
    public function it_correctly_identifies_pending_approval_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_PENDING_APPROVAL]);

        $this->assertTrue($deployment->isPendingApproval());
        $this->assertFalse($deployment->isApproved());
        $this->assertFalse($deployment->isRejected());
        $this->assertFalse($deployment->isComplete());
        $this->assertTrue($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_queued_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_QUEUED]);

        $this->assertTrue($deployment->isQueued());
        $this->assertTrue($deployment->isActive());
        $this->assertFalse($deployment->isComplete());
    }

    #[Test]
    public function it_correctly_identifies_running_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_RUNNING]);

        $this->assertTrue($deployment->isRunning());
        $this->assertTrue($deployment->isActive());
        $this->assertFalse($deployment->isComplete());
    }

    #[Test]
    public function it_correctly_identifies_success_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_SUCCESS]);

        $this->assertTrue($deployment->isSuccess());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_failed_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_FAILED]);

        $this->assertTrue($deployment->isFailed());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_rejected_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_REJECTED]);

        $this->assertTrue($deployment->isRejected());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_correctly_identifies_expired_status(): void
    {
        $deployment = new DeployerDeployment(['status' => DeployerDeployment::STATUS_EXPIRED]);

        $this->assertTrue($deployment->isExpired());
        $this->assertTrue($deployment->isComplete());
        $this->assertFalse($deployment->isActive());
    }

    #[Test]
    public function it_can_approve_pending_deployment(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHours(2),
        ]);

        $deployment->approve('test@example.com');

        $this->assertEquals(DeploymentStatus::Queued, $deployment->status);
        $this->assertEquals('test@example.com', $deployment->approved_by);
        $this->assertNotNull($deployment->approved_at);
        $this->assertNotNull($deployment->queued_at);
    }

    #[Test]
    public function it_cannot_approve_expired_deployment(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
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
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $deployment->reject('test@example.com', 'Not ready for release');

        $this->assertEquals(DeploymentStatus::Rejected, $deployment->status);
        $this->assertEquals('test@example.com', $deployment->rejected_by);
        $this->assertEquals('Not ready for release', $deployment->rejection_reason);
        $this->assertNotNull($deployment->rejected_at);
    }

    #[Test]
    public function it_cannot_reject_already_approved_deployment(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_APPROVED,
        ]);

        $this->assertFalse($deployment->canBeRejected());

        $this->expectException(\RuntimeException::class);
        $deployment->reject('test@example.com');
    }

    #[Test]
    public function it_can_mark_running(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $deployment->markRunning();

        $this->assertEquals(DeploymentStatus::Running, $deployment->status);
        $this->assertNotNull($deployment->started_at);
    }

    #[Test]
    public function it_can_mark_success(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        $deployment->markSuccess('Deployment output here');

        $this->assertEquals(DeploymentStatus::Success, $deployment->status);
        $this->assertEquals('Deployment output here', $deployment->output);
        $this->assertEquals(0, $deployment->exit_code);
        $this->assertNotNull($deployment->completed_at);
        $this->assertNotNull($deployment->duration_seconds);
    }

    #[Test]
    public function it_can_mark_failed(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        $deployment->markFailed('Error: deployment failed', 1);

        $this->assertEquals(DeploymentStatus::Failed, $deployment->status);
        $this->assertEquals('Error: deployment failed', $deployment->output);
        $this->assertEquals(1, $deployment->exit_code);
        $this->assertNotNull($deployment->completed_at);
    }

    #[Test]
    public function it_can_expire_pending_deployment(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $deployment->expire();

        $this->assertEquals(DeploymentStatus::Expired, $deployment->status);
    }

    #[Test]
    public function expire_does_nothing_for_non_pending(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $deployment->expire();

        $this->assertEquals(DeploymentStatus::Queued, $deployment->status);
    }

    #[Test]
    public function it_computes_short_commit_sha(): void
    {
        $deployment = new DeployerDeployment([
            'commit_sha' => 'abc1234567890def',
        ]);

        $this->assertEquals('abc1234', $deployment->short_commit_sha);
    }

    #[Test]
    public function it_computes_duration_for_humans(): void
    {
        $deployment = new DeployerDeployment(['duration_seconds' => 45]);
        $this->assertEquals('45 seconds', $deployment->duration_for_humans);

        $deployment = new DeployerDeployment(['duration_seconds' => 125]);
        $this->assertEquals('2m 5s', $deployment->duration_for_humans);

        $deployment = new DeployerDeployment(['duration_seconds' => null]);
        $this->assertNull($deployment->duration_for_humans);
    }

    #[Test]
    public function pending_scope_returns_only_pending_approvals(): void
    {
        $this->createDeployment([
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->createDeployment([
            'trigger_ref' => 'develop',
            'commit_sha' => 'def456',
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $pending = DeployerDeployment::pending()->get();

        $this->assertCount(1, $pending);
        $this->assertEquals(DeploymentStatus::PendingApproval, $pending->first()->status);
    }

    #[Test]
    public function active_scope_returns_active_deployments(): void
    {
        $this->createDeployment([
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->createDeployment([
            'trigger_ref' => 'develop',
            'commit_sha' => 'def456',
            'status' => DeployerDeployment::STATUS_RUNNING,
        ]);

        $this->createDeployment([
            'trigger_ref' => 'develop',
            'commit_sha' => 'ghi789',
            'status' => DeployerDeployment::STATUS_SUCCESS,
        ]);

        $active = DeployerDeployment::active()->get();

        $this->assertCount(2, $active);
    }

    #[Test]
    public function for_app_scope_filters_by_app_key(): void
    {
        $this->createDeployment([
            'app_key' => 'app1',
            'app_name' => 'App One',
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $this->createDeployment([
            'app_key' => 'app2',
            'app_name' => 'App Two',
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $app1Deployments = DeployerDeployment::forApp('app1')->get();

        $this->assertCount(1, $app1Deployments);
        $this->assertEquals('app1', $app1Deployments->first()->app_key);
    }

    #[Test]
    public function for_trigger_scope_filters_by_trigger_name(): void
    {
        $this->createDeployment([
            'trigger_name' => 'production',
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $this->createDeployment([
            'trigger_name' => 'staging',
            'status' => DeployerDeployment::STATUS_QUEUED,
        ]);

        $prodDeployments = DeployerDeployment::forTrigger('production')->get();

        $this->assertCount(1, $prodDeployments);
        $this->assertEquals('production', $prodDeployments->first()->trigger_name);
    }

    #[Test]
    public function expired_scope_returns_expired_pending(): void
    {
        $this->createDeployment([
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        $this->createDeployment([
            'trigger_ref' => 'v1.0.1',
            'commit_sha' => 'def456',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHour(),
        ]);

        $expired = DeployerDeployment::expired()->get();

        $this->assertCount(1, $expired);
        $this->assertEquals('v1.0.0', $expired->first()->trigger_ref);
    }
}