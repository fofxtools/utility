<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrSitemapImporter;

$importer = new FiverrSitemapImporter();
$importer->setBatchSize(500);

$importer->setCategoriesSitemapFilename(__DIR__ . '/../resources/sitemap_categories.xml');
$stats = $importer->importCategories();
print_r($stats);

$importer->setTagsSitemapFilename(__DIR__ . '/../resources/sitemap_tags.xml');
$stats = $importer->importTags();
print_r($stats);
