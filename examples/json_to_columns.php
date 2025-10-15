<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\Utility;

$start = microtime(true);

$files = [
    __DIR__ . '/../resources/listing-selected-fields.json',
    __DIR__ . '/../resources/gig-selected-fields.json',
    __DIR__ . '/../resources/seller-profile-selected-fields.json',
];

foreach ($files as $file) {
    $data  = json_decode(file_get_contents($file), true);
    $types = Utility\inspect_json_types($data, delimiter: '__', infer: true);
    echo Utility\types_to_columns($types) . PHP_EOL;
    //print_r($types);
    $paths  = array_keys($types);
    $values = Utility\extract_values_by_paths($data, $paths, delimiter: '__');
    //print_r($values);
}

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
