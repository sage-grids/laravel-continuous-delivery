<?php

namespace SageGrids\ContinuousDelivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SageGrids\ContinuousDelivery\Config\AppRegistry;
use SageGrids\ContinuousDelivery\Models\DeployerDeployment;

class HealthController extends Controller
{
    /**
     * Perform health check.
     */
    public function check(AppRegistry $registry): JsonResponse
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

        // Apps check
        $health['checks']['apps'] = $this->checkApps($registry);

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
            $connection = DeployerDeployment::getDeploymentConnection();
            DB::connection($connection)->getPdo();

            $deploymentCount = DeployerDeployment::count();

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
     * Check apps configuration.
     */
    protected function checkApps(AppRegistry $registry): array
    {
        $apps = $registry->all();

        if (empty($apps)) {
            return [
                'status' => 'unhealthy',
                'error' => 'No apps configured',
            ];
        }

        $appStatuses = [];
        $hasIssues = false;

        foreach ($apps as $key => $app) {
            $status = ['status' => 'healthy'];

            // Check if path exists
            if (! is_dir($app->path)) {
                $status['status'] = 'unhealthy';
                $status['error'] = 'Path does not exist';
                $hasIssues = true;
            } else {
                $status['path'] = $app->path;
                $status['strategy'] = $app->strategy;
                $status['triggers'] = count($app->triggers);
            }

            $appStatuses[$key] = $status;
        }

        return [
            'status' => $hasIssues ? 'unhealthy' : 'healthy',
            'count' => count($apps),
            'apps' => $appStatuses,
        ];
    }

    /**
     * Get deployment statistics.
     */
    protected function getDeploymentStats(): array
    {
        $now = now();

        return [
            'total' => DeployerDeployment::count(),
            'last_24h' => DeployerDeployment::where('created_at', '>=', $now->copy()->subDay())->count(),
            'last_7d' => DeployerDeployment::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'pending_approval' => DeployerDeployment::pending()->count(),
            'active' => DeployerDeployment::active()->count(),
            'by_status' => DeployerDeployment::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'by_app' => DeployerDeployment::query()
                ->selectRaw('app_key, count(*) as count')
                ->groupBy('app_key')
                ->pluck('count', 'app_key')
                ->toArray(),
        ];
    }
}
