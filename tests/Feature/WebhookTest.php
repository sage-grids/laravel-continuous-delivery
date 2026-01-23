<?php

namespace SageGrids\ContinuousDelivery\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Jobs\RunDeployJob;
use SageGrids\ContinuousDelivery\Models\Deployment;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class WebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_rejects_request_without_signature(): void
    {
        $response = $this->postJson('/api/deploy/github', []);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    #[Test]
    public function it_rejects_request_with_invalid_signature(): void
    {
        $payload = $this->createGithubPushPayload();

        $response = $this->postJson('/api/deploy/github', $payload, [
            'X-Hub-Signature-256' => 'sha256=invalid',
            'X-GitHub-Event' => 'push',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    #[Test]
    public function it_accepts_valid_push_webhook_for_configured_branch(): void
    {
        $payload = $this->createGithubPushPayload('develop');
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'test-delivery-id',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'message',
            'deployment_id',
            'status',
        ]);

        $this->assertEquals('queued', $response->json('status'));
        Queue::assertPushed(RunDeployJob::class);
    }

    #[Test]
    public function it_ignores_push_for_unconfigured_branch(): void
    {
        $payload = $this->createGithubPushPayload('feature/some-feature');
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'push',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Branch not configured']);
        Queue::assertNotPushed(RunDeployJob::class);
    }

    #[Test]
    public function it_creates_pending_approval_for_release(): void
    {
        $payload = $this->createGithubReleasePayload('v1.0.0');
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'release',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'message',
            'deployment_id',
            'status',
            'approve_url',
            'reject_url',
            'expires_at',
        ]);

        $this->assertEquals('pending_approval', $response->json('status'));
        Queue::assertNotPushed(RunDeployJob::class);

        $deployment = Deployment::where('uuid', $response->json('deployment_id'))->first();
        $this->assertNotNull($deployment);
        $this->assertEquals('production', $deployment->environment);
        $this->assertNotNull($deployment->approval_token);
    }

    #[Test]
    public function it_ignores_non_published_release_actions(): void
    {
        $payload = $this->createGithubReleasePayload('v1.0.0');
        $payload['action'] = 'created'; // Not 'published'
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'release',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Release action ignored']);
    }

    #[Test]
    public function it_responds_to_ping_event(): void
    {
        $payload = ['zen' => 'Keep it simple'];
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'pong']);
    }

    #[Test]
    public function it_ignores_unknown_events(): void
    {
        $payload = ['test' => 'data'];
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'issues',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Event ignored']);
    }

    #[Test]
    public function it_rejects_when_active_deployment_exists(): void
    {
        // Create an active deployment
        Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'existing123',
            'status' => Deployment::STATUS_RUNNING,
        ]);

        $payload = $this->createGithubPushPayload('develop');
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'push',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(409);
        $response->assertJsonStructure(['error', 'active_deployment']);
    }

    #[Test]
    public function it_filters_by_repository_when_configured(): void
    {
        config(['continuous-delivery.github.only_repo_full_name' => 'correct/repo']);

        $payload = $this->createGithubPushPayload('develop', 'abc123', 'user', 'wrong/repo');
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'push',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Repository not configured']);
    }

    #[Test]
    public function it_allows_matching_repository(): void
    {
        config(['continuous-delivery.github.only_repo_full_name' => 'owner/repo']);

        $payload = $this->createGithubPushPayload('develop', 'abc123', 'user', 'owner/repo');
        $payloadJson = json_encode($payload);
        $signature = $this->generateGithubSignature($payloadJson, 'test-secret');

        $response = $this->call('POST', '/api/deploy/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => 'push',
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertStatus(202);
    }

    #[Test]
    public function it_returns_deployment_status(): void
    {
        $deployment = Deployment::create([
            'environment' => 'staging',
            'trigger_type' => 'branch_push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'author' => 'testuser',
            'status' => Deployment::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'duration_seconds' => 300,
            'exit_code' => 0,
        ]);

        $response = $this->getJson("/api/deploy/status/{$deployment->uuid}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'uuid',
            'environment',
            'trigger',
            'status',
            'commit',
            'author',
            'created_at',
            'started_at',
            'completed_at',
            'duration',
            'exit_code',
        ]);
        $response->assertJson([
            'status' => 'success',
            'environment' => 'staging',
        ]);
    }

    #[Test]
    public function it_returns_404_for_unknown_deployment(): void
    {
        $response = $this->getJson('/api/deploy/status/unknown-uuid');

        $response->assertStatus(404);
    }
}
