<?php

namespace SageGrids\ContinuousDelivery\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SageGrids\ContinuousDelivery\ContinuousDeliveryServiceProvider;

abstract class TestCase extends Orchestra
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ContinuousDeliveryServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in memory for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Disable isolated database for testing (use the test database)
        $app['config']->set('continuous-delivery.database.connection', 'testing');

        // Disable throttling for tests
        $app['config']->set('continuous-delivery.route.middleware', ['api']);

        // Configure default settings
        $app['config']->set('continuous-delivery.github.webhook_secret', 'test-secret');
        $app['config']->set('continuous-delivery.github.only_repo_full_name', null);

        $app['config']->set('continuous-delivery.apps', [
            'default' => [
                'name' => 'Default App',
                'repository' => 'owner/repo',
                'path' => '/var/www/default',
                'strategy' => 'simple',
                'triggers' => [
                    [
                        'name' => 'staging',
                        'on' => 'push',
                        'branch' => 'develop',
                        'auto_deploy' => true,
                        'story' => 'staging',
                    ],
                    [
                        'name' => 'production',
                        'on' => 'release',
                        'tag_pattern' => '/^v\d+\.\d+\.\d+$/',
                        'auto_deploy' => false,
                        'approval_timeout' => 2,
                        'story' => 'production',
                    ],
                ],
            ],
        ]);

        $app['config']->set('continuous-delivery.notifications.telegram.enabled', false);
        $app['config']->set('continuous-delivery.notifications.slack.enabled', false);

        $app['config']->set('continuous-delivery.queue.connection', null);
        $app['config']->set('continuous-delivery.queue.queue', null);
    }

    protected function setUpDatabase(): void
    {
        // Migrations are loaded via defineDatabaseMigrations
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function createDeployment(array $attributes = []): \SageGrids\ContinuousDelivery\Models\DeployerDeployment
    {
        return \SageGrids\ContinuousDelivery\Models\DeployerDeployment::create(array_merge([
            'app_key' => 'default',
            'app_name' => 'Default App',
            'trigger_name' => 'staging',
            'trigger_type' => 'push',
            'trigger_ref' => 'develop',
            'commit_sha' => 'abc1234567890',
            'status' => 'queued',
            'strategy' => 'simple',
            'envoy_story' => 'staging',
        ], $attributes));
    }

    protected function createGithubPushPayload(
        string $branch = 'develop',
        string $commitSha = 'abc1234567890',
        string $author = 'testuser',
        string $repo = 'owner/repo'
    ): array {
        return [
            'ref' => "refs/heads/{$branch}",
            'after' => $commitSha,
            'head_commit' => [
                'message' => 'Test commit message',
            ],
            'sender' => [
                'login' => $author,
            ],
            'repository' => [
                'full_name' => $repo,
            ],
        ];
    }

    protected function createGithubReleasePayload(
        string $tag = 'v1.0.0',
        string $author = 'testuser',
        string $repo = 'owner/repo'
    ): array {
        return [
            'action' => 'published',
            'release' => [
                'tag_name' => $tag,
                'target_commitish' => 'abc1234567890',
                'body' => 'Release notes',
            ],
            'sender' => [
                'login' => $author,
            ],
            'repository' => [
                'full_name' => $repo,
            ],
        ];
    }

    protected function generateGithubSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }
}
