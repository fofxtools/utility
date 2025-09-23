<?php

require_once __DIR__ . '/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;
use FOfX\Helper;
use Illuminate\Support\Arr;

use function FOfX\Utility\ensure_table_exists;

// Increase memory for getAllListingsData()
Helper\minimum_memory_limit('2048M');

$start = microtime(true);

$importer = new FiverrJsonImporter();

// Ensure required tables exist
ensure_table_exists('fiverr_listings', $importer->getFiverrListingsMigrationPath());
ensure_table_exists('fiverr_gigs', $importer->getFiverrGigsMigrationPath());
ensure_table_exists('fiverr_seller_profiles', $importer->getFiverrSellerProfilesMigrationPath());
ensure_table_exists('fiverr_listings_gigs', $importer->getFiverrListingsGigsMigrationPath());
ensure_table_exists('fiverr_listings_stats', $importer->getFiverrListingsStatsMigrationPath());

// Sample input files
$listingsFilePath = __DIR__ . '/../storage/app/private/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.json';
$gigFilePath      = __DIR__ . '/../storage/app/private/25-httpswwwfiverrcomvprosscreate-a-top-quality-retro-vintage-logo-for-youcontext-referrersubcategory-listingsourcepaginationref-ctx-idb46fa114ae314be9926cc4a808c204efpckg-id1pos8conte.json';
$sellerFilePath   = __DIR__ . '/../storage/app/private/33-httpswwwfiverrcomtonigdesign-fiverrcom-browserhtml.json';

echo "\n== Import fiverr_listings from File ==\n";
$stats = $importer->importListingsFromFile($listingsFilePath);
print_r($stats);

echo "\n== Import fiverr_gigs from File ==\n";
$stats = $importer->importGigFromFile($gigFilePath);
print_r($stats);

echo "\n== Import fiverr_seller_profiles from File ==\n";
$stats = $importer->importSellerProfileFromFile($sellerFilePath);
print_r($stats);

// Process listings into gigs
// Note: To re-process all, call $importer->resetListingsProcessed() first.
echo "\n== Process fiverr_listings_gigs from listings JSON ==\n";
//$importer->resetListingsProcessed()
$stats = $importer->processListingsGigsAll();
print_r($stats);

// Process listings into stats
// Note: To re-process all, call $importer->resetListingsStatsProcessed() first.
echo "\n== Process fiverr_listings_stats from listings JSON ==\n";
//$importer->resetListingsStatsProcessed();
$stats = $importer->processListingsStatsAll();
print_r($stats);

// Whether to force cache rebuild after inserting data
$forceCacheRebuild = true;

// Process gigs into stats (must be inserted into fiverr_gigs first)
// Gig pages have to be individually downloaded, then processed into fiverr_gigs
// Note: To re-process all, call $importer->resetListingsGigsStatsProcessed() first.
echo "\n== Process fiverr_listings_stats from gigs JSON ==\n";
echo "== (if downloaded and inserted into fiverr_gigs) ==\n";
//$importer->resetListingsGigsStatsProcessed();
$stats = $importer->processGigsStatsAll(forceCacheRebuild: $forceCacheRebuild);
print_r($stats);

echo "\n== Test getAllListingsData ==\n";
$map      = $importer->getAllListingsData(forceCacheRebuild: $forceCacheRebuild);
$slice    = array_slice($map, 0, 3);
$dot      = Arr::dot($slice);
$sliceDot = array_slice($dot, 0, 10);
print_r($sliceDot);

echo "\n== Test getListingToGigPositionsMap ==\n";
$map   = $importer->getListingToGigPositionsMap(forceCacheRebuild: $forceCacheRebuild);
$slice = array_slice($map, 0, 10, preserve_keys: true);
print_r($slice);

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
