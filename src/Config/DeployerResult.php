<?php

namespace SageGrids\ContinuousDelivery\Config;

class DeployerResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly int $exitCode,
        public readonly ?string $releaseName = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailed(): bool
    {
        return ! $this->success;
    }
}
