<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility;

// Migration file paths
$basePath   = __DIR__ . '/../database/migrations/';
$migrations = [
    [
        'table' => 'tracking_pageviews',
        'file'  => $basePath . '2025_10_20_185122_create_tracking_pageviews_table.php',
    ],
    [
        'table' => 'tracking_pageviews_daily',
        'file'  => $basePath . '2025_10_20_194000_create_tracking_pageviews_daily_table.php',
    ],
];

echo "Ensuring tracking tables exist...\n\n";

foreach ($migrations as $migration) {
    echo "Checking table: {$migration['table']}\n";
    echo "Migration file: {$migration['file']}\n";

    try {
        Utility\ensure_table_exists($migration['table'], $migration['file']);
        echo "✓ Table {$migration['table']} is ready\n\n";
    } catch (\Throwable $e) {
        echo "✗ Error with {$migration['table']}: {$e->getMessage()}\n\n";
        exit(1);
    }
}

echo "All tracking tables are ready!\n";
