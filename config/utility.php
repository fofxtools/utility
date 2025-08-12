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
];
