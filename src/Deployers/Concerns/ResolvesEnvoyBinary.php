<?php

namespace SageGrids\ContinuousDelivery\Deployers\Concerns;

trait ResolvesEnvoyBinary
{
    /**
     * Get the path to the Envoy binary.
     */
    protected function getEnvoyBinary(): string
    {
        $configBinary = config('continuous-delivery.envoy.binary');

        if ($configBinary && file_exists($configBinary)) {
            return $configBinary;
        }

        // Try common locations
        $locations = [
            base_path('vendor/bin/envoy'),
            '/usr/local/bin/envoy',
            'envoy',
        ];

        foreach ($locations as $location) {
            if ($location === 'envoy' || file_exists($location)) {
                return $location;
            }
        }

        return 'envoy';
    }

    /**
     * Get the path to the Envoy.blade.php file.
     */
    protected function getEnvoyPath(): string
    {
        return config('continuous-delivery.envoy.path', base_path('Envoy.blade.php'));
    }

    /**
     * Get the Envoy timeout in seconds.
     */
    protected function getEnvoyTimeout(): int
    {
        return (int) config('continuous-delivery.envoy.timeout', 1800);
    }
}
