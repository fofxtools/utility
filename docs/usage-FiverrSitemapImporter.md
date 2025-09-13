## FiverrSitemapImporter - Basic Usage

A helper class that imports Fiverr sitemap data (categories and tags) into your database in batches.

After running, check the tables. By default, the tables are:

- fiverr_sitemap_categories
- fiverr_sitemap_tags

See: [`../examples/fiverr_sitemap_importer_test.php`](../examples/fiverr_sitemap_importer_test.php)

```php
use FOfX\Utility\FiverrSitemapImporter;

$importer = new FiverrSitemapImporter();
$importer->setBatchSize(500); // optional (default 100)

// Categories
$importer->setCategoriesSitemapFilename(__DIR__ . '/../resources/sitemap_categories.xml');
$stats = $importer->importCategories();
print_r($stats);

// Tags
$importer->setTagsSitemapFilename(__DIR__ . '/../resources/sitemap_tags.xml');
$stats = $importer->importTags();
print_r($stats);
```