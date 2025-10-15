<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\AmazonProductPageParser;
use Illuminate\Support\Facades\DB;

$start = microtime(true);

$parser = new AmazonProductPageParser();

// Get distinct keywords from dataforseo_merchant_amazon_products_listings
// Note: The table is an external dependency from the fofx/api-cache library. Make sure it exists first.
echo "== Fetching Keywords ==\n";
$keywords = DB::table('dataforseo_merchant_amazon_products_listings')
    ->select('keyword', 'location_code', 'language_code', 'device')
    ->distinct()
    ->get();

echo 'Found ' . count($keywords) . " distinct keyword combinations\n\n";

// Process each keyword
$totalInserted = 0;
$totalSkipped  = 0;

foreach ($keywords as $keywordRow) {
    echo "Processing: {$keywordRow->keyword} (location: {$keywordRow->location_code}, lang: {$keywordRow->language_code}, device: {$keywordRow->device})\n";

    $stats = $parser->insertAmazonKeywordsStatsRow(
        $keywordRow->keyword,
        $keywordRow->location_code,
        $keywordRow->language_code,
        $keywordRow->device
    );

    $totalInserted += $stats['inserted'];
    $totalSkipped += $stats['skipped'];
    print_r($stats);
}

echo "\n== Summary ==\n";
echo "Total inserted: {$totalInserted}\n";
echo "Total skipped: {$totalSkipped}\n";

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
