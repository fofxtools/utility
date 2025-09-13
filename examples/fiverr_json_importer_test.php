<?php

require_once __DIR__ . '/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;

use function FOfX\Utility\ensure_table_exists;

$start = microtime(true);

$importer = new FiverrJsonImporter();

// Ensure required tables exist
ensure_table_exists('fiverr_listings', $importer->getFiverrListingsMigrationPath());
ensure_table_exists('fiverr_gigs', $importer->getFiverrGigsMigrationPath());
ensure_table_exists('fiverr_seller_profiles', $importer->getFiverrSellerProfilesMigrationPath());
ensure_table_exists('fiverr_listings_gigs', $importer->getFiverrListingsGigsMigrationPath());
ensure_table_exists('fiverr_listings_stats', $importer->getFiverrListingsStatsMigrationPath());

// Sample input files
$listingsJson = __DIR__ . '/../storage/app/private/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.json';
$gigJson      = __DIR__ . '/../storage/app/private/25-httpswwwfiverrcomvprosscreate-a-top-quality-retro-vintage-logo-for-youcontext-referrersubcategory-listingsourcepaginationref-ctx-idb46fa114ae314be9926cc4a808c204efpckg-id1pos8conte.json';
$sellerJson   = __DIR__ . '/../storage/app/private/33-httpswwwfiverrcomtonigdesign-fiverrcom-browserhtml.json';

echo "\n== Import fiverr_listings from JSON ==\n";
$stats = $importer->importListingsJson($listingsJson);
print_r($stats);

echo "\n== Import fiverr_gigs from JSON ==\n";
$stats = $importer->importGigJson($gigJson);
print_r($stats);

echo "\n== Import fiverr_seller_profiles from JSON ==\n";
$stats = $importer->importSellerProfileJson($sellerJson);
print_r($stats);

// Process listings into gigs
// Note: To re-process all, call $importer->resetListingsProcessed() first.
echo "\n== Process fiverr_listings_gigs from listings JSON ==\n";
$stats = $importer->processListingsGigsAll();
print_r($stats);

// Process listings into stats
// Note: To re-process all, call $importer->resetListingsStatsProcessed() first.
echo "\n== Process fiverr_listings_stats from listings JSON ==\n";
$stats = $importer->processListingsStatsAll();
print_r($stats);

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
