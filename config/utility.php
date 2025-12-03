<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Determines which database connection the utility uses by default.
    | Override via the UTILITY_DATABASE_CONNECTION environment variable.
    |
    */

    'database_connection' => env('UTILITY_DATABASE_CONNECTION', 'sqlite_memory'),

    /*
    |--------------------------------------------------------------------------
    | WordPress API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for WordPress REST API integration.
    | Used by the WordPress PHP SDK for authentication and connection.
    |
    */

    'wordpress' => [
        'site_url'     => env('UTILITY_WP_SITE_URL'),
        'username'     => env('UTILITY_WP_USERNAME'),
        'app_password' => env('UTILITY_WP_APP_PASSWORD'),
    ],
];
