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

        $response = $this->get($deployment->getApproveUrl());

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

        $response = $this->get($deployment->getRejectUrl());

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

        $url = \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.approve',
            ['token' => $deployment->approval_token],
            $deployment->approval_expires_at
        );

        $response = $this->post($url);

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

        $url = \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.reject',
            ['token' => $deployment->approval_token],
            $deployment->approval_expires_at
        );

        $response = $this->post($url, [
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
        // Testing unsigned request which now returns 'Invalid Link' instead of checking token
        $response = $this->get('/api/deploy/approve/invalid-short-token');

        $response->assertStatus(400);
        $response->assertViewHas('title', 'Invalid Link');
    }

    #[Test]
    public function it_returns_error_for_nonexistent_token(): void
    {
        $token = str_repeat('a', 64);
        
        // We must sign it to pass the signature check, then it will fail finding the token
        $url = \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.approve.confirm',
            ['token' => $token]
        );

        $response = $this->get($url);

        $response->assertStatus(400);
        $response->assertViewHas('title', 'Deployment Not Found');
    }

    #[Test]
    public function it_returns_error_for_expired_approval(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_token' => str_repeat('0', 64),
            'approval_expires_at' => now()->subHour(),
        ]);

        // URL::signedRoute will also check expiry if we pass it in the route params or as argument.
        // But if the route itself is signed with an expiry time, the signature check fails if it is expired.
        // We want to test that the *controller* logic catches it if it passes signature but model logic says expired.
        // However, if we sign the route using the deployment's expired time, hasValidSignature() will return false.
        
        // So we sign it with a FUTURE time so it passes signature check,
        // but the deployment itself is expired in DB.
        
        $url = \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.approve.confirm',
            ['token' => $deployment->approval_token],
            now()->addHour() // Signature valid
        );

        $response = $this->get($url);

        $response->assertStatus(400);
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

        $response = $this->get($deployment->getApproveUrl());

        $response->assertStatus(400);
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

        $response = $this->get($deployment->getRejectUrl());

        $response->assertStatus(400);
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

        $url = \Illuminate\Support\Facades\URL::signedRoute(
            'continuous-delivery.approve',
            ['token' => $deployment->approval_token],
            $deployment->approval_expires_at
        );

        $response = $this->post($url);

        $response->assertStatus(200);

        $deployment->refresh();
        $this->assertStringStartsWith('ip:', $deployment->approved_by);
    }
}