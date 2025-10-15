<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility;
use Illuminate\Support\Facades\DB;

$start = microtime(true);

$tables = Utility\get_tables();
printf("Tables before table creation: %s\n", json_encode($tables));

// Create test table if it doesn't exist
DB::statement('CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY)');

$tables = Utility\get_tables();

printf("Tables after table creation: %s\n", json_encode($tables));

// Download public suffix list
$path = Utility\download_public_suffix_list();
printf("Public suffix list path: %s\n", $path);

// Extract registrable domain
$domain = Utility\extract_registrable_domain('https://www.example.com');
printf("Registrable domain: %s\n", $domain);

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
