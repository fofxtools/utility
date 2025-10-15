<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\AmazonBrowseNodeImporter;

$start = microtime(true);

$importer = new AmazonBrowseNodeImporter();

$stats = $importer->importBrowseNodesFromCsv();
print_r($stats);

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
