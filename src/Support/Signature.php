<?php

namespace SageGrids\ContinuousDelivery\Support;

class Signature
{
    public static function verifyGithubSha256(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        // Header looks like: "sha256=...."
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
