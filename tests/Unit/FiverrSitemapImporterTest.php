<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\FiverrSitemapImporter;
use FOfX\Utility\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FiverrSitemapImporterTest extends TestCase
{
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

    // 1) Constructor defaults and overrides
    public function testConstructorDefaults(): void
    {
        $importer = new FiverrSitemapImporter();
        $this->assertFileExists($importer->getCategoriesSitemapFilename());
        $this->assertSame('fiverr_sitemap_categories', $importer->getCategoriesTableName());
        $this->assertFileExists($importer->getCategoriesMigrationFilename());
        $this->assertSame(100, $importer->getBatchSize());
    }

    public function testConstructorOverrides(): void
    {
        $tmpXml   = $this->makeTempXml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml"><url><loc>https://example.com/</loc></url></urlset>');
        $importer = new FiverrSitemapImporter(
            $tmpXml,
            'tmp_table',
            __DIR__ . '/../../does_not_exist.php',
            42
        );
        $this->assertSame($tmpXml, $importer->getCategoriesSitemapFilename());
        $this->assertSame('tmp_table', $importer->getCategoriesTableName());
        $this->assertSame(__DIR__ . '/../../does_not_exist.php', $importer->getCategoriesMigrationFilename());
        $this->assertSame(42, $importer->getBatchSize());
    }

    // 2) Getters/Setters: categoriesSitemapFilename
    public function testGetSetCategoriesSitemapFilename(): void
    {
        $importer = new FiverrSitemapImporter();
        $tmpXml   = $this->makeTempXml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        $importer->setCategoriesSitemapFilename($tmpXml);
        $this->assertSame($tmpXml, $importer->getCategoriesSitemapFilename());
    }

    // 3) Getters/Setters: categoriesTableName
    public function testGetSetCategoriesTableName(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setCategoriesTableName('abc');
        $this->assertSame('abc', $importer->getCategoriesTableName());
    }

    // 4) Getters/Setters: categoriesMigrationFilename
    public function testGetSetCategoriesMigrationFilename(): void
    {
        $importer = new FiverrSitemapImporter();
        $path     = __FILE__;
        $importer->setCategoriesMigrationFilename($path);
        $this->assertSame($path, $importer->getCategoriesMigrationFilename());
    }

    // 5) Getters/Setters: batchSize
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

    // 6) loadDom
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

    // 7) deriveSlug
    public function testDeriveSlug(): void
    {
        $importer = new FiverrSitemapImporter();
        $this->assertSame('design', $importer->deriveSlug('https://example.com/categories/graphics/design'));
        $this->assertNull($importer->deriveSlug('https://example.com/'));
        $this->assertSame('a', $importer->deriveSlug('https://example.com/a'));
    }

    // 8) parseCategoryIdsFromAlternateHref
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

    // 9) insertBatchToTable
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

    public function testEnsureCategoriesTableExistsThrowsWhenMigrationMissing(): void
    {
        $importer = new FiverrSitemapImporter();
        Schema::dropIfExists($importer->getCategoriesTableName());
        $importer->setCategoriesMigrationFilename(__DIR__ . '/../../nope_migration_file.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration file not found');
        $importer->ensureCategoriesTableExists();
    }

    // 10) ensureCategoriesTableExists
    public function testEnsureCategoriesTableExists(): void
    {
        $importer = new FiverrSitemapImporter();
        // Ensure table removed, then create
        Schema::dropIfExists($importer->getCategoriesTableName());
        $this->assertFalse(Schema::hasTable($importer->getCategoriesTableName()));
        $importer->ensureCategoriesTableExists();
        $this->assertTrue(Schema::hasTable($importer->getCategoriesTableName()));
    }

    // 11) importCategories
    public function testImportCategoriesThrowsWhenSitemapMissing(): void
    {
        $importer = new FiverrSitemapImporter();
        $importer->setCategoriesSitemapFilename(__DIR__ . '/../../no_such_file.xml');
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
        $importer->setCategoriesSitemapFilename($tmp);

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
        $importer->setCategoriesSitemapFilename($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['inserted']);
    }

    public function testImportCategoriesSkipsUrlWithoutLoc(): void
    {
        $importer = new FiverrSitemapImporter();
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><priority>0.5</priority></url><url><loc>https://example.com/a</loc></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapFilename($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['inserted']);
    }

    public function testImportCategoriesTreatsNonNumericPriorityAsNull(): void
    {
        $importer = new FiverrSitemapImporter();
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc><priority>high</priority></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapFilename($tmp);
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
        $importer->setCategoriesSitemapFilename($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['with_alternate']);
        $row = DB::table($importer->getCategoriesTableName())->first();
        $this->assertNull($row->category_id);
        $this->assertNull($row->sub_category_id);
        $this->assertNull($row->nested_sub_category_id);
    }

    public function testImportCategoriesSingleItemBatches(): void
    {
        $importer = new FiverrSitemapImporter(batchSize: 1);
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapFilename($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(2, $stats['batches']);
    }

    public function testImportCategoriesExactBatchBoundary(): void
    {
        $importer = new FiverrSitemapImporter(batchSize: 2);
        $xml      = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
        $tmp      = $this->makeTempXml($xml);
        $importer->setCategoriesSitemapFilename($tmp);
        $stats = $importer->importCategories();
        $this->assertSame(1, $stats['batches']);
    }
}
