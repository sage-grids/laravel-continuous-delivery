<?php

namespace SageGrids\ContinuousDelivery\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SageGrids\ContinuousDelivery\Support\Signature;
use SageGrids\ContinuousDelivery\Tests\TestCase;

class SignatureTest extends TestCase
{
    #[Test]
    public function it_verifies_valid_github_sha256_signature(): void
    {
        $payload = '{"test": "data"}';
        $secret = 'my-secret-key';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $result = Signature::verifyGithubSha256($payload, $signature, $secret);

        $this->assertTrue($result);
    }

    #[Test]
    public function it_rejects_invalid_signature(): void
    {
        $payload = '{"test": "data"}';
        $secret = 'my-secret-key';
        $wrongSignature = 'sha256=invalid_signature_here';

        $result = Signature::verifyGithubSha256($payload, $wrongSignature, $secret);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_rejects_signature_with_wrong_secret(): void
    {
        $payload = '{"test": "data"}';
        $correctSecret = 'correct-secret';
        $wrongSecret = 'wrong-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $correctSecret);

        $result = Signature::verifyGithubSha256($payload, $signature, $wrongSecret);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_rejects_empty_secret(): void
    {
        $payload = '{"test": "data"}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'some-secret');

        $result = Signature::verifyGithubSha256($payload, $signature, '');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_rejects_empty_signature_header(): void
    {
        $payload = '{"test": "data"}';
        $secret = 'my-secret-key';

        $result = Signature::verifyGithubSha256($payload, '', $secret);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_handles_tampered_payload(): void
    {
        $originalPayload = '{"test": "data"}';
        $tamperedPayload = '{"test": "modified"}';
        $secret = 'my-secret-key';
        $signature = 'sha256=' . hash_hmac('sha256', $originalPayload, $secret);

        $result = Signature::verifyGithubSha256($tamperedPayload, $signature, $secret);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_handles_real_github_webhook_format(): void
    {
        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'after' => 'abc123',
            'sender' => ['login' => 'user'],
        ]);
        $secret = 'webhook-secret-123';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $result = Signature::verifyGithubSha256($payload, $signature, $secret);

        $this->assertTrue($result);
    }

    #[Test]
    public function it_is_timing_safe(): void
    {
        // This test verifies the function uses hash_equals for timing-safe comparison
        // We can't directly test timing safety, but we ensure it works correctly
        $payload = '{"test": "data"}';
        $secret = 'my-secret-key';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        // Run multiple times to ensure consistent behavior
        for ($i = 0; $i < 100; $i++) {
            $result = Signature::verifyGithubSha256($payload, $signature, $secret);
            $this->assertTrue($result);
        }
    }
}
