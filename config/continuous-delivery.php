<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    |
    | Define one or more applications to manage. Each app has its own
    | deployment settings, triggers, and notifications.
    |
    | The 'default' app uses the current application. Add more apps
    | to deploy multiple repos from a single installation.
    |
    */
    'apps' => [
        // Default app - self-deploying by default
        'default' => [
            'name' => env('APP_NAME', 'My App'),
            'repository' => env('CD_REPOSITORY'),  // Optional: auto-detected from .git
            'path' => env('CD_APP_PATH', base_path()),

            // Deployment strategy: 'simple' or 'advanced'
            'strategy' => env('CD_STRATEGY', 'simple'),

            // Simple strategy settings (git pull in place)
            'simple' => [
                // No additional config needed for simple mode
            ],

            // Advanced strategy settings (releases + symlinks)
            'advanced' => [
                'releases_path' => 'releases',
                'shared_path' => 'shared',
                'current_link' => 'current',
                'keep_releases' => env('CD_KEEP_RELEASES', 5),
                'shared_dirs' => ['storage'],
                'shared_files' => ['.env'],
            ],

            // Triggers define when and how to deploy
            'triggers' => [
                [
                    'name' => 'staging',
                    'on' => 'push',
                    'branch' => env('CD_STAGING_BRANCH', 'develop'),
                    'auto_deploy' => true,
                    'story' => 'staging',
                ],
                [
                    'name' => 'production',
                    'on' => 'release',
                    'tag_pattern' => '/^v\d+\.\d+\.\d+$/',
                    'auto_deploy' => false,  // Requires approval
                    'approval_timeout' => env('CD_PRODUCTION_APPROVAL_TIMEOUT', 2), // hours
                    'story' => 'production',
                ],
            ],

            // Notifications for this app
            'notifications' => [
                'telegram' => env('CD_TELEGRAM_CHAT_ID'),
                'slack' => env('CD_SLACK_WEBHOOK'),
                'webhook' => env('CD_NOTIFY_WEBHOOK'),
            ],
        ],

        // Example: Additional app (uncomment and configure)
        // 'api-service' => [
        //     'name' => 'API Service',
        //     'repository' => 'git@github.com:org/api-service.git',
        //     'path' => '/var/www/api-service',
        //     'strategy' => 'advanced',
        //     'advanced' => [
        //         'keep_releases' => 3,
        //         'shared_dirs' => ['storage', 'node_modules'],
        //         'shared_files' => ['.env'],
        //     ],
        //     'triggers' => [
        //         ['name' => 'production', 'on' => 'push', 'branch' => 'main', 'auto_deploy' => true, 'story' => 'staging'],
        //     ],
        //     'notifications' => [
        //         'telegram' => env('API_TELEGRAM_CHAT_ID'),
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Route Configuration
    |--------------------------------------------------------------------------
    */
    'route' => [
        'path' => env('CD_WEBHOOK_PATH', '/deploy/github'),
        'prefix' => env('CD_ROUTE_PREFIX', 'api'),
        'middleware' => ['api', 'throttle:10,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Webhook Settings
    |--------------------------------------------------------------------------
    */
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'verify_signature' => env('CD_VERIFY_SIGNATURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Storage
    |--------------------------------------------------------------------------
    |
    | Deployment records are stored in a separate SQLite database to survive
    | application database refreshes (e.g., migrate:fresh on staging).
    |
    | Set 'connection' to 'default' to use the app's database instead.
    |
    */
    'database' => [
        'connection' => env('CD_DATABASE', 'sqlite'),
        'sqlite_path' => env('CD_DATABASE_PATH', storage_path('continuous-delivery/deployments.sqlite')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('CD_QUEUE_CONNECTION'),
        'queue' => env('CD_QUEUE_NAME', 'deployments'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Envoy Settings
    |--------------------------------------------------------------------------
    */
    'envoy' => [
        'binary' => env('CD_ENVOY_BINARY'),
        'path' => env('CD_ENVOY_PATH', base_path('Envoy.blade.php')),
        'timeout' => env('CD_ENVOY_TIMEOUT', 1800), // 30 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval Settings
    |--------------------------------------------------------------------------
    */
    'approval' => [
        'token_length' => 64,
        'auto_expire' => env('CD_AUTO_EXPIRE', true),
        'notify_on_expire' => env('CD_NOTIFY_ON_EXPIRE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Notification Defaults
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'telegram' => [
            'enabled' => env('CD_TELEGRAM_ENABLED', false),
            'bot_token' => env('CD_TELEGRAM_BOT_TOKEN'),
        ],
        'slack' => [
            'enabled' => env('CD_SLACK_ENABLED', false),
            'default_channel' => env('CD_SLACK_CHANNEL', '#deployments'),
            'use_block_kit' => env('CD_SLACK_USE_BLOCK_KIT', true),
        ],
    ],
];
