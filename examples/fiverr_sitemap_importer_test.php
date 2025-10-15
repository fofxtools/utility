<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrSitemapImporter;

$start = microtime(true);

$importer = new FiverrSitemapImporter();
$importer->setBatchSize(500);

$importer->setCategoriesSitemapPath(__DIR__ . '/../resources/sitemap_categories.xml');
$stats = $importer->importCategories();
print_r($stats);

$importer->setTagsSitemapPath(__DIR__ . '/../resources/sitemap_tags.xml');
$stats = $importer->importTags();
print_r($stats);

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
