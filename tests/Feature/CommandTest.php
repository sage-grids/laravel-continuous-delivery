<?php

namespace SageGrids\ContinuousDelivery\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Enums\DeploymentStatus;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class CommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function pending_command_shows_pending_deployments(): void
    {
        $this->createDeployment([
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHours(2),
        ]);

        $this->artisan('deployer:pending')
            ->assertSuccessful()
            ->expectsOutputToContain('default');
    }

    #[Test]
    public function pending_command_shows_no_pending_message(): void
    {
        $this->artisan('deployer:pending')
            ->assertSuccessful()
            ->expectsOutputToContain('No pending deployments');
    }

    #[Test]
    public function pending_command_filters_by_app(): void
    {
        $this->createDeployment([
            'app_key' => 'app1',
            'app_name' => 'App One',
            'trigger_name' => 'production',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->createDeployment([
            'app_key' => 'app2',
            'app_name' => 'App Two',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->artisan('deployer:pending', ['--app' => 'app1'])
            ->assertSuccessful()
            ->expectsOutputToContain('app1');
    }

    #[Test]
    public function approve_command_approves_deployment(): void
    {
        $deployment = $this->createDeployment([
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.0',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHours(2),
        ]);

        $this->artisan('deployer:approve', [
            'uuid' => $deployment->uuid,
            '--force' => true,
        ])->assertSuccessful();

        $deployment->refresh();
        $this->assertEquals(DeploymentStatus::Queued, $deployment->status);
        $this->assertStringStartsWith('cli:', $deployment->approved_by);

        Queue::assertPushed(RunDeployJob::class);
    }

    #[Test]
    public function approve_command_fails_for_nonexistent_deployment(): void
    {
        $this->artisan('deployer:approve', [
            'uuid' => 'nonexistent-uuid',
            '--force' => true,
        ])->assertFailed();
    }

    #[Test]
    public function approve_command_fails_for_expired_deployment(): void
    {
        $deployment = $this->createDeployment([
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.0',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        $this->artisan('deployer:approve', [
            'uuid' => $deployment->uuid,
            '--force' => true,
        ])->assertFailed();
    }

    #[Test]
    public function reject_command_rejects_deployment(): void
    {
        $deployment = $this->createDeployment([
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.0',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->artisan('deployer:reject', [
            'uuid' => $deployment->uuid,
            '--force' => true,
            '--reason' => 'Not ready for production',
        ])->assertSuccessful();

        $deployment->refresh();
        $this->assertEquals(DeploymentStatus::Rejected, $deployment->status);
        $this->assertEquals('Not ready for production', $deployment->rejection_reason);
        $this->assertStringStartsWith('cli:', $deployment->rejected_by);
    }

    #[Test]
    public function reject_command_fails_for_nonexistent_deployment(): void
    {
        $this->artisan('deployer:reject', [
            'uuid' => 'nonexistent-uuid',
            '--force' => true,
        ])->assertFailed();
    }

    #[Test]
    public function status_command_shows_recent_deployments(): void
    {
        $this->createDeployment([
            'status' => DeployerDeployment::STATUS_SUCCESS,
        ]);

        $this->artisan('deployer:status')
            ->assertSuccessful()
            ->expectsOutputToContain('default');
    }

    #[Test]
    public function status_command_shows_single_deployment(): void
    {
        $deployment = $this->createDeployment([
            'status' => DeployerDeployment::STATUS_SUCCESS,
            'output' => 'Deployment output here',
        ]);

        $this->artisan('deployer:status', ['uuid' => $deployment->uuid])
            ->assertSuccessful()
            ->expectsOutputToContain($deployment->uuid);
    }

    #[Test]
    public function status_command_filters_by_app(): void
    {
        $this->createDeployment([
            'app_key' => 'app1',
            'app_name' => 'App One',
            'status' => DeployerDeployment::STATUS_SUCCESS,
        ]);

        $this->createDeployment([
            'app_key' => 'app2',
            'app_name' => 'App Two',
            'status' => DeployerDeployment::STATUS_SUCCESS,
        ]);

        $this->artisan('deployer:status', ['--app' => 'app1'])
            ->assertSuccessful()
            ->expectsOutputToContain('app1');
    }

    #[Test]
    public function expire_command_expires_pending_deployments(): void
    {
        $expiredDeployment = $this->createDeployment([
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.0',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        $validDeployment = $this->createDeployment([
            'trigger_name' => 'production',
            'trigger_ref' => 'v1.0.1',
            'status' => DeployerDeployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHour(),
        ]);

        $this->artisan('deployer:expire')->assertSuccessful();

        $expiredDeployment->refresh();
        $validDeployment->refresh();

        $this->assertEquals(DeploymentStatus::Expired, $expiredDeployment->status);
        $this->assertEquals(DeploymentStatus::PendingApproval, $validDeployment->status);
    }

    #[Test]
    public function expire_command_handles_no_expired_deployments(): void
    {
        $this->artisan('deployer:expire')
            ->assertSuccessful()
            ->expectsOutputToContain('No expired deployments');
    }

    #[Test]
    public function install_command_publishes_assets(): void
    {
        $this->artisan('deployer:install')
            ->assertSuccessful();
    }
}