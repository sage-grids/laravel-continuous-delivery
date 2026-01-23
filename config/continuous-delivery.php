<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Route Configuration
    |--------------------------------------------------------------------------
    */
    'route' => [
        'path' => env('CD_WEBHOOK_PATH', '/deploy/github'),
        'middleware' => ['api', 'throttle:10,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Integration
    |--------------------------------------------------------------------------
    */
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET', ''),
        'only_repo_full_name' => env('GITHUB_REPO_FULL_NAME', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Isolated Storage (SQLite)
    |--------------------------------------------------------------------------
    |
    | Deployment records are stored in a separate SQLite database to survive
    | application database refreshes (e.g., migrate:fresh on staging).
    |
    */
    'storage' => [
        'database' => env('CD_DATABASE_PATH', '/var/lib/sage-grids-cd/deployments.sqlite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Configuration
    |--------------------------------------------------------------------------
    |
    | Define deployment environments with their triggers and approval settings.
    |
    | Triggers:
    |   - 'branch': Deploy on push to specified branch
    |   - 'release': Deploy on GitHub release (tag) creation
    |
    */
    'environments' => [
        'staging' => [
            'enabled' => env('CD_STAGING_ENABLED', true),
            'trigger' => 'branch',
            'branch' => env('CD_STAGING_BRANCH', 'develop'),
            'approval_required' => false,
            'envoy_story' => 'staging',
        ],

        'production' => [
            'enabled' => env('CD_PRODUCTION_ENABLED', false),
            'trigger' => 'release',
            'tag_pattern' => env('CD_PRODUCTION_TAG_PATTERN', '/^v\d+\.\d+\.\d+$/'),
            'approval_required' => env('CD_PRODUCTION_APPROVAL', true),
            'approval_timeout_hours' => env('CD_PRODUCTION_APPROVAL_TIMEOUT', 2),
            'envoy_story' => 'production',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'telegram' => [
            'enabled' => env('CD_TELEGRAM_ENABLED', false),
            'bot_token' => env('CD_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('CD_TELEGRAM_CHAT_ID'),
        ],

        'slack' => [
            'enabled' => env('CD_SLACK_ENABLED', false),
            'webhook_url' => env('CD_SLACK_WEBHOOK'),
            'channel' => env('CD_SLACK_CHANNEL', '#deploys'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('CD_QUEUE_CONNECTION'),
        'queue' => env('CD_QUEUE_NAME'),
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
    | Envoy Configuration
    |--------------------------------------------------------------------------
    */
    'envoy' => [
        'binary' => env('CD_ENVOY_BINARY'),
        'path' => env('CD_ENVOY_PATH', base_path('Envoy.blade.php')),
        'timeout' => env('CD_ENVOY_TIMEOUT', 1800), // 30 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Directory
    |--------------------------------------------------------------------------
    |
    | The directory where the application is deployed. Used by Envoy tasks.
    |
    */
    'app_dir' => env('CD_APP_DIR', base_path()),
];
