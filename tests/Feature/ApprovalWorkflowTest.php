<?php

namespace SageGrids\ContinuousDelivery\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_shows_approval_confirmation_view(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewIs('continuous-delivery::confirm-approval');
        $response->assertViewHas('deployment', $deployment);
    }

    #[Test]
    public function it_shows_rejection_confirmation_view(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/reject/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewIs('continuous-delivery::confirm-rejection');
        $response->assertViewHas('deployment', $deployment);
    }

    #[Test]
    public function it_approves_pending_deployment_via_post(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->post("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewIs('continuous-delivery::approved');

        $deployment->refresh();
        $this->assertEquals(DeployerDeployment::STATUS_QUEUED, $deployment->status);
        $this->assertNotNull($deployment->approved_by);
        $this->assertNotNull($deployment->approved_at);

        Queue::assertPushed(RunDeployJob::class);
    }

    #[Test]
    public function it_rejects_pending_deployment_via_post(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->post("/api/deploy/reject/{$deployment->approval_token}", [
            'reason' => 'Not ready',
        ]);

        $response->assertStatus(200);
        $response->assertViewIs('continuous-delivery::rejected');

        $deployment->refresh();
        $this->assertEquals(DeployerDeployment::STATUS_REJECTED, $deployment->status);
        $this->assertNotNull($deployment->rejected_by);
        $this->assertNotNull($deployment->rejected_at);
        $this->assertEquals('Not ready', $deployment->rejection_reason);

        Queue::assertNotPushed(RunDeployJob::class);
    }

    #[Test]
    public function it_returns_error_for_invalid_token(): void
    {
        $response = $this->get('/api/deploy/approve/invalid-short-token');

        $response->assertStatus(400);
        $response->assertViewIs('continuous-delivery::error');
    }

    #[Test]
    public function it_returns_error_for_nonexistent_token(): void
    {
        $token = str_repeat('a', 64);

        $response = $this->get("/api/deploy/approve/{$token}");

        $response->assertStatus(400);
        $response->assertViewIs('continuous-delivery::error');
    }

    #[Test]
    public function it_returns_error_for_expired_approval(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->subHour(),
        ]);

        $response = $this->get("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(400);
        $response->assertViewIs('continuous-delivery::error');
        $response->assertViewHas('title', 'Approval Expired');
    }

    #[Test]
    public function it_returns_error_when_already_approved(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_QUEUED,
            'approval_token' => str_repeat('0', 64),
            'approved_by' => 'someone@example.com',
            'approved_at' => now(),
        ]);

        $response = $this->get("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(400);
        $response->assertViewIs('continuous-delivery::error');
        $response->assertViewHas('title', 'Cannot Approve');
    }

    #[Test]
    public function it_returns_error_when_rejecting_already_rejected(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_REJECTED,
            'approval_token' => str_repeat('0', 64),
            'rejected_by' => 'someone@example.com',
            'rejected_at' => now(),
        ]);

        $response = $this->get("/api/deploy/reject/{$deployment->approval_token}");

        $response->assertStatus(400);
        $response->assertViewIs('continuous-delivery::error');
        $response->assertViewHas('title', 'Cannot Reject');
    }

    #[Test]
    public function it_records_ip_as_approver_for_unauthenticated_user(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->post("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(200);

        $deployment->refresh();
        $this->assertStringStartsWith('ip:', $deployment->approved_by);
    }
}