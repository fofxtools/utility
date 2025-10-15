<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\AmazonProductPageParser;

$start = microtime(true);

$parser = new AmazonProductPageParser();

// Load HTML file
$htmlFile = __DIR__ . '/../resources/amazon_asin_B0FNY2674D.html';
$html     = file_get_contents($htmlFile);

echo "== Parse Product Page ==\n";
$data = $parser->parseAll($html);
print_r($data);

echo "\n== Insert Product ==\n";
$stats = $parser->insertProduct($data);
print_r($stats);

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
