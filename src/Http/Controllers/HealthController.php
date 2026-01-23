<?php

namespace SageGrids\ContinuousDelivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SageGrids\ContinuousDelivery\Models\Deployment;

class HealthController extends Controller
{
    /**
     * Perform health check.
     */
    public function check(): JsonResponse
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => [],
        ];

        // Database check
        $health['checks']['database'] = $this->checkDatabase();

        // Envoy binary check
        $health['checks']['envoy'] = $this->checkEnvoy();

        // App directory check
        $health['checks']['app_dir'] = $this->checkAppDir();

        // Deployment stats
        $health['stats'] = $this->getDeploymentStats();

        // Determine overall health status
        $failedChecks = collect($health['checks'])->filter(fn ($check) => $check['status'] === 'unhealthy');
        if ($failedChecks->isNotEmpty()) {
            $health['status'] = 'unhealthy';
        }

        $statusCode = $health['status'] === 'healthy' ? 200 : 503;

        return response()->json($health, $statusCode);
    }

    /**
     * Check database connectivity.
     */
    protected function checkDatabase(): array
    {
        try {
            $connection = Deployment::getDeploymentConnection();
            DB::connection($connection)->getPdo();

            $deploymentCount = Deployment::count();

            return [
                'status' => 'healthy',
                'connection' => $connection,
                'deployment_count' => $deploymentCount,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Envoy binary availability.
     */
    protected function checkEnvoy(): array
    {
        $configPath = config('continuous-delivery.envoy.binary');
        $vendorPath = base_path('vendor/bin/envoy');

        if ($configPath && is_executable($configPath)) {
            return [
                'status' => 'healthy',
                'path' => $configPath,
                'source' => 'config',
            ];
        }

        if (is_executable($vendorPath)) {
            return [
                'status' => 'healthy',
                'path' => $vendorPath,
                'source' => 'vendor',
            ];
        }

        return [
            'status' => 'unhealthy',
            'error' => 'Envoy binary not found',
        ];
    }

    /**
     * Check app directory configuration.
     */
    protected function checkAppDir(): array
    {
        $appDir = config('continuous-delivery.app_dir');

        if (!$appDir) {
            return [
                'status' => 'healthy',
                'message' => 'Not configured (optional)',
            ];
        }

        if (!is_dir($appDir)) {
            return [
                'status' => 'unhealthy',
                'error' => 'Directory does not exist',
                'path' => $appDir,
            ];
        }

        if (!is_writable($appDir)) {
            return [
                'status' => 'unhealthy',
                'error' => 'Directory is not writable',
                'path' => $appDir,
            ];
        }

        return [
            'status' => 'healthy',
            'path' => $appDir,
            'writable' => true,
        ];
    }

    /**
     * Get deployment statistics.
     */
    protected function getDeploymentStats(): array
    {
        $now = now();

        return [
            'total' => Deployment::count(),
            'last_24h' => Deployment::where('created_at', '>=', $now->copy()->subDay())->count(),
            'last_7d' => Deployment::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'pending_approval' => Deployment::pending()->count(),
            'active' => Deployment::active()->count(),
            'by_status' => Deployment::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'by_environment' => Deployment::query()
                ->selectRaw('environment, count(*) as count')
                ->groupBy('environment')
                ->pluck('count', 'environment')
                ->toArray(),
        ];
    }
}
