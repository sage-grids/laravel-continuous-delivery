<?php

namespace SageGrids\ContinuousDelivery\Exceptions;

use InvalidArgumentException;

class InvalidConfigurationException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $appKey,
        public readonly array $errors,
        string $message = ''
    ) {
        if (empty($message)) {
            $message = sprintf(
                "Invalid configuration for app '%s': %s",
                $appKey,
                implode('; ', $errors)
            );
        }

        parent::__construct($message);
    }

    /**
     * Get the app key that had invalid configuration.
     */
    public function getAppKey(): string
    {
        return $this->appKey;
    }

    /**
     * Get the validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
