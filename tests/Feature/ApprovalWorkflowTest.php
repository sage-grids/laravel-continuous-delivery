<?php

namespace SageGrids\ContinuousDelivery\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_approves_pending_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewIs('continuous-delivery::approved');

        $deployment->refresh();
        $this->assertEquals(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertNotNull($deployment->approved_by);
        $this->assertNotNull($deployment->approved_at);

        Queue::assertPushed(RunDeployJob::class);
    }

    #[Test]
    public function it_rejects_pending_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/reject/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewIs('continuous-delivery::rejected');

        $deployment->refresh();
        $this->assertEquals(Deployment::STATUS_REJECTED, $deployment->status);
        $this->assertNotNull($deployment->rejected_by);
        $this->assertNotNull($deployment->rejected_at);

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
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
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
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_APPROVED,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
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
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_REJECTED,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
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
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(200);

        $deployment->refresh();
        $this->assertStringStartsWith('ip:', $deployment->approved_by);
    }

    #[Test]
    public function it_accepts_reason_for_rejection(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/reject/{$deployment->approval_token}?reason=Not%20ready");

        $response->assertStatus(200);

        $deployment->refresh();
        $this->assertEquals('Not ready', $deployment->rejection_reason);
    }

    #[Test]
    public function it_uses_default_reason_when_not_provided(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/reject/{$deployment->approval_token}");

        $response->assertStatus(200);

        $deployment->refresh();
        $this->assertEquals('Rejected via web interface', $deployment->rejection_reason);
    }

    #[Test]
    public function approve_view_contains_deployment_info(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/approve/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewHas('deployment', function ($viewDeployment) use ($deployment) {
            return $viewDeployment->uuid === $deployment->uuid;
        });
    }

    #[Test]
    public function rejected_view_contains_deployment_info(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            'approval_expires_at' => now()->addHours(2),
        ]);

        $response = $this->get("/api/deploy/reject/{$deployment->approval_token}");

        $response->assertStatus(200);
        $response->assertViewHas('deployment', function ($viewDeployment) use ($deployment) {
            return $viewDeployment->uuid === $deployment->uuid;
        });
    }
}
