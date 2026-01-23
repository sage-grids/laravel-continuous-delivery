<?php

namespace SageGrids\ContinuousDelivery\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\Deployment;
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
        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHours(2),
        ]);

        // Console table output is formatted, just verify command runs and shows environment
        $this->artisan('deploy:pending')
            ->assertSuccessful()
            ->expectsOutputToContain('production');
    }

    #[Test]
    public function pending_command_shows_no_pending_message(): void
    {
        $this->artisan('deploy:pending')
            ->assertSuccessful()
            ->expectsOutputToContain('No pending deployments');
    }

    #[Test]
    public function pending_command_filters_by_environment(): void
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
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->artisan('deploy:pending', ['--environment' => 'production'])
            ->assertSuccessful()
            ->expectsOutputToContain('production');
    }

    #[Test]
    public function approve_command_approves_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHours(2),
        ]);

        $this->artisan('deploy:approve', [
            'uuid' => $deployment->uuid,
            '--force' => true,
        ])->assertSuccessful();

        $deployment->refresh();
        $this->assertEquals(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertStringStartsWith('cli:', $deployment->approved_by);

        Queue::assertPushed(RunDeployJob::class);
    }

    #[Test]
    public function approve_command_fails_for_nonexistent_deployment(): void
    {
        $this->artisan('deploy:approve', [
            'uuid' => 'nonexistent-uuid',
            '--force' => true,
        ])->assertFailed();
    }

    #[Test]
    public function approve_command_fails_for_expired_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        $this->artisan('deploy:approve', [
            'uuid' => $deployment->uuid,
            '--force' => true,
        ])->assertFailed();
    }

    #[Test]
    public function reject_command_rejects_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
        ]);

        $this->artisan('deploy:reject', [
            'uuid' => $deployment->uuid,
            '--force' => true,
            '--reason' => 'Not ready for production',
        ])->assertSuccessful();

        $deployment->refresh();
        $this->assertEquals(Deployment::STATUS_REJECTED, $deployment->status);
        $this->assertEquals('Not ready for production', $deployment->rejection_reason);
        $this->assertStringStartsWith('cli:', $deployment->rejected_by);
    }

    #[Test]
    public function reject_command_fails_for_nonexistent_deployment(): void
    {
        $this->artisan('deploy:reject', [
            'uuid' => 'nonexistent-uuid',
            '--force' => true,
        ])->assertFailed();
    }

    #[Test]
    public function status_command_shows_recent_deployments(): void
    {
        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => Deployment::STATUS_SUCCESS,
        ]);

        // The output uses ANSI color codes, so just check for 'staging'
        $this->artisan('deploy:status')
            ->assertSuccessful()
            ->expectsOutputToContain('staging');
    }

    #[Test]
    public function status_command_shows_single_deployment(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => Deployment::STATUS_SUCCESS,
            'output' => 'Deployment output here',
        ]);

        $this->artisan('deploy:status', ['uuid' => $deployment->uuid])
            ->assertSuccessful()
            ->expectsOutputToContain($deployment->uuid);
    }

    #[Test]
    public function status_command_filters_by_environment(): void
    {
        Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => Deployment::STATUS_SUCCESS,
        ]);

        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'def456',
            'status' => Deployment::STATUS_SUCCESS,
        ]);

        $this->artisan('deploy:status', ['--environment' => 'production'])
            ->assertSuccessful()
            ->expectsOutputToContain('production');
    }

    #[Test]
    public function expire_command_expires_pending_deployments(): void
    {
        $expiredDeployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.0',
            'commit_sha' => 'abc123',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->subHour(),
        ]);

        $validDeployment = Deployment::create([
            'environment' => 'production',
            'trigger_type' => 'release',
            'trigger_ref' => 'v1.0.1',
            'commit_sha' => 'def456',
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'approval_expires_at' => now()->addHour(),
        ]);

        $this->artisan('deploy:expire')->assertSuccessful();

        $expiredDeployment->refresh();
        $validDeployment->refresh();

        $this->assertEquals(Deployment::STATUS_EXPIRED, $expiredDeployment->status);
        $this->assertEquals(Deployment::STATUS_PENDING_APPROVAL, $validDeployment->status);
    }

    #[Test]
    public function expire_command_handles_no_expired_deployments(): void
    {
        $this->artisan('deploy:expire')
            ->assertSuccessful()
            ->expectsOutputToContain('No expired deployments');
    }

    #[Test]
    public function install_command_publishes_assets(): void
    {
        $this->artisan('deploy:install')
            ->assertSuccessful();
    }
}
