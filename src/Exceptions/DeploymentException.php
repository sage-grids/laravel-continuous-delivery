<?php

namespace SageGrids\ContinuousDelivery\Exceptions;

use RuntimeException;

class DeploymentException extends RuntimeException
{
    public static function appDirNotConfigured(): self
    {
        return new self('CD_APP_DIR is not configured. Set the continuous-delivery.app_dir config value.');
    }

    public static function appDirDoesNotExist(string $path): self
    {
        return new self("App directory does not exist: {$path}");
    }

    public static function appDirNotWritable(string $path): self
    {
        return new self("App directory is not writable: {$path}");
    }

    public static function envoyNotFound(): self
    {
        return new self('Envoy binary not found. Install with: composer require laravel/envoy');
    }

    public static function gitNotInstalled(): self
    {
        return new self('Git is not installed or not in PATH');
    }

    public static function prerequisiteFailed(string $reason): self
    {
        return new self("Deployment prerequisite failed: {$reason}");
    }
}
