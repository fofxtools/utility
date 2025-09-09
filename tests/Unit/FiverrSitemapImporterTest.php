<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\FiverrSitemapImporter;
use FOfX\Utility\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FiverrSitemapImporterTest extends TestCase
{
    use RefreshDatabase;

    private function makeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fofx_utility_');
        file_put_contents($path, $content);

        return $path;
    }

    private function makeTempXml(string $xml): string
    {
        return $this->makeTempFile($xml);
    }

    public function testGetSetBatchSize(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setBatchSize(1);
        $this->assertSame(1, $importer->getBatchSize());
        $importer->setBatchSize(0);
        $this->assertSame(1, $importer->getBatchSize(), 'Batch size should clamp to >= 1');
        $importer->setBatchSize(250);
        $this->assertSame(250, $importer->getBatchSize());
    }

    public function testGetSetCategoriesSitemapPath(): void
    {
        $importer = new FiverrSitemapImporter();
        $tmpXml   = $this->makeTempXml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        $importer->setCategoriesSitemapPath($tmpXml);
        $this->assertSame($tmpXml, $importer->getCategoriesSitemapPath());
    }

    public function testGetSetCategoriesTableName(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setCategoriesTableName('abc');
        $this->assertSame('abc', $importer->getCategoriesTableName());
    }

    public function testGetSetCategoriesMigrationPath(): void
    {
        $importer = new FiverrSitemapImporter();
        $path     = __FILE__;
        $importer->setCategoriesMigrationPath($path);
        $this->assertSame($path, $importer->getCategoriesMigrationPath());
    }

    public function testGetSetTagsSitemapPath(): void
    {
        $importer = new FiverrSitemapImporter();
        $tmpXml   = $this->makeTempXml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        $importer->setTagsSitemapPath($tmpXml);
        $this->assertSame($tmpXml, $importer->getTagsSitemapPath());
    }

    public function testGetSetTagsTableName(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setTagsTableName('fiverr_sitemap_tags_test');
        $this->assertSame('fiverr_sitemap_tags_test', $importer->getTagsTableName());
    }

    public function testGetSetTagsMigrationPath(): void
    {
        $importer = new FiverrSitemapImporter();
        $path     = __FILE__;
        $importer->setTagsMigrationPath($path);
        $this->assertSame($path, $importer->getTagsMigrationPath());
    }

    public function testLoadDomParsesValidXml(): void
    {
        $importer = new FiverrSitemapImporter();
        $xmlPath  = $this->makeTempXml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url></urlset>');
        $xpath    = $importer->loadDom($xmlPath);
        $nodes    = $xpath->query('//sm:url');
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length);
    }

    public function testLoadDomThrowsOnInvalidXml(): void
    {
        $importer = new FiverrSitemapImporter();
        $badPath  = $this->makeTempFile('<urlset'); // malformed
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse XML file');
        $importer->loadDom($badPath);
    }

    public function testDeriveSlug(): void
    {
        $importer = new FiverrSitemapImporter();
        $this->assertSame('design', $importer->deriveSlug('https://example.com/categories/graphics/design'));
        $this->assertNull($importer->deriveSlug('https://example.com/'));
        $this->assertSame('a', $importer->deriveSlug('https://example.com/a'));
    }

    public function testParseCategoryIdsFromAlternateHref(): void
    {
        $importer             = new FiverrSitemapImporter();
        [$cat, $sub, $nested] = $importer->parseCategoryIdsFromAlternateHref('https://www.fiverr.com/link?category_id=3&sub_category_id=49&nested_sub_category_id=7');
        $this->assertSame(3, $cat);
        $this->assertSame(49, $sub);
        $this->assertSame(7, $nested);

        [$cat2, $sub2, $nested2] = $importer->parseCategoryIdsFromAlternateHref(null);
        $this->assertNull($cat2);
        $this->assertNull($sub2);
        $this->assertNull($nested2);
    }

    public function testInsertBatchToTable(): void
    {
        $importer = new FiverrSitemapImporter();
        // Create a temp table
        $table = 'tmp_insert_batch';
        Schema::dropIfExists($table);
        Schema::create($table, function ($table) {
            $table->increments('id');
            $table->string('url')->unique();
            $table->string('slug')->nullable();
            $table->float('priority')->nullable();
            $table->string('alternate_href')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('sub_category_id')->nullable();
            $table->integer('nested_sub_category_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('processed_status')->nullable();
        });

        $batch = [
            ['url' => 'https://ex.com/1', 'slug' => '1', 'priority' => null, 'alternate_href' => null, 'category_id' => null, 'sub_category_id' => null, 'nested_sub_category_id' => null, 'created_at' => now(), 'updated_at' => now(), 'processed_at' => null, 'processed_status' => null],
            ['url' => 'https://ex.com/2', 'slug' => '2', 'priority' => 0.9, 'alternate_href' => 'https://ex.com/link?category_id=1', 'category_id' => 1, 'sub_category_id' => null, 'nested_sub_category_id' => null, 'created_at' => now(), 'updated_at' => now(), 'processed_at' => null, 'processed_status' => null],
        ];
        $res = $importer->insertBatchToTable($table, $batch);
        $this->assertSame(2, $res['inserted']);
        $this->assertSame(0, $res['skipped']);

        // Re-insert to test insertOrIgnore skip
        $res2 = $importer->insertBatchToTable($table, $batch);
        $this->assertSame(0, $res2['inserted']);
        $this->assertSame(2, $res2['skipped']);

        $count = DB::table($table)->count();
        $this->assertSame(2, $count);
    }

    public function testInsertBatchToTableWithEmptyBatch(): void
    {
        $importer = new FiverrSitemapImporter();
        $table    = 'tmp_insert_batch_empty';
        Schema::dropIfExists($table);
        Schema::create($table, function ($table) {
            $table->increments('id');
            $table->string('url')->unique();
        });
        $res = $importer->insertBatchToTable($table, []);
        $this->assertSame(0, $res['inserted']);
        $this->assertSame(0, $res['skipped']);
    }

    public function testImportCategoriesThrowsWhenSitemapMissing(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setCategoriesSitemapPath(__DIR__ . '/../../no_such_file.xml');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sitemap XML not found');
        $importer->importCategories();
    }

    public function testImportCategoriesWithFixture(): void
    {
        $importer = new FiverrSitemapImporter();

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <url>
    <loc>https://www.fiverr.com/categories/graphics-design</loc>
    <priority>0.9</priority>
    <xhtml:link rel="alternate" href="https://www.fiverr.com/linker?category_id=3&amp;view=category"/>
  </url>
  <url>
    <loc>https://www.fiverr.com/</loc>
    <priority>1.0</priority>
  </url>
</urlset>
XML;
        $tmp = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapPath($tmp);

        $stats = $importer->importCategories();
        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['with_alternate']);
        $this->assertSame(2, $stats['inserted']);
        $this->assertSame(0, $stats['skipped']);
    }

    public function testImportCategoriesWithEmptyXmlReturnsZeroProcessed(): void
    {
        $importer = new FiverrSitemapImporter();
        $tmp      = $this->makeTempXml('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        $importer->setCategoriesSitemapPath($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['inserted']);
    }

    public function testImportCategoriesSkipsUrlWithoutLoc(): void
    {
        $importer = new FiverrSitemapImporter();
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><priority>0.5</priority></url><url><loc>https://example.com/a</loc></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapPath($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['inserted']);
    }

    public function testImportCategoriesTreatsNonNumericPriorityAsNull(): void
    {
        $importer = new FiverrSitemapImporter();
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc><priority>high</priority></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapPath($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['inserted']);
        $row = DB::table($importer->getCategoriesTableName())->first();
        $this->assertNull($row->priority);
    }

    public function testImportCategoriesHandlesMalformedAlternateHref(): void
    {
        $importer = new FiverrSitemapImporter();
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml"><url><loc>https://example.com/a</loc><xhtml:link rel="alternate" href="not a url"/></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapPath($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['with_alternate']);
        $row = DB::table($importer->getCategoriesTableName())->first();
        $this->assertNull($row->category_id);
        $this->assertNull($row->sub_category_id);
        $this->assertNull($row->nested_sub_category_id);
    }

    public function testImportCategoriesSingleItemBatches(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setBatchSize(1);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
        $tmp = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapPath($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(2, $stats['batches']);
    }

    public function testImportCategoriesExactBatchBoundary(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setBatchSize(2);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
        $tmp = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapPath($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['batches']);
    }

    public function testImportTagsThrowsWhenSitemapMissing(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setTagsSitemapPath(__DIR__ . '/../../no_such_tags.xml');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sitemap XML not found');
        $importer->importTags();
    }

    public function testImportTagsWithFixture(): void
    {
        $importer = new FiverrSitemapImporter();

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://www.fiverr.com/tags/illustration</loc></url>
  <url><loc>https://www.fiverr.com/tags/logo-design</loc></url>
</urlset>
XML;
        $tmp = $this->makeTempXml($xml);
        $importer->setTagsSitemapPath($tmp);

        $stats = $importer->importTags();
        $this->assertSame(2, $stats['processed']);
        $this->assertSame(2, $stats['inserted']);
        $this->assertSame(0, $stats['skipped']);
    }

    public function testImportTagsWithEmptyXmlReturnsZeroProcessed(): void
    {
        $importer = new FiverrSitemapImporter();
        $tmp      = $this->makeTempXml('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        $importer->setTagsSitemapPath($tmp);
        $stats = $importer->importTags();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['inserted']);
    }

    public function testImportTagsSingleItemBatches(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setBatchSize(1);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
        $tmp = $this->makeTempXml($xml);
        $importer->setTagsSitemapPath($tmp);
        $stats = $importer->importTags();
        $this->assertSame(2, $stats['batches']);
    }

    public function testImportTagsExactBatchBoundary(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setBatchSize(2);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
        $tmp = $this->makeTempXml($xml);
        $importer->setTagsSitemapPath($tmp);
        $stats = $importer->importTags();
        $this->assertSame(1, $stats['batches']);
    }
}
