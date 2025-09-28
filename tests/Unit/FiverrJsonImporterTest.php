<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\FiverrJsonImporter;
use FOfX\Utility\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class FiverrJsonImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_getSetExcludedColumns(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setExcludedColumns(['foo', 'bar']);
        $this->assertSame(['foo', 'bar'], $importer->getExcludedColumns());
    }

    public function test_getSetJsonFlags(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setJsonFlags(JSON_UNESCAPED_SLASHES);
        $this->assertSame(JSON_UNESCAPED_SLASHES, $importer->getJsonFlags());
    }

    public function test_getSetColumnPathDelimiter(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setColumnPathDelimiter('::');
        $this->assertSame('::', $importer->getColumnPathDelimiter());
    }

    public function test_getSetDefaultSourceFormat(): void
    {
        $importer = new FiverrJsonImporter();

        // Test default value
        $this->assertNull($importer->getDefaultSourceFormat());

        // Test setting and getting values
        $importer->setDefaultSourceFormat('category');
        $this->assertSame('category', $importer->getDefaultSourceFormat());

        $importer->setDefaultSourceFormat('search');
        $this->assertSame('search', $importer->getDefaultSourceFormat());

        $importer->setDefaultSourceFormat('tag');
        $this->assertSame('tag', $importer->getDefaultSourceFormat());

        // Test setting back to null
        $importer->setDefaultSourceFormat(null);
        $this->assertNull($importer->getDefaultSourceFormat());
    }

    public function test_table_name_accessors(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setFiverrListingsTable('x_listings');
        $importer->setFiverrGigsTable('x_gigs');
        $importer->setFiverrSellerProfilesTable('x_sellers');
        $importer->setFiverrListingsGigsTable('x_listings_gigs');
        $importer->setFiverrListingsStatsTable('x_stats');

        $this->assertSame('x_listings', $importer->getFiverrListingsTable());
        $this->assertSame('x_gigs', $importer->getFiverrGigsTable());
        $this->assertSame('x_sellers', $importer->getFiverrSellerProfilesTable());
        $this->assertSame('x_listings_gigs', $importer->getFiverrListingsGigsTable());
        $this->assertSame('x_stats', $importer->getFiverrListingsStatsTable());
    }

    public function test_migration_path_accessors(): void
    {
        $importer = new FiverrJsonImporter();

        $importer->setFiverrListingsMigrationPath('/tmp/a.php');
        $importer->setFiverrGigsMigrationPath('/tmp/b.php');
        $importer->setFiverrSellerProfilesMigrationPath('/tmp/c.php');
        $importer->setFiverrListingsGigsMigrationPath('/tmp/d.php');
        $importer->setFiverrListingsStatsMigrationPath('/tmp/e.php');

        $this->assertSame('/tmp/a.php', $importer->getFiverrListingsMigrationPath());
        $this->assertSame('/tmp/b.php', $importer->getFiverrGigsMigrationPath());
        $this->assertSame('/tmp/c.php', $importer->getFiverrSellerProfilesMigrationPath());
        $this->assertSame('/tmp/d.php', $importer->getFiverrListingsGigsMigrationPath());
        $this->assertSame('/tmp/e.php', $importer->getFiverrListingsStatsMigrationPath());
    }

    public function test_getTableColumns_returns_columns(): void
    {
        Schema::dropIfExists('tmp_cols');
        Schema::create('tmp_cols', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        $importer = new FiverrJsonImporter();
        $columns  = $importer->getTableColumns('tmp_cols');

        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('bio', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_getTextColumns_identifies_text_like_columns_for_sqlite(): void
    {
        Schema::dropIfExists('tmp_text');
        Schema::create('tmp_text', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('bio')->nullable();
            $table->mediumText('summary')->nullable();
            $table->longText('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $importer = new FiverrJsonImporter();
        $textCols = $importer->getTextColumns('tmp_text');

        $this->assertContains('bio', $textCols);
        $this->assertContains('summary', $textCols);
        $this->assertContains('description', $textCols);
        $this->assertContains('notes', $textCols);
        $this->assertNotContains('name', $textCols);
    }

    public function test_removeExcludedColumns_filters_out_auto_managed(): void
    {
        $importer = new FiverrJsonImporter();
        $cols     = ['id', 'name', 'created_at', 'bio', 'updated_at', 'processed_at', 'processed_status'];
        $filtered = $importer->removeExcludedColumns($cols);

        $this->assertSame(['name', 'bio'], $filtered);
    }

    public function test_removeExcludedColumns_respects_custom_excluded(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setExcludedColumns(['name', 'custom_field']);
        $cols     = ['id', 'name', 'created_at', 'bio', 'updated_at', 'processed_at', 'processed_status', 'custom_field'];
        $filtered = $importer->removeExcludedColumns($cols);

        $this->assertSame(['id', 'created_at', 'bio', 'updated_at', 'processed_at', 'processed_status'], $filtered);
    }

    public function test_extractAndEncode_maps_and_encodes_text_columns(): void
    {
        $importer = new FiverrJsonImporter();

        $data = [
            'user' => [
                'name'    => 'Alice',
                'profile' => ['bio' => ['k' => 'v']],
            ],
            'processed_status' => ['state' => 'ok'],
        ];
        $columns  = ['user__name', 'user__profile__bio', 'processed_status'];
        $textCols = ['user__profile__bio', 'processed_status']; // processed_status should NOT be encoded

        $mapped = $importer->extractAndEncode($data, $columns, $textCols);

        $this->assertSame('Alice', $mapped['user__name']);
        $this->assertIsString($mapped['user__profile__bio']);
        $this->assertSame(json_encode(['k' => 'v'], $importer->getJsonFlags()), $mapped['user__profile__bio']);
        $this->assertIsArray($mapped['processed_status']); // not JSON-encoded
    }

    public function test_extractAndEncode_source_format_no_injection_when_default_null(): void
    {
        $importer = new FiverrJsonImporter();

        // When defaultSourceFormat is null, no injection should occur
        $data     = ['user__name' => 'Alice'];
        $columns  = ['user__name', 'source_format'];
        $textCols = [];

        $mapped = $importer->extractAndEncode($data, $columns, $textCols);
        $this->assertArrayHasKey('source_format', $mapped);
        $this->assertNull($mapped['source_format']);
    }

    public function test_extractAndEncode_source_format_injects_when_default_set(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setDefaultSourceFormat('category');

        // defaultSourceFormat set - auto-inject when source_format column exists but is null
        $data     = ['user__name' => 'Alice'];
        $columns  = ['user__name', 'source_format'];
        $textCols = [];

        $mapped = $importer->extractAndEncode($data, $columns, $textCols);
        $this->assertArrayHasKey('source_format', $mapped);
        $this->assertSame('category', $mapped['source_format']);
    }

    public function test_extractAndEncode_source_format_preserves_existing_value(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setDefaultSourceFormat('category');

        // source_format already in data - don't override
        $data     = ['user__name' => 'Bob', 'source_format' => 'search'];
        $columns  = ['user__name', 'source_format'];
        $textCols = [];

        $mapped = $importer->extractAndEncode($data, $columns, $textCols);
        $this->assertSame('search', $mapped['source_format']); // should keep original value
    }

    public function test_extractAndEncode_source_format_no_injection_when_column_not_listed(): void
    {
        $importer = new FiverrJsonImporter();
        $importer->setDefaultSourceFormat('category');

        // source_format column not in columns list - no injection
        $data     = ['user__name' => 'Alice'];
        $columns  = ['user__name']; // source_format not included
        $textCols = [];

        $mapped = $importer->extractAndEncode($data, $columns, $textCols);
        $this->assertArrayNotHasKey('source_format', $mapped);
    }

    public function test_normalizeRows_wraps_assoc_row(): void
    {
        $importer = new FiverrJsonImporter();
        $refClass = new \ReflectionClass(FiverrJsonImporter::class);
        $method   = $refClass->getMethod('normalizeRows');
        $method->setAccessible(true);

        $assoc   = ['a' => 1];
        $wrapped = $method->invoke($importer, $assoc);
        $this->assertIsArray($wrapped);
        $this->assertCount(1, $wrapped);
        $this->assertSame($assoc, $wrapped[0]);
    }

    public function test_normalizeRows_keeps_list_as_is(): void
    {
        $importer = new FiverrJsonImporter();
        $refClass = new \ReflectionClass(FiverrJsonImporter::class);
        $method   = $refClass->getMethod('normalizeRows');
        $method->setAccessible(true);

        $list = [['a' => 1], ['a' => 2]];
        $same = $method->invoke($importer, $list);
        $this->assertSame($list, $same);
    }

    public function test_insertRows_inserts_and_timestamps_and_ignores_duplicates(): void
    {
        Schema::dropIfExists('tmp_insert');
        Schema::create('tmp_insert', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        $importer = new FiverrJsonImporter();

        // Single row insert
        $res1 = $importer->insertRows('tmp_insert', ['name' => 'A', 'meta' => 'x']);
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res1);
        $rowA = DB::table('tmp_insert')->where('name', 'A')->first();
        $this->assertNotNull($rowA->created_at);
        $this->assertNotNull($rowA->updated_at);

        // Duplicate insert ignored
        $res2 = $importer->insertRows('tmp_insert', ['name' => 'A']);
        $this->assertSame(['inserted' => 0, 'skipped' => 1], $res2);
        $count = DB::table('tmp_insert')->count();
        $this->assertSame(1, $count);

        // Batch insert with one new and one duplicate
        $res3 = $importer->insertRows('tmp_insert', [
            ['name' => 'B'],
            ['name' => 'A'], // duplicate
        ]);
        $this->assertSame(['inserted' => 1, 'skipped' => 1], $res3);
        $count = DB::table('tmp_insert')->count();
        $this->assertSame(2, $count);

        $rowB = DB::table('tmp_insert')->where('name', 'B')->first();
        $this->assertNotNull($rowB->created_at);
        $this->assertNotNull($rowB->updated_at);
    }

    public function test_insertRows_returns_zero_on_empty_input(): void
    {
        Schema::dropIfExists('tmp_insert_empty');
        Schema::create('tmp_insert_empty', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $importer = new FiverrJsonImporter();
        $res      = $importer->insertRows('tmp_insert_empty', []);
        $this->assertSame(['inserted' => 0, 'skipped' => 0], $res);
        $this->assertSame(0, DB::table('tmp_insert_empty')->count());
    }

    public function test_insertRows_preserves_provided_timestamps(): void
    {
        Schema::dropIfExists('tmp_insert_ts');
        Schema::create('tmp_insert_ts', function ($table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $importer = new FiverrJsonImporter();
        $created  = '2020-01-01 00:00:00';
        $updated  = '2020-01-02 00:00:00';
        $res      = $importer->insertRows('tmp_insert_ts', [
            'name'       => 'A',
            'created_at' => $created,
            'updated_at' => $updated,
        ]);
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);

        $row = DB::table('tmp_insert_ts')->where('name', 'A')->first();
        $this->assertSame($created, (string) $row->created_at);
        $this->assertSame($updated, (string) $row->updated_at);
    }

    public function test_loadJsonString_success(): void
    {
        $importer = new FiverrJsonImporter();
        $decoded  = $importer->loadJsonString(json_encode(['a' => 1]));
        $this->assertSame(['a' => 1], $decoded);
    }

    public function test_loadJsonString_invalid_json_throws(): void
    {
        $importer = new FiverrJsonImporter();
        $this->expectException(\RuntimeException::class);
        $importer->loadJsonString('{bad-json');
    }

    public function test_loadJsonFile_success(): void
    {
        $importer = new FiverrJsonImporter();
        $tmpOk    = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($tmpOk, json_encode(['a' => 1]));

        try {
            $data = $importer->loadJsonFile($tmpOk);
            $this->assertSame(['a' => 1], $data);
        } finally { // Use finally to ensure cleanup even if assertion fails
            unlink($tmpOk);
        }
    }

    public function test_loadJsonFile_invalid_json_throws(): void
    {
        $importer = new FiverrJsonImporter();
        $tmpBad   = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($tmpBad, '{not-json');
        $this->expectException(\RuntimeException::class);

        try {
            $importer->loadJsonFile($tmpBad);
        } finally {
            unlink($tmpBad);
        }
    }

    public function test_loadJsonFile_missing_throws(): void
    {
        $importer = new FiverrJsonImporter();
        $this->expectException(\RuntimeException::class);
        $importer->loadJsonFile('/definitely/missing/file.json');
    }

    public function test_transformTagsPageForImport_basic_transformation(): void
    {
        $importer = new FiverrJsonImporter();
        $tagsData = [
            'currency'  => ['name' => 'USD', 'rate' => 1, 'symbol' => '$'],
            'id'        => 'tag-123',
            'status'    => 'ACTIVE',
            'name'      => '1000 seo backlinks',
            'slug'      => '1000-seo-backlinks',
            'numOfGigs' => 48,
            'gigs'      => [
                [
                    'gigId'                  => 374638030,
                    'category_id'            => 2,
                    'sub_category_id'        => 65,
                    'nested_sub_category_id' => 2155,
                    'title'                  => 'Test gig',
                ],
                [
                    'gigId'                  => 123456789,
                    'category_id'            => 3,
                    'sub_category_id'        => 70,
                    'nested_sub_category_id' => 2200,
                    'title'                  => 'Another gig',
                ],
            ],
            'classification' => ['type' => 'TAG_PAGE', 'key' => '1000 seo backlinks'],
            'relatedLinks'   => [],
        ];

        $result = $importer->transformTagsPageForImport($tagsData);

        // Test required transformations
        $this->assertSame('tag', $result['source_format']);
        $this->assertArrayHasKey('listings', $result);
        $this->assertCount(1, $result['listings']);
        $this->assertArrayHasKey('gigs', $result['listings'][0]);
        $this->assertCount(2, $result['listings'][0]['gigs']);
        $this->assertSame($tagsData['gigs'], $result['listings'][0]['gigs']);

        // Test pagination mapping
        $this->assertArrayHasKey('appData', $result);
        $this->assertArrayHasKey('pagination', $result['appData']);
        $this->assertSame(48, $result['appData']['pagination']['total']);

        // Test category mapping from first gig
        $this->assertArrayHasKey('categoryIds', $result);
        $this->assertSame(2, $result['categoryIds']['categoryId']);
        $this->assertSame(65, $result['categoryIds']['subCategoryId']);
        $this->assertSame(2155, $result['categoryIds']['nestedSubCategoryId']);

        // Test that original data is preserved
        $this->assertSame($tagsData['currency'], $result['currency']);
        $this->assertSame($tagsData['id'], $result['id']);
        $this->assertSame($tagsData['status'], $result['status']);
        $this->assertSame($tagsData['name'], $result['name']);
        $this->assertSame($tagsData['slug'], $result['slug']);
        $this->assertSame($tagsData['classification'], $result['classification']);
        $this->assertSame($tagsData['relatedLinks'], $result['relatedLinks']);
    }

    public function test_transformTagsPageForImport_empty_gigs(): void
    {
        $importer = new FiverrJsonImporter();
        $tagsData = [
            'numOfGigs' => 0,
            'gigs'      => [],
        ];

        $result = $importer->transformTagsPageForImport($tagsData);

        $this->assertSame('tag', $result['source_format']);
        $this->assertCount(1, $result['listings']);
        $this->assertSame([], $result['listings'][0]['gigs']);
        $this->assertSame(0, $result['appData']['pagination']['total']);
        $this->assertArrayNotHasKey('categoryIds', $result);
    }

    public function test_transformTagsPageForImport_missing_gigs_array(): void
    {
        $importer = new FiverrJsonImporter();
        $tagsData = [
            'numOfGigs' => 10,
            // 'gigs' key missing
        ];

        $result = $importer->transformTagsPageForImport($tagsData);

        $this->assertSame('tag', $result['source_format']);
        $this->assertCount(1, $result['listings']);
        $this->assertSame([], $result['listings'][0]['gigs']);
        $this->assertSame(10, $result['appData']['pagination']['total']);
        $this->assertArrayNotHasKey('categoryIds', $result);
    }

    public function test_transformTagsPageForImport_missing_category_fields(): void
    {
        $importer = new FiverrJsonImporter();
        $tagsData = [
            'numOfGigs' => 1,
            'gigs'      => [
                [
                    'gigId' => 123,
                    // category fields missing
                    'title' => 'Test gig',
                ],
            ],
        ];

        $result = $importer->transformTagsPageForImport($tagsData);

        $this->assertSame('tag', $result['source_format']);
        $this->assertArrayHasKey('categoryIds', $result);
        $this->assertNull($result['categoryIds']['categoryId']);
        $this->assertNull($result['categoryIds']['subCategoryId']);
        $this->assertNull($result['categoryIds']['nestedSubCategoryId']);
    }

    public function test_transformTagsPageForImport_missing_numOfGigs(): void
    {
        $importer = new FiverrJsonImporter();
        $tagsData = [
            'gigs' => [
                ['gigId' => 123, 'title' => 'Test gig'],
            ],
            // 'numOfGigs' missing
        ];

        $result = $importer->transformTagsPageForImport($tagsData);

        $this->assertSame('tag', $result['source_format']);
        $this->assertNull($result['appData']['pagination']['total']);
        $this->assertCount(1, $result['listings'][0]['gigs']);
    }

    public function test_transformTagsPageForImport_overwrites_existing_keys(): void
    {
        $importer = new FiverrJsonImporter();
        $tagsData = [
            'source_format' => 'category', // Should be overwritten to 'tag'
            'listings'      => 'invalid',  // Should be overwritten by wrapped gigs array
            'appData'       => 'invalid',  // Should be overwritten by numOfGigs value
            'categoryIds'   => 'invalid',  // Should be overwritten by first gig's category IDs
            'gigs'          => [['gigId' => 123, 'category_id' => 2, 'sub_category_id' => 65]],
            'numOfGigs'     => 1,
        ];

        $result = $importer->transformTagsPageForImport($tagsData);

        $this->assertSame('tag', $result['source_format']); // Overwritten
        $this->assertIsArray($result['listings']);          // Overwritten
        $this->assertIsArray($result['appData']);           // Overwritten
        $this->assertIsArray($result['categoryIds']);       // Overwritten
        $this->assertCount(1, $result['listings']);
        $this->assertSame(1, $result['appData']['pagination']['total']);
        $this->assertSame(2, $result['categoryIds']['categoryId']);
        $this->assertSame(65, $result['categoryIds']['subCategoryId']);
    }

    public function test_importListingsFromArray_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();
        $payload  = [
            'listingAttributes' => ['id' => 'L_ARY_1'],
            'listings'          => [['gigs' => []]],
        ];

        $res = $importer->importListingsFromArray($payload);
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
        $this->assertSame(1, DB::table($importer->getFiverrListingsTable())->count());
    }

    public function test_importGigFromArray_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();
        $payload  = [
            'general' => ['gigId' => 777002],
        ];

        $res = $importer->importGigFromArray($payload);
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
        $this->assertSame(1, DB::table($importer->getFiverrGigsTable())->count());
    }

    public function test_importSellerProfileFromArray_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();
        $payload  = [
            'seller' => ['user' => ['id' => 'SELL_ARY_1']],
        ];

        $res = $importer->importSellerProfileFromArray($payload);
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
        $this->assertSame(1, DB::table($importer->getFiverrSellerProfilesTable())->count());
    }

    public function test_importListingsFromJson_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();
        $payload  = [
            'listingAttributes' => ['id' => 'L_JSON_1'],
            'listings'          => [['gigs' => []]],
        ];

        $res = $importer->importListingsFromJson(json_encode($payload));
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
        $this->assertSame(1, DB::table($importer->getFiverrListingsTable())->count());
    }

    public function test_importGigFromJson_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();
        $payload  = [
            'general' => ['gigId' => 777001],
        ];

        $res = $importer->importGigFromJson(json_encode($payload));
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
        $this->assertSame(1, DB::table($importer->getFiverrGigsTable())->count());
    }

    public function test_importSellerProfileFromJson_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();
        $payload  = [
            'seller' => ['user' => ['id' => 'SELL_JSON_1']],
        ];

        $res = $importer->importSellerProfileFromJson(json_encode($payload));
        $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
        $this->assertSame(1, DB::table($importer->getFiverrSellerProfilesTable())->count());
    }

    public function test_importListingsFromFile_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();

        // Minimal valid payload: must include unique listingAttributes__id
        $payload = [
            'listingAttributes' => [
                'id' => 'L1',
            ],
            'listings' => [
                ['gigs' => []],
            ],
        ];

        $json = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json, json_encode($payload));

        try {
            $res = $importer->importListingsFromFile($json);
            $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
            $this->assertSame(1, DB::table($importer->getFiverrListingsTable())->count());
        } finally {
            unlink($json);
        }
    }

    public function test_importGigFromFile_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();

        // Minimal valid payload: must include unique general.gigId
        $payload = [
            'general' => [
                'gigId' => 101,
            ],
        ];

        $json = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json, json_encode($payload));

        try {
            $res = $importer->importGigFromFile($json);
            $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
            $this->assertSame(1, DB::table($importer->getFiverrGigsTable())->count());
        } finally {
            unlink($json);
        }
    }

    public function test_importSellerProfileFromFile_inserts_row(): void
    {
        $importer = new FiverrJsonImporter();

        // Minimal valid payload: must include unique seller.user.id
        $payload = [
            'seller' => [
                'user' => [
                    'id' => 'S1',
                ],
            ],
        ];

        $json = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json, json_encode($payload));

        try {
            $res = $importer->importSellerProfileFromFile($json);
            $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
            $this->assertSame(1, DB::table($importer->getFiverrSellerProfilesTable())->count());
        } finally {
            unlink($json);
        }
    }

    public function test_resetListingsProcessed_clears_flags_and_returns_count(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed two rows directly
        DB::table($importer->getFiverrListingsTable())->insert([
            [
                'listingAttributes__id' => 'L10',
                'processed_at'          => now(),
                'processed_status'      => json_encode(['x' => 1], $importer->getJsonFlags()),
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'listingAttributes__id' => 'L11',
                'processed_at'          => now(),
                'processed_status'      => json_encode(['x' => 2], $importer->getJsonFlags()),
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);

        // Reset and assert
        $updated = $importer->resetListingsProcessed();
        $this->assertSame(2, $updated);

        $rows = DB::table($importer->getFiverrListingsTable())->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertNull($r->processed_at);
            $this->assertNull($r->processed_status);
        }
    }

    public function test_resetListingsProcessed_when_no_rows_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        $updated  = $importer->resetListingsProcessed();
        $this->assertSame(0, $updated);
    }

    public function test_resetListingsStatsProcessed_clears_flags_and_returns_count(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed two rows directly
        DB::table($importer->getFiverrListingsTable())->insert([
            [
                'listingAttributes__id'  => 'S10',
                'stats_processed_at'     => now(),
                'stats_processed_status' => json_encode(['y' => 1], $importer->getJsonFlags()),
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'listingAttributes__id'  => 'S11',
                'stats_processed_at'     => now(),
                'stats_processed_status' => json_encode(['y' => 2], $importer->getJsonFlags()),
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
        ]);

        // Reset and assert
        $updated = $importer->resetListingsStatsProcessed();
        $this->assertSame(2, $updated);

        $rows = DB::table($importer->getFiverrListingsTable())->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertNull($r->stats_processed_at);
            $this->assertNull($r->stats_processed_status);
        }
    }

    public function test_resetListingsStatsProcessed_when_no_rows_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        $updated  = $importer->resetListingsStatsProcessed();
        $this->assertSame(0, $updated);
    }

    public function test_resetListingsGigsStatsProcessed_clears_flags_and_returns_count(): void
    {
        $importer = new FiverrJsonImporter();

        DB::table($importer->getFiverrListingsTable())->insert([
            [
                'listingAttributes__id'       => 'L1',
                'gigs_stats_processed_at'     => now(),
                'gigs_stats_processed_status' => json_encode(['x' => 1]),
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ],
            [
                'listingAttributes__id'       => 'L2',
                'gigs_stats_processed_at'     => now(),
                'gigs_stats_processed_status' => json_encode(['y' => 2]),
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ],
        ]);

        $updated = $importer->resetListingsGigsStatsProcessed();
        $this->assertSame(2, $updated);

        $rows = DB::table($importer->getFiverrListingsTable())->orderBy('listingAttributes__id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertNull($r->gigs_stats_processed_at);
            $this->assertNull($r->gigs_stats_processed_status);
        }
    }

    public function test_resetListingsGigsStatsProcessed_when_no_rows_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        $updated  = $importer->resetListingsGigsStatsProcessed();
        $this->assertSame(0, $updated);
    }

    public function test_resetGigsProcessed_clears_flags_and_returns_count(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed two gigs
        DB::table($importer->getFiverrGigsTable())->insert([
            [
                'general__gigId'   => 1001,
                'processed_at'     => now(),
                'processed_status' => json_encode(['a' => 1]),
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'general__gigId'   => 1002,
                'processed_at'     => now(),
                'processed_status' => json_encode(['b' => 2]),
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);

        $updated = $importer->resetGigsProcessed();
        $this->assertSame(2, $updated);

        $rows = DB::table($importer->getFiverrGigsTable())->orderBy('general__gigId')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertNull($r->processed_at);
            $this->assertNull($r->processed_status);
        }
    }

    public function test_resetGigsProcessed_when_no_rows_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        $updated  = $importer->resetGigsProcessed();
        $this->assertSame(0, $updated);
    }

    public function test_processListingsGigsBatch_processes_at_most_batch_size_and_marks_processed(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed three listings with gigs
        $makePayload = function (string $id, array $gigIds): array {
            return [
                'listingAttributes' => ['id' => $id],
                'listings'          => [['gigs' => array_map(fn ($gid) => ['gigId' => $gid, 'title' => 'T' . $gid], $gigIds)]],
            ];
        };
        $payloads = [
            $makePayload('B1', [9101, 9102]),
            $makePayload('B2', [9201, 9202]),
            $makePayload('B3', [9301]),
        ];

        foreach ($payloads as $p) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');
            file_put_contents($json, json_encode($p));

            try {
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        // First batch: limit 2 -> should process B1 and B2 only
        $stats1 = $importer->processListingsGigsBatch(2);
        $this->assertSame(2, $stats1['rows_processed']);
        $this->assertSame(4, $stats1['gigs_seen']);
        $this->assertSame(4, $stats1['inserted']);

        // Verify two processed, one remaining
        $processedCount = DB::table($importer->getFiverrListingsTable())->whereNotNull('processed_at')->count();
        $this->assertSame(2, $processedCount);
        $remainingCount = DB::table($importer->getFiverrListingsTable())->whereNull('processed_at')->count();
        $this->assertSame(1, $remainingCount);
        $this->assertSame(4, DB::table($importer->getFiverrListingsGigsTable())->count());

        // Second batch: should process the remaining B3
        $stats2 = $importer->processListingsGigsBatch(2);
        $this->assertSame(1, $stats2['rows_processed']);
        $this->assertSame(1, $stats2['gigs_seen']);
        $this->assertSame(1, $stats2['inserted']);

        $this->assertSame(0, DB::table($importer->getFiverrListingsTable())->whereNull('processed_at')->count());
        $this->assertSame(5, DB::table($importer->getFiverrListingsGigsTable())->count());
    }

    public function test_processListingsGigsBatch_no_unprocessed_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        // With no listings, batch should return zeros
        $stats = $importer->processListingsGigsBatch(5);
        $this->assertSame(['rows_processed' => 0, 'gigs_seen' => 0, 'inserted' => 0], $stats);
    }

    public function test_processListingsGigsBatch_handles_invalid_json_gracefully(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed one listing with minimal valid structure, then corrupt the listings JSON
        $payload = ['listingAttributes' => ['id' => 'E1'], 'listings' => [['gigs' => []]]];
        $json    = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json, json_encode($payload));

        try {
            $importer->importListingsFromFile($json);
        } finally {
            unlink($json);
        }

        // Corrupt the listings column directly to simulate malformed JSON
        DB::table($importer->getFiverrListingsTable())
            ->where('listingAttributes__id', 'E1')
            ->update(['listings' => '{bad-json']);

        $stats = $importer->processListingsGigsBatch(5);
        $this->assertSame(1, $stats['rows_processed']);
        $this->assertSame(0, $stats['gigs_seen']);
        $this->assertSame(0, $stats['inserted']);

        $row = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', 'E1')->first();
        $this->assertNotNull($row->processed_at);
        $this->assertNotNull($row->processed_status);
    }

    public function test_processListingsGigsBatch_handles_missing_gigs_key(): void
    {
        $importer = new FiverrJsonImporter();

        $payload = ['listingAttributes' => ['id' => 'E2'], 'listings' => [['no_gigs' => true]]];
        $json    = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json, json_encode($payload));

        try {
            $importer->importListingsFromFile($json);
        } finally {
            unlink($json);
        }

        $stats = $importer->processListingsGigsBatch(5);
        $this->assertSame(1, $stats['rows_processed']);
        $this->assertSame(0, $stats['gigs_seen']);
        $this->assertSame(0, $stats['inserted']);

        $row = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', 'E2')->first();
        $this->assertNotNull($row->processed_at);
        $this->assertNotNull($row->processed_status);
    }

    public function test_processListingsGigsAll_inserts_gigs_and_marks_processed(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed one listing with two gigs inside listings[0].gigs
        $payload = [
            'listingAttributes' => ['id' => 'L20'],
            'listings'          => [
                [
                    'gigs' => [
                        ['gigId' => 9001, 'title' => 'Gig A'],
                        ['gigId' => 9002, 'title' => 'Gig B'],
                    ],
                ],
            ],
        ];
        $json = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json, json_encode($payload));

        try {
            $importer->importListingsFromFile($json);
        } finally {
            unlink($json);
        }

        // Process all (single batch here)
        $stats = $importer->processListingsGigsAll(50);
        $this->assertSame(1, $stats['rows_processed']);
        $this->assertSame(2, $stats['gigs_seen']);
        $this->assertSame(2, $stats['inserted']);

        // Verify gigs inserted and timestamps populated
        $this->assertSame(2, DB::table($importer->getFiverrListingsGigsTable())->count());
        $gigs = DB::table($importer->getFiverrListingsGigsTable())->orderBy('gigId')->get();
        $this->assertSame(9001, (int) $gigs[0]->gigId);
        $this->assertSame(9002, (int) $gigs[1]->gigId);
        $this->assertNotNull($gigs[0]->created_at);
        $this->assertNotNull($gigs[0]->updated_at);

        // Listing should be marked processed
        $listing = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', 'L20')->first();
        $this->assertNotNull($listing->processed_at);
        $this->assertNotNull($listing->processed_status);
    }

    public function test_processListingsGigsAll_dedupes_duplicate_gigs_across_listings(): void
    {
        $importer = new FiverrJsonImporter();

        // Helper function to make a payload based on gigId
        $makePayload = function (string $id, int $gigId): array {
            return [
                'listingAttributes' => ['id' => $id],
                'listings'          => [['gigs' => [['gigId' => $gigId, 'title' => 'T' . $gigId]]]],
            ];
        };
        // Two listings referencing the same gigId
        $p1 = $makePayload('D1', 9901);
        $p2 = $makePayload('D2', 9901);

        foreach ([$p1, $p2] as $p) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');
            file_put_contents($json, json_encode($p));

            try {
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        $stats = $importer->processListingsGigsAll(5);
        $this->assertSame(2, $stats['rows_processed']);
        $this->assertSame(2, DB::table($importer->getFiverrListingsTable())->whereNotNull('processed_at')->count());

        // Only one row for the duplicate gigId due to unique+insertOrIgnore
        $this->assertSame(1, DB::table($importer->getFiverrListingsGigsTable())->where('gigId', 9901)->count());
    }

    public static function provider_getSellerLevelNumeric(): array
    {
        return [
            ['na', 0],
            ['level_one_seller', 1],
            ['level_two_seller', 2],
            ['top_rated_seller', 3],
            ['UNKNOWN', 0],
            ['NEW_SELLER', 0],
            ['LEVEL_ONE', 1],
            ['LEVEL_TWO', 2],
            ['LEVEL_TRS', 3],
        ];
    }

    #[DataProvider('provider_getSellerLevelNumeric')]
    public function test_getSellerLevelNumeric(string $input, int $expected): void
    {
        $importer = new FiverrJsonImporter();
        $this->assertSame($expected, $importer->getSellerLevelNumeric($input));
    }

    public static function provider_getSellerLevelAdjusted(): array
    {
        return [
            // Count-only (rating null)
            'count-only-none'         => [0, 0,   null, 0],
            'count-only-l1'           => [0, 5,   null, 1],
            'count-only-l2'           => [0, 20,  null, 2],
            'count-only-tr'           => [0, 40,  null, 3],
            'count-only-max-existing' => [1, 40,  null, 3], // max(1,3) = 3
            'count-only-no-downgrade' => [2, 5,   null, 2], // inferred 1 but keep 2

            // With rating provided (inclusive thresholds)
            'rating-l1'                    => [0, 5,   4.4,  1],
            'rating-l2'                    => [0, 20,  4.6,  2],
            'rating-tr'                    => [0, 40,  4.7,  3],
            'rating-high-count-low-rating' => [0, 40, 4.69, 2], // below 4.7 → Level 2
            'rating-high-rating-low-count' => [0, 39, 4.7,  2], // count<40 → Level 2
            'max-existing-higher'          => [3, 5,   4.4,  3],    // inferred 1 but keep 3
        ];
    }

    #[DataProvider('provider_getSellerLevelAdjusted')]
    public function test_getSellerLevelAdjusted(int $sellerLevel, int $count, ?float $rating, int $expected): void
    {
        $importer = new FiverrJsonImporter();
        $this->assertSame($expected, $importer->getSellerLevelAdjusted($sellerLevel, $count, $rating));
    }

    public static function provider_calculateSellerLevelStats(): array
    {
        return [
            'mixed-levels' => [
                [
                    ['seller_level' => 'na'],
                    ['seller_level' => 'level_one_seller'],
                    ['seller_level' => 'level_two_seller'],
                    ['seller_level' => 'top_rated_seller'],
                    ['seller_level' => 'level_two_seller'],
                    ['seller_level' => 'unknown'],
                ],
                ['na' => 2, 'level_one' => 1, 'level_two' => 2, 'top_rated' => 1, 'avg' => 8 / 6],
            ],
            'empty' => [
                [],
                ['na' => 0, 'level_one' => 0, 'level_two' => 0, 'top_rated' => 0, 'avg' => null],
            ],
        ];
    }

    #[DataProvider('provider_calculateSellerLevelStats')]
    public function test_calculateSellerLevelStats(array $items, array $expected): void
    {
        $importer = new FiverrJsonImporter();
        $stats    = $importer->calculateSellerLevelStats($items);

        $this->assertSame($expected['na'], $stats['na']);
        $this->assertSame($expected['level_one'], $stats['level_one']);
        $this->assertSame($expected['level_two'], $stats['level_two']);
        $this->assertSame($expected['top_rated'], $stats['top_rated']);

        if ($expected['avg'] === null) {
            $this->assertNull($stats['weighted_avg']);
        } else {
            $this->assertEqualsWithDelta($expected['avg'], $stats['weighted_avg'] ?? -1, 1e-9);
        }
    }

    public static function provider_calculateSellerLevelAdjustedStats_count_only(): array
    {
        $gigs_basic = [
            ['seller_level' => 'na',                'seller_rating' => ['count' => 0]],    // -> 0
            ['seller_level' => 'level_one_seller',  'seller_rating' => ['count' => 4]],    // inf 0 -> max(1,0)=1
            ['seller_level' => 'level_one_seller',  'seller_rating' => ['count' => 5]],    // inf 1 -> 1
            ['seller_level' => 'level_two_seller',  'seller_rating' => ['count' => 19]],   // inf 1 -> max(2,1)=2
            ['seller_level' => 'na',                'seller_rating' => ['count' => '20']], // numeric string -> 2
            ['seller_level' => 'na',                'seller_rating' => ['count' => 39.0]], // float -> 2
            ['seller_level' => 'na',                'seller_rating' => ['count' => 40]],   // -> 3
        ];

        return [
            'empty' => [
                [],
                ['values' => [], 'avg' => null],
            ],
            'basic-mixed' => [
                $gigs_basic,
                ['values' => [0, 1, 1, 2, 2, 2, 3], 'avg' => 11 / 7],
            ],
        ];
    }

    #[DataProvider('provider_calculateSellerLevelAdjustedStats_count_only')]
    public function test_calculateSellerLevelAdjustedStats_count_only(array $gigs, array $expected): void
    {
        $importer = new FiverrJsonImporter();
        $result   = $importer->calculateSellerLevelAdjustedStats($gigs);

        $this->assertSame($expected['values'], $result['values']);

        if ($expected['avg'] === null) {
            $this->assertNull($result['avg']);
        } else {
            $this->assertEqualsWithDelta($expected['avg'], (float) $result['avg'], 1e-9);
        }
    }

    public static function provider_calculateSellerLevelAdjustedStats_with_ratings(): array
    {
        $gigs = [
            ['seller_level' => 'na',         'seller_rating' => ['count' => 5,    'score' => 4.4]], // ->1
            ['seller_level' => 'na',         'seller_rating' => ['count' => '20', 'score' => 4.6]], // ->2
            ['seller_level' => 'na',         'seller_rating' => ['count' => 40.0, 'score' => 4.7]], // ->3
            ['seller_level' => 'na',         'seller_rating' => ['count' => 40,   'score' => 4.69]], // ->2
            ['seller_level' => 'na',         'seller_rating' => ['count' => 39,   'score' => 4.7]], // ->2
            ['seller_level' => 'level_trs',  'seller_rating' => ['count' => 0,    'score' => 0]], // keep 3
        ];

        return [
            'with-ratings' => [
                $gigs,
                ['values' => [1, 2, 3, 2, 2, 3], 'avg' => 13 / 6],
            ],
        ];
    }

    #[DataProvider('provider_calculateSellerLevelAdjustedStats_with_ratings')]
    public function test_calculateSellerLevelAdjustedStats_with_ratings(array $gigs, array $expected): void
    {
        $importer = new FiverrJsonImporter();
        $res      = $importer->calculateSellerLevelAdjustedStats($gigs, true);
        $this->assertSame($expected['values'], $res['values']);
        if ($expected['avg'] === null) {
            $this->assertNull($res['avg']);
        } else {
            $this->assertEqualsWithDelta($expected['avg'], (float) $res['avg'], 1e-9);
        }
    }

    public static function provider_extractFacetCount(): array
    {
        $facets = [
            ['id' => 'true', 'count' => 10],
            ['id' => 'false', 'count' => 5],
            ['id' => 'maybe'],
            ['id' => 'numstr', 'count' => '7'],
        ];

        return [
            'present-int'    => [$facets, 'true', 10],
            'present-int2'   => [$facets, 'false', 5],
            'present-nonint' => [$facets, 'maybe', 0],
            'present-numstr' => [$facets, 'numstr', 7],
            'missing'        => [$facets, 'missing', null],
        ];
    }

    #[DataProvider('provider_extractFacetCount')]
    public function test_extractFacetCount(array $facets, string $id, ?int $expected): void
    {
        $importer = new FiverrJsonImporter();
        $this->assertSame($expected, $importer->extractFacetCount($facets, $id));
    }

    public static function provider_categorizeGigType(): array
    {
        return [
            [null, 'missing'],
            ['', 'missing'],
            ['promoted_gigs', 'promoted_gigs'],
            ['FIVERR_CHOICE', 'fiverr_choice'],
            ['fixed_pricing', 'fixed_pricing'],
            ['pro', 'pro'],
            ['something_else', 'other'],
        ];
    }

    #[DataProvider('provider_categorizeGigType')]
    public function test_categorizeGigType(?string $input, string $expected): void
    {
        $importer = new FiverrJsonImporter();
        $this->assertSame($expected, $importer->categorizeGigType($input));
    }

    public function test_computeListingsStatsRow_handles_missing_index_zero_safely(): void
    {
        $importer = new FiverrJsonImporter();

        // Set up a listings row with listings JSON as an array without index 0
        $row = [
            'id'       => 123,
            'listings' => json_encode([1 => ['gigs' => [['type' => 'promoted_gigs']]]]),
        ];

        $stats = $importer->computeListingsStatsRow($row);

        // Should not error and should produce defaults (no gigs processed)
        $this->assertSame(123, $stats['fiverr_listings_row_id']);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___promoted_gigs'] ?? 0);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___fiverr_choice'] ?? 0);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___fixed_pricing'] ?? 0);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___pro'] ?? 0);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___missing'] ?? 0);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___other'] ?? 0);
    }

    public function test_computeListingsStatsRow_basic_aggregation_from_gigs(): void
    {
        $importer = new FiverrJsonImporter();

        $gigs = [
            ['type' => 'promoted_gigs', 'seller_level' => 'level_two_seller', 'buying_review_rating' => 4.5],
            ['type' => 'fixed_pricing', 'seller_level' => 'level_one_seller', 'buying_review_rating' => 5],
            ['type' => '', 'seller_level' => 'top_rated_seller'],
        ];
        $row = [
            'id'       => 456,
            'listings' => json_encode([['gigs' => $gigs]]),
        ];

        $stats = $importer->computeListingsStatsRow($row);

        // Type counts
        $this->assertSame(1, $stats['cnt___listings__gigs__type___promoted_gigs']);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___fiverr_choice']);
        $this->assertSame(1, $stats['cnt___listings__gigs__type___fixed_pricing']);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___pro']);
        $this->assertSame(1, $stats['cnt___listings__gigs__type___missing']);
        $this->assertSame(0, $stats['cnt___listings__gigs__type___other']);

        // Seller level counts
        $this->assertSame(0, $stats['cnt___listings__gigs__seller_level___na']);
        $this->assertSame(1, $stats['cnt___listings__gigs__seller_level___level_one_seller']);
        $this->assertSame(1, $stats['cnt___listings__gigs__seller_level___level_two_seller']);
        $this->assertSame(1, $stats['cnt___listings__gigs__seller_level___top_rated_seller']);

        // Weighted average seller level: (2 + 1 + 3) / 3 = 2.0
        $this->assertEqualsWithDelta(2.0, $stats['avg___listings__gigs__seller_level'] ?? -1, 1e-9);

        // Average of buying_review_rating: (4.5 + 5) / 2 = 4.75
        $this->assertEqualsWithDelta(4.75, $stats['avg___listings__gigs__buying_review_rating'] ?? -1, 1e-9);
    }

    public function test_computeListingsStatsRow_facets_priceBuckets_and_copyThrough(): void
    {
        $importer = new FiverrJsonImporter();

        // Build facets arrays
        $fac_seller_level = json_encode([
            ['id' => 'na', 'count' => 4],
            ['id' => 'level_one_seller', 'count' => 3],
            ['id' => 'level_two_seller', 'count' => 2],
            ['id' => 'top_rated_seller', 'count' => 1],
        ]);
        $fac_is_agency = json_encode([
            ['id' => 'true', 'count' => 11],
        ]);
        $fac_seller_language = json_encode([
            ['id' => 'en', 'count' => 7],
        ]);
        $fac_seller_location = json_encode([
            ['id' => 'US', 'count' => 5],
        ]);
        $fac_service_offerings = json_encode([
            ['id' => 'offer_consultation', 'count' => 9],
            ['id' => 'subscription', 'count' => 6],
        ]);

        $row = [
            'id' => 789,
            // Copy-through fields
            'listingAttributes__id'            => 'ATTR-123',
            'currency__rate'                   => 1.23,
            'rawListingData__has_more'         => true,
            'countryCode'                      => 'GB',
            'assumedLanguage'                  => 'en',
            'v2__report__search_total_results' => 321,
            'appData__pagination__page'        => 2,
            'appData__pagination__page_size'   => 50,
            'appData__pagination__total'       => 400,
            // Tracking copy-through fields
            'tracking__isNonExperiential'                   => false,
            'tracking__fiverrChoiceGigPosition'             => 7,
            'tracking__hasFiverrChoiceGigs'                 => true,
            'tracking__hasPromotedGigs'                     => true,
            'tracking__promotedGigsCount'                   => 3,
            'tracking__searchAutoComplete__is_autocomplete' => false,
            // Facets and price buckets
            'facets__seller_level'      => $fac_seller_level,
            'facets__is_agency'         => $fac_is_agency,
            'facets__seller_language'   => $fac_seller_language,
            'facets__seller_location'   => $fac_seller_location,
            'facets__service_offerings' => $fac_service_offerings,
            'priceBucketsSkeleton'      => json_encode([
                ['max' => 100], ['max' => 2000], ['max' => 30000],
            ]),
        ];

        $stats = $importer->computeListingsStatsRow($row);

        // Copy-through checks
        $this->assertSame('GB', $stats['countryCode']);
        $this->assertTrue($stats['tracking__hasPromotedGigs']);

        // Facet counts
        $this->assertSame(11, $stats['facets__is_agency___true___count']);

        // Additional copy-through checks
        $this->assertSame('ATTR-123', $stats['listingAttributes__id']);
        $this->assertEqualsWithDelta(1.23, (float)$stats['currency__rate'], 1e-9);
        $this->assertTrue($stats['rawListingData__has_more']);
        $this->assertSame('en', $stats['assumedLanguage']);
        $this->assertSame(321, $stats['v2__report__search_total_results']);
        $this->assertSame(2, $stats['appData__pagination__page']);
        $this->assertSame(50, $stats['appData__pagination__page_size']);
        $this->assertSame(400, $stats['appData__pagination__total']);

        // Additional tracking checks
        $this->assertFalse($stats['tracking__isNonExperiential']);
        $this->assertSame(7, $stats['tracking__fiverrChoiceGigPosition']);
        $this->assertTrue($stats['tracking__hasFiverrChoiceGigs']);
        $this->assertTrue($stats['tracking__hasPromotedGigs']);
        $this->assertSame(3, $stats['tracking__promotedGigsCount']);
        $this->assertFalse($stats['tracking__searchAutoComplete__is_autocomplete']);

        // Additional facet true counts
        $fac_has_hourly       = json_encode([['id' => 'true', 'count' => 8]]);
        $fac_is_pa_online     = json_encode([['id' => 'true', 'count' => 6]]);
        $fac_is_seller_online = json_encode([['id' => 'true', 'count' => 4]]);
        $fac_pro              = json_encode([['id' => 'true', 'count' => 2]]);

        $row2                             = $row;
        $row2['facets__has_hourly']       = $fac_has_hourly;
        $row2['facets__is_pa_online']     = $fac_is_pa_online;
        $row2['facets__is_seller_online'] = $fac_is_seller_online;
        $row2['facets__pro']              = $fac_pro;

        $stats2 = $importer->computeListingsStatsRow($row2);
        $this->assertSame(8, $stats2['facets__has_hourly___true___count']);
        $this->assertSame(6, $stats2['facets__is_pa_online___true___count']);
        $this->assertSame(4, $stats2['facets__is_seller_online___true___count']);
        $this->assertSame(2, $stats2['facets__pro___true___count']);

        $this->assertSame(7, $stats['facets__seller_language___en___count']);
        $this->assertSame(5, $stats['facets__seller_location___us___count']);
        $this->assertSame(9, $stats['facets__service_offerings__offer_consultation___count']);
        $this->assertSame(6, $stats['facets__service_offerings__subscription___count']);

        // Price buckets (already above) and countryCode were checked; also check listing row id copy
        $this->assertSame(789, $stats['fiverr_listings_row_id']);

        // Facet seller levels and weighted average
        $this->assertSame(4, $stats['facets__seller_level___na___count']);
        $this->assertSame(3, $stats['facets__seller_level___level_one_seller___count']);
        $this->assertSame(2, $stats['facets__seller_level___level_two_seller___count']);
        $this->assertSame(1, $stats['facets__seller_level___top_rated_seller___count']);
        $this->assertEqualsWithDelta(1.0, $stats['avg___facets___seller_level'] ?? -1, 1e-9);

        // Price buckets
        $this->assertSame(100, $stats['priceBucketsSkeleton___0___max']);
        $this->assertSame(2000, $stats['priceBucketsSkeleton___1___max']);
        $this->assertSame(30000, $stats['priceBucketsSkeleton___2___max']);
    }

    public function test_computeListingsStatsRow_all_type_buckets_booleans_and_null_average(): void
    {
        $importer = new FiverrJsonImporter();

        $gigs = [
            ['type' => 'promoted_gigs', 'is_featured' => true],
            ['type'     => 'fiverr_choice', 'extra_fast' => true],
            ['type'     => 'fixed_pricing'],
            ['type'     => 'pro'],
            ['type'     => 'weird_type'], // other
            ['type'     => ''], // missing
            ['packages' => ['recommended' => ['extra_fast' => true, 'price' => 10]]],
            ['type'     => 'promoted_gigs', 'is_fiverr_choice' => true],
            ['type'     => 'pro', 'is_pro' => true],
            ['type'     => 'pro', 'seller_online' => true],
            ['type'     => 'pro', 'offer_consultation' => true],
            ['type'     => 'pro', 'personalized_pricing_fail' => true],
            ['type'     => 'pro', 'has_recurring_option' => true],
            ['type'     => 'pro', 'is_seller_unavailable' => true],
            ['type'     => 'pro', 'seller_rating' => ['count' => 2]],
            ['type'     => 'pro', 'price_i' => 1, 'package_i' => 3, 'num_of_packages' => 5],
            ['type'     => 'pro', 'buying_review_rating_count' => 4],
            ['type'     => 'pro', 'packages' => ['recommended' => ['duration' => 2, 'price_tier' => 1]]],
        ];

        $row = [
            'id'       => 901,
            'listings' => json_encode([['gigs' => $gigs]]),
        ];

        $stats = $importer->computeListingsStatsRow($row);

        // All type buckets
        $this->assertSame(2, $stats['cnt___listings__gigs__type___promoted_gigs']);
        $this->assertSame(1, $stats['cnt___listings__gigs__type___fiverr_choice']);
        $this->assertSame(1, $stats['cnt___listings__gigs__type___fixed_pricing']);
        $this->assertSame(11, $stats['cnt___listings__gigs__type___pro']);
        $this->assertSame(1, $stats['cnt___listings__gigs__type___other']);
        $this->assertSame(2, $stats['cnt___listings__gigs__type___missing']);

        // Boolean counts
        $this->assertSame(1, $stats['cnt___listings__gigs__is_featured']);
        $this->assertSame(1, $stats['cnt___listings__gigs__extra_fast']);
        $this->assertSame(1, $stats['cnt___listings__gigs__packages__recommended__extra_fast']);

        // Additional boolean counts we set above
        $this->assertSame(1, $stats['cnt___listings__gigs__is_fiverr_choice']);
        $this->assertSame(1, $stats['cnt___listings__gigs__is_pro']);
        $this->assertSame(1, $stats['cnt___listings__gigs__seller_online']);
        $this->assertSame(1, $stats['cnt___listings__gigs__offer_consultation']);
        $this->assertSame(1, $stats['cnt___listings__gigs__personalized_pricing_fail']);
        $this->assertSame(1, $stats['cnt___listings__gigs__has_recurring_option']);
        $this->assertSame(1, $stats['cnt___listings__gigs__is_seller_unavailable']);

        // Remaining averages
        $this->assertEqualsWithDelta(2.0, $stats['avg___listings__gigs__packages__recommended__duration'] ?? -1, 1e-9);
        $this->assertEqualsWithDelta(1.0, $stats['avg___listings__gigs__packages__recommended__price_tier'] ?? -1, 1e-9);
        $this->assertEqualsWithDelta(4.0, $stats['avg___listings__gigs__buying_review_rating_count'] ?? -1, 1e-9);
        $this->assertEqualsWithDelta(2.0, $stats['avg___listings__gigs__seller_rating__count'] ?? -1, 1e-9);
        $this->assertEqualsWithDelta(1.0, $stats['avg___listings__gigs__price_i'] ?? -1, 1e-9);
        $this->assertEqualsWithDelta(3.0, $stats['avg___listings__gigs__package_i'] ?? -1, 1e-9);
        $this->assertEqualsWithDelta(5.0, $stats['avg___listings__gigs__num_of_packages'] ?? -1, 1e-9);

        // One recommended average present (price)
        $this->assertEqualsWithDelta(10.0, $stats['avg___listings__gigs__packages__recommended__price'] ?? -1, 1e-9);

        // Example of null average when no numeric values provided (seller_rating__score)
        $this->assertNull($stats['avg___listings__gigs__seller_rating__score']);
    }

    public function test_processListingsStatsBatch_processes_at_most_batch_size_and_marks_stats_processed(): void
    {
        $importer = new FiverrJsonImporter();

        // Helper to create and import a minimal listings JSON payload
        $makePayload = function (string $id): array {
            return [
                'listingAttributes' => ['id' => $id],
                'currency'          => ['rate' => 1],
                'rawListingData'    => ['has_more' => true],
                'countryCode'       => 'US',
                'assumedLanguage'   => 'en',
                'v2'                => ['report' => ['search_total_results' => 1]],
                'appData'           => ['pagination' => ['page' => 1, 'page_size' => 48, 'total' => 1]],
                'listings'          => [['gigs' => []]],
                'facets'            => [
                    'is_agency'         => [['id' => 'true', 'count' => 1]],
                    'seller_language'   => [['id' => 'en', 'count' => 1]],
                    'seller_level'      => [['id' => 'na', 'count' => 1]],
                    'seller_location'   => [['id' => 'US', 'count' => 1]],
                    'service_offerings' => [['id' => 'offer_consultation', 'count' => 1]],
                ],
                'priceBucketsSkeleton' => [['max' => 100], ['max' => 200], ['max' => 300]],
                'tracking'             => [
                    'isNonExperiential'       => false,
                    'fiverrChoiceGigPosition' => 0,
                    'hasFiverrChoiceGigs'     => false,
                    'hasPromotedGigs'         => false,
                    'promotedGigsCount'       => 0,
                    'searchAutoComplete'      => ['is_autocomplete' => false],
                ],
            ];
        };

        // Seed three listings
        foreach (['S1', 'S2', 'S3'] as $id) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');

            try {
                file_put_contents($json, json_encode($makePayload($id)));
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        // Process first batch of size 2
        $stats1 = $importer->processListingsStatsBatch(2);
        $this->assertSame(2, $stats1['rows_processed']);
        $this->assertSame(2, DB::table($importer->getFiverrListingsStatsTable())->count());
        $this->assertSame(1, DB::table($importer->getFiverrListingsTable())->whereNull('stats_processed_at')->count());

        // Verify one computed stats row content (S1)
        $listingS1 = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', 'S1')->first();
        $this->assertNotNull($listingS1);
        $statsS1 = DB::table($importer->getFiverrListingsStatsTable())->where('fiverr_listings_row_id', $listingS1->id)->first();
        $this->assertNotNull($statsS1);
        $this->assertSame('S1', $statsS1->listingAttributes__id);
        $this->assertEqualsWithDelta(1.0, (float) $statsS1->currency__rate, 1e-9);
        $this->assertSame(1, $statsS1->rawListingData__has_more);
        $this->assertSame('US', $statsS1->countryCode);
        $this->assertSame('en', $statsS1->assumedLanguage);
        $this->assertSame(1, $statsS1->v2__report__search_total_results);
        $this->assertSame(1, $statsS1->appData__pagination__page);
        $this->assertSame(48, $statsS1->appData__pagination__page_size);
        $this->assertSame(1, $statsS1->appData__pagination__total);
        // Facets & buckets
        $this->assertSame(1, $statsS1->facets__is_agency___true___count);
        $this->assertSame(1, $statsS1->facets__seller_language___en___count);
        $this->assertSame(1, $statsS1->facets__seller_level___na___count);
        $this->assertEqualsWithDelta(0.0, (float) $statsS1->avg___facets___seller_level, 1e-9);
        $this->assertSame(1, $statsS1->facets__seller_location___us___count);
        $this->assertSame(1, $statsS1->facets__service_offerings__offer_consultation___count);
        $this->assertSame(100, $statsS1->priceBucketsSkeleton___0___max);
        $this->assertSame(200, $statsS1->priceBucketsSkeleton___1___max);
        $this->assertSame(300, $statsS1->priceBucketsSkeleton___2___max);
        // Tracking copy-through
        $this->assertSame(0, $statsS1->tracking__isNonExperiential);
        $this->assertSame(0, $statsS1->tracking__fiverrChoiceGigPosition);
        $this->assertSame(0, $statsS1->tracking__hasFiverrChoiceGigs);
        $this->assertSame(0, $statsS1->tracking__hasPromotedGigs);
        $this->assertSame(0, $statsS1->tracking__promotedGigsCount);
        $this->assertSame(0, $statsS1->tracking__searchAutoComplete__is_autocomplete);

        // Verify stats_processed_status content
        $status = DB::table($importer->getFiverrListingsTable())->where('id', $listingS1->id)->value('stats_processed_status');
        $this->assertNotNull($status);
        $decoded = json_decode((string) $status, true);
        $this->assertIsArray($decoded);
        $this->assertSame($listingS1->id, $decoded['row_id']);
        $this->assertSame(1, $decoded['inserted']);
        $this->assertSame(0, $decoded['skipped']);

        // Process second batch (remaining 1)
        $stats2 = $importer->processListingsStatsBatch(5);
        $this->assertSame(1, $stats2['rows_processed']);
        $this->assertSame(3, DB::table($importer->getFiverrListingsStatsTable())->count());
        $this->assertSame(0, DB::table($importer->getFiverrListingsTable())->whereNull('stats_processed_at')->count());
    }

    public function test_processListingsStatsBatch_no_unprocessed_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        $stats    = $importer->processListingsStatsBatch(10);
        $this->assertSame(['rows_processed' => 0, 'inserted' => 0, 'skipped' => 0], $stats);
    }

    public function test_processListingsStatsBatch_handles_duplicate_stats_row_gracefully(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed one listing
        $payload = [
            'listingAttributes' => ['id' => 'DUP1'],
            'listings'          => [['gigs' => []]],
        ];
        $json = tempnam(sys_get_temp_dir(), 'importer_json_');

        try {
            file_put_contents($json, json_encode($payload));
            $importer->importListingsFromFile($json);
        } finally {
            unlink($json);
        }

        $listing = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', 'DUP1')->first();
        $this->assertNotNull($listing);

        // Insert a pre-existing stats row for this listing
        DB::table($importer->getFiverrListingsStatsTable())->insert([
            'fiverr_listings_row_id' => $listing->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $this->assertSame(1, DB::table($importer->getFiverrListingsStatsTable())->where('fiverr_listings_row_id', $listing->id)->count());

        // Now run the batch; insert should be ignored due to unique constraint, but listing should still be marked processed
        $res = $importer->processListingsStatsBatch(5);

        $this->assertSame(1, $res['rows_processed']);
        $this->assertSame(0, $res['inserted']);
        $this->assertSame(1, $res['skipped']);

        // Verify processed markers
        $updated = DB::table($importer->getFiverrListingsTable())->where('id', $listing->id)->first();

        $this->assertNotNull($updated->stats_processed_at);
        $this->assertNotNull($updated->stats_processed_status);

        $status = json_decode((string) $updated->stats_processed_status, true);

        $this->assertSame($listing->id, $status['row_id']);
        $this->assertSame(0, $status['inserted']);
        $this->assertSame(1, $status['skipped']);

        // Still only 1 stats row
        $this->assertSame(1, DB::table($importer->getFiverrListingsStatsTable())->where('fiverr_listings_row_id', $listing->id)->count());
    }

    public function test_processListingsStatsAll_inserts_stats_and_marks_processed_across_batches(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed 3 listings to exercise multiple batches
        foreach (['A1', 'A2', 'A3'] as $id) {
            $payload = [
                'listingAttributes' => ['id' => $id],
                'listings'          => [['gigs' => []]],
            ];
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');

            try {
                file_put_contents($json, json_encode($payload));
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        $res = $importer->processListingsStatsAll(1); // force multiple iterations
        $this->assertSame(3, $res['rows_processed']);
        $this->assertSame(3, $res['inserted']);

        $this->assertSame(0, DB::table($importer->getFiverrListingsTable())->whereNull('stats_processed_at')->count());
        $this->assertSame(3, DB::table($importer->getFiverrListingsStatsTable())->count());

        // Re-running should do nothing
        $res2 = $importer->processListingsStatsAll(1);
        $this->assertSame(0, $res2['rows_processed']);
        $this->assertSame(0, $res2['inserted']);
        $this->assertSame(0, $res2['skipped']);
    }

    public function test_getAllListingsData_returns_map_with_decoded_gigs(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed two listings with minimal gigs arrays
        $payload1 = [
            'listingAttributes' => ['id' => 'L1'],
            'listings'          => [['gigs' => [
                ['gigId' => 1001, 'pos' => 0],
                ['gigId' => 1002, 'pos' => 10],
            ]]],
        ];
        $payload2 = [
            'listingAttributes' => ['id' => 'L2'],
            'listings'          => [['gigs' => [
                ['gigId' => 2001, 'pos' => 5],
                ['gigId' => 1002, 'pos' => 7], // duplicate gigId across listings
            ]]],
        ];

        foreach ([$payload1, $payload2] as $p) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');
            file_put_contents($json, json_encode($p));

            try {
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        $map = $importer->getAllListingsData(useCache: false);

        $this->assertArrayHasKey('L1', $map);
        $this->assertArrayHasKey('L2', $map);
        $this->assertIsArray($map['L1'][0]['gigs']);
        $this->assertIsArray($map['L2'][0]['gigs']);
        // Verify decoded gigs present
        $this->assertSame(2, count($map['L1'][0]['gigs'] ?? []));
        $this->assertSame(2, count($map['L2'][0]['gigs'] ?? []));
    }

    public function test_getAllListingsData_skips_malformed_json(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed one row with invalid JSON
        DB::table($importer->getFiverrListingsTable())->insert([
            'listingAttributes__id' => 'BAD1',
            'listings'              => '{invalid-json',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $map = $importer->getAllListingsData(useCache: false);
        $this->assertArrayNotHasKey('BAD1', $map); // BAD1 should not be present
    }

    public function test_getListingToGigPositionsMap_builds_sorted_map(): void
    {
        $importer = new FiverrJsonImporter();

        $payload1 = [
            'listingAttributes' => ['id' => 'LM1'],
            'listings'          => [[
                'gigs' => [
                    ['gigId' => 5102, 'pos' => 7],
                    ['gigId' => 5101, 'pos' => 0],
                ],
            ]],
        ];
        $payload2 = [
            'listingAttributes' => ['id' => 'LM2'],
            'listings'          => [[
                'gigs' => [
                    ['gigId' => 6201, 'pos' => 3],
                ],
            ]],
        ];

        foreach ([$payload1, $payload2] as $p) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');
            file_put_contents($json, json_encode($p));

            try {
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        $map = $importer->getListingToGigPositionsMap(useCache: false);

        $this->assertArrayHasKey('LM1', $map);
        $this->assertArrayHasKey('LM2', $map);
        $this->assertSame([0 => 5101, 7 => 5102], $map['LM1']);
        $this->assertSame([3 => 6201], $map['LM2']);
    }

    public function test_getGigIdToListingIdMapBetweenPositions_filters_and_maps(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed two listings with gigs and positions
        $payload1 = [
            'listingAttributes' => ['id' => 'GL1'],
            'listings'          => [['gigs' => [
                ['gigId' => 1101, 'pos' => 0],   // in range
                ['gigId' => 1102, 'pos' => 10],  // out of range for [0,7]
            ]]],
        ];
        $payload2 = [
            'listingAttributes' => ['id' => 'GL2'],
            'listings'          => [['gigs' => [
                ['gigId' => 2201, 'pos' => 5],   // in range
                ['gigId' => 1102, 'pos' => 7],   // duplicate gigId, in range here
            ]]],
        ];

        foreach ([$payload1, $payload2] as $p) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');
            file_put_contents($json, json_encode($p));

            try {
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        $map = $importer->getGigIdToListingIdMapBetweenPositions(0, 7, useCache: false);

        // Should include 1101=>['GL1'=>0], 2201=>['GL2'=>5], and 1102=>['GL2'=>7]
        $this->assertSame(['GL1' => 0], $map[1101]);
        $this->assertSame(['GL2' => 5], $map[2201]);
        $this->assertSame(['GL2' => 7], $map[1102]);
    }

    public function test_getGigIdToListingIdMapBetweenPositions_range_10_15(): void
    {
        $importer = new FiverrJsonImporter();

        $payloadA = [
            'listingAttributes' => ['id' => 'GL3'],
            'listings'          => [[
                'gigs' => [
                    ['gigId' => 3101, 'pos' => 10], // in
                    ['gigId' => 3102, 'pos' => 15], // in (first seen for 3102)
                    ['gigId' => 3103, 'pos' => 9],  // out
                ],
            ]],
        ];
        $payloadB = [
            'listingAttributes' => ['id' => 'GL4'],
            'listings'          => [[
                'gigs' => [
                    ['gigId' => 3102, 'pos' => 12], // in but duplicate; should not override first
                    ['gigId' => 3201, 'pos' => 20], // out
                ],
            ]],
        ];

        foreach ([$payloadA, $payloadB] as $p) {
            $json = tempnam(sys_get_temp_dir(), 'importer_json_');
            file_put_contents($json, json_encode($p));

            try {
                $importer->importListingsFromFile($json);
            } finally {
                unlink($json);
            }
        }

        $map = $importer->getGigIdToListingIdMapBetweenPositions(10, 15, useCache: false);

        $this->assertSame(['GL3' => 10], $map[3101]);
        $this->assertArrayHasKey(3102, $map);
        $this->assertSame(15, $map[3102]['GL3']);
        $this->assertSame(12, $map[3102]['GL4']);
        $this->assertArrayNotHasKey(3103, $map); // below range
        $this->assertArrayNotHasKey(3201, $map); // above range
    }

    public function test_getGigIdToListingIdMapBetweenPositions_throws_on_invalid_range(): void
    {
        $importer = new FiverrJsonImporter();
        $this->expectException(\InvalidArgumentException::class);
        $importer->getGigIdToListingIdMapBetweenPositions(10, 5, useCache: false);
    }

    public static function provider_parseDeliveryTimeDays(): array
    {
        return [
            [null, null],
            ['', null],
            [' ', null],
            ['1 day', 1],
            ['3 days', 3],
            [' 12 days ', 12],
            ['0 day', 0],
            ['same day', null],
            ['10', 10],
        ];
    }

    #[DataProvider('provider_parseDeliveryTimeDays')]
    public function test_parseDeliveryTimeDays(?string $input, ?int $expected): void
    {
        $importer = new FiverrJsonImporter();
        $this->assertSame($expected, $importer->parseDeliveryTimeDays($input));
    }

    public function test_extractGigFieldArrays_orders_by_input_and_maps_seller_level(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed two gigs with distinct values
        DB::table($importer->getFiverrGigsTable())->insert([
            [
                'general__gigId'                          => 7001,
                'sellerCard__memberSince'                 => 1111111111,
                'sellerCard__responseTime'                => 2,
                'sellerCard__recentDelivery'              => 1700000000000,
                'overview__gig__rating'                   => 4.8,
                'overview__gig__ratingsCount'             => 120,
                'overview__gig__ordersInQueue'            => 1,
                'topNav__gigCollectedCount'               => 10,
                'portfolio__projectsCount'                => 2,
                'seo__description__deliveryTime'          => '1 day',
                'seo__schemaMarkup__gigOffers__lowPrice'  => '25.00',
                'seo__schemaMarkup__gigOffers__highPrice' => '60.00',
                'seller__user__joinedAt'                  => 1600000000,
                'seller__sellerLevel'                     => 'level_one',
                'seller__isPro'                           => false,
                'seller__rating__count'                   => 5,
                'seller__rating__score'                   => 4.6,
                'seller__responseTime__inHours'           => 3,
                'seller__completedOrdersCount'            => 100,
                'created_at'                              => now(),
                'updated_at'                              => now(),
            ],
            [
                'general__gigId'                          => 7002,
                'sellerCard__memberSince'                 => 2222222222,
                'sellerCard__responseTime'                => 4,
                'sellerCard__recentDelivery'              => 1710000000000,
                'overview__gig__rating'                   => 5.0,
                'overview__gig__ratingsCount'             => 240,
                'overview__gig__ordersInQueue'            => 0,
                'topNav__gigCollectedCount'               => 20,
                'portfolio__projectsCount'                => 4,
                'seo__description__deliveryTime'          => '3 days',
                'seo__schemaMarkup__gigOffers__lowPrice'  => '15.00',
                'seo__schemaMarkup__gigOffers__highPrice' => '90.00',
                'seller__user__joinedAt'                  => 1500000000,
                'seller__sellerLevel'                     => 'level_two',
                'seller__isPro'                           => true,
                'seller__rating__count'                   => 7,
                'seller__rating__score'                   => 4.9,
                'seller__responseTime__inHours'           => 1,
                'seller__completedOrdersCount'            => 200,
                'created_at'                              => now(),
                'updated_at'                              => now(),
            ],
        ]);

        // Provide input with duplicates and out-of-order, plus an invalid id
        $gigIds = [7002, 7001, 7002, 0];

        $arrays = $importer->extractGigFieldArrays($gigIds);

        // Order should be [7002, 7001] after deduplication; check every field accordingly
        $expected = [
            'sellerCard__memberSince'                 => [2222222222, 1111111111],
            'sellerCard__responseTime'                => [4, 2],
            'sellerCard__recentDelivery'              => [1710000000000, 1700000000000],
            'overview__gig__rating'                   => [5.0, 4.8],
            'overview__gig__ratingsCount'             => [240, 120],
            'overview__gig__ordersInQueue'            => [0, 1],
            'topNav__gigCollectedCount'               => [20, 10],
            'portfolio__projectsCount'                => [4, 2],
            'seo__description__deliveryTime'          => [3, 1],
            'seo__schemaMarkup__gigOffers__lowPrice'  => ['15.00', '25.00'],
            'seo__schemaMarkup__gigOffers__highPrice' => ['90.00', '60.00'],
            'seller__user__joinedAt'                  => [1500000000, 1600000000],
            'seller__sellerLevel'                     => [2, 1],
            'seller__isPro'                           => [1, 0],
            'seller__rating__count'                   => [7, 5],
            'seller__rating__score'                   => [4.9, 4.6],
            'seller__responseTime__inHours'           => [1, 3],
            'seller__completedOrdersCount'            => [200, 100],
            'seller__sellerLevel___adjusted'          => [3, 3],
        ];

        $fields = array_keys($expected);

        // Ensure all expected fields exist in correct order
        $this->assertSame($fields, array_keys($arrays));

        foreach ($fields as $f) {
            $this->assertArrayHasKey($f, $arrays);
            $this->assertSame($expected[$f], $arrays[$f]);
        }
    }

    public function test_extractGigFieldArrays_empty_and_missing_ids_return_empty_arrays(): void
    {
        $importer = new FiverrJsonImporter();

        $fields = [
            'sellerCard__memberSince',
            'sellerCard__responseTime',
            'sellerCard__recentDelivery',
            'overview__gig__rating',
            'overview__gig__ratingsCount',
            'overview__gig__ordersInQueue',
            'topNav__gigCollectedCount',
            'portfolio__projectsCount',
            'seo__description__deliveryTime',
            'seo__schemaMarkup__gigOffers__lowPrice',
            'seo__schemaMarkup__gigOffers__highPrice',
            'seller__user__joinedAt',
            'seller__sellerLevel',
            'seller__isPro',
            'seller__rating__count',
            'seller__rating__score',
            'seller__responseTime__inHours',
            'seller__completedOrdersCount',
            'seller__sellerLevel___adjusted',
        ];

        // Empty input
        $empty = $importer->extractGigFieldArrays([]);
        $this->assertSame($fields, array_keys($empty));
        foreach ($fields as $k) {
            $this->assertSame([], $empty[$k]);
        }

        // Non-existing id -> arrays remain empty
        $miss = $importer->extractGigFieldArrays([99999999]);
        $this->assertSame($fields, array_keys($miss));
        foreach ($fields as $k) {
            $this->assertSame([], $miss[$k]);
        }
    }

    public function test_computeListingGigFieldAverages_basic(): void
    {
        $importer = new FiverrJsonImporter();

        $listingId = 'L-AVG-1';

        // Seed two gigs
        DB::table($importer->getFiverrGigsTable())->insert([
            [
                'general__gigId'                          => 8101,
                'sellerCard__memberSince'                 => 111,
                'sellerCard__responseTime'                => 2,
                'sellerCard__recentDelivery'              => 1700000000000,
                'overview__gig__rating'                   => 4.6,
                'overview__gig__ratingsCount'             => 10,
                'overview__gig__ordersInQueue'            => 2,
                'topNav__gigCollectedCount'               => 1,
                'portfolio__projectsCount'                => 1,
                'seo__description__deliveryTime'          => '1 day',
                'seo__schemaMarkup__gigOffers__lowPrice'  => '20.00',
                'seo__schemaMarkup__gigOffers__highPrice' => '50.00',
                'seller__user__joinedAt'                  => 1500000000,
                'seller__sellerLevel'                     => 'level_one',
                'seller__isPro'                           => false,
                'seller__rating__count'                   => 5,
                'seller__rating__score'                   => 4.5,
                'seller__responseTime__inHours'           => 3,
                'seller__completedOrdersCount'            => 100,
                'created_at'                              => now(),
                'updated_at'                              => now(),
            ],
            [
                'general__gigId'                          => 8102,
                'sellerCard__memberSince'                 => 222,
                'sellerCard__responseTime'                => 4,
                'sellerCard__recentDelivery'              => 1710000000000,
                'overview__gig__rating'                   => 4.9,
                'overview__gig__ratingsCount'             => 20,
                'overview__gig__ordersInQueue'            => 0,
                'topNav__gigCollectedCount'               => 2,
                'portfolio__projectsCount'                => 2,
                'seo__description__deliveryTime'          => '3 days',
                'seo__schemaMarkup__gigOffers__lowPrice'  => '100.00',
                'seo__schemaMarkup__gigOffers__highPrice' => '200.00',
                'seller__user__joinedAt'                  => 1600000000,
                'seller__sellerLevel'                     => 'level_two',
                'seller__isPro'                           => true,
                'seller__rating__count'                   => 7,
                'seller__rating__score'                   => 4.9,
                'seller__responseTime__inHours'           => 1,
                'seller__completedOrdersCount'            => 200,
                'created_at'                              => now(),
                'updated_at'                              => now(),
            ],
        ]);

        // Seed listings JSON: two gigs with pos 0 and 1
        $listingsPayload = json_encode([
            [
                'gigs' => [
                    ['gigId' => 8101, 'pos' => 0],
                    ['gigId' => 8102, 'pos' => 1],
                ],
            ],
        ]);

        DB::table($importer->getFiverrListingsTable())->insert([
            'listingAttributes__id' => $listingId,
            'listings'              => $listingsPayload,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Compute averages without Cache to ensure fresh read
        $avg = $importer->computeListingGigFieldAverages($listingId, 0, 7, useCache: false);

        // Check all averages
        $expected = [
            'sellerCard__memberSince'                 => 166.5,
            'sellerCard__responseTime'                => 3.0,
            'sellerCard__recentDelivery'              => 1705000000000.0,
            'overview__gig__rating'                   => 4.75,
            'overview__gig__ratingsCount'             => 15.0,
            'overview__gig__ordersInQueue'            => 1.0,
            'topNav__gigCollectedCount'               => 1.5,
            'portfolio__projectsCount'                => 1.5,
            'seo__description__deliveryTime'          => 2.0,
            'seo__schemaMarkup__gigOffers__lowPrice'  => 60.0,
            'seo__schemaMarkup__gigOffers__highPrice' => 125.0,
            'seller__user__joinedAt'                  => 1550000000.0,
            'seller__sellerLevel'                     => 1.5,
            'seller__isPro'                           => 0.5,
            'seller__rating__count'                   => 6.0,
            'seller__rating__score'                   => 4.7,
            'seller__responseTime__inHours'           => 2.0,
            'seller__completedOrdersCount'            => 150.0,
            'seller__sellerLevel___adjusted'          => 3.0,
        ];

        $this->assertSame(array_keys($expected), array_keys($avg));
        foreach ($expected as $k => $v) {
            $this->assertSame($v, $avg[$k]);
        }
    }

    public function test_computeScoresForStatsRow_happy_path(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            'currency__rate'                               => 1.0,
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 2,
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 100,
            'avg___seller__sellerLevel'                    => 1, // should be ignored by score_2
            'avg___seller__sellerLevel___adjusted'         => 2,
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $expectedScore1 = (10.0 / 2.0) * 100.0 * 1.0; // 500.0
        $expectedScore2 = 4.8 * log(100) * (2 * 2);
        $expectedScore3 = $expectedScore1 / $expectedScore2;

        $this->assertSame($expectedScore1, $scores['score_1']);
        $this->assertSame($expectedScore2, $scores['score_2']);
        $this->assertSame($expectedScore3, $scores['score_3']);

        // Pagination total scores when it is not provided
        $this->assertNull($scores['score_4']);
        $this->assertNull($scores['score_5']);
    }

    public function test_computeScoresForStatsRow_score1_null_when_delivery_days_invalid(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            'currency__rate'                               => 1.0,
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 0, // invalid
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 100,
            'avg___seller__sellerLevel___adjusted'         => 2,
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $this->assertNull($scores['score_1']);
        // score_2 should still compute
        $this->assertSame(4.8 * log(100) * (2 * 2), $scores['score_2']);
        $this->assertNull($scores['score_3']); // since score_1 is null

        // Should be null when score_1 is null
        $this->assertNull($scores['score_4']);
        $this->assertNull($scores['score_5']);
    }

    public function test_computeScoresForStatsRow_score1_null_when_currency_rate_missing(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            // currency__rate is missing
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 2,
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 100,
            'avg___seller__sellerLevel___adjusted'         => 2,
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $this->assertNull($scores['score_1']); // Should be null due to missing currency__rate
        // score_2 should still compute since it doesn't need currency__rate
        $this->assertSame(4.8 * log(100) * (2 * 2), $scores['score_2']);
        $this->assertNull($scores['score_3']); // since score_1 is null
    }

    public function test_computeScoresForStatsRow_score1_with_currency_rate_multiplication(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            'currency__rate'                               => 1.5, // Non-unity currency rate
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 2,
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 100,
            'avg___seller__sellerLevel___adjusted'         => 2,
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $expectedScore1 = (10.0 / 2.0) * 100.0 * 1.5; // 750.0
        $expectedScore2 = 4.8 * log(100) * (2 * 2);
        $expectedScore3 = $expectedScore1 / $expectedScore2;

        $this->assertSame($expectedScore1, $scores['score_1']);
        $this->assertSame($expectedScore2, $scores['score_2']);
        $this->assertSame($expectedScore3, $scores['score_3']);
    }

    public function test_computeScoresForStatsRow_score2_null_on_invalid_ratingsCount(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            'currency__rate'                               => 1.0,
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 2,
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 0, // invalid for log
            'avg___seller__sellerLevel___adjusted'         => 2,
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $this->assertSame((10.0 / 2.0) * 100.0 * 1.0, $scores['score_1']);
        $this->assertNull($scores['score_2']);
        $this->assertNull($scores['score_3']);

        // Pagination total scores when it is not provided
        $this->assertNull($scores['score_4']);
        $this->assertNull($scores['score_5']);
    }

    public function test_computeScoresForStatsRow_score3_null_when_score2_zero_due_to_zero_levelAdjusted(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            'currency__rate'                               => 1.0,
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 2,
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 100,
            'avg___seller__sellerLevel___adjusted'         => 0, // forces score_2 = 0
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $this->assertSame((10.0 / 2.0) * 100.0 * 1.0, $scores['score_1']);
        $this->assertSame(0.0, $scores['score_2']);
        $this->assertNull($scores['score_3']);

        // Pagination total scores when it is not provided
        $this->assertNull($scores['score_4']);
        $this->assertNull($scores['score_5']);
    }

    public function test_computeScoresForStatsRow_score4_score5_with_pagination_total(): void
    {
        $importer = new FiverrJsonImporter();

        $row = [
            'currency__rate'                               => 1.0,
            'avg___overview__gig__ordersInQueue'           => 10,
            'avg___seo__description__deliveryTime'         => 2,
            'avg___seo__schemaMarkup__gigOffers__lowPrice' => 100,
            'avg___overview__gig__rating'                  => 4.8,
            'avg___overview__gig__ratingsCount'            => 100,
            'avg___seller__sellerLevel___adjusted'         => 2,
            'appData__pagination__total'                   => 400,
        ];

        $scores = $importer->computeScoresForStatsRow($row);

        $expectedScore1 = (10.0 / 2.0) * 100.0 * 1.0; // 500
        $this->assertSame($expectedScore1 / 400.0, $scores['score_4']);
        $this->assertSame($expectedScore1 / sqrt(400.0), $scores['score_5']);
    }

    public function test_processGigsStatsBatch_updates_stats_for_listing_with_in_range_gigs(): void
    {
        $importer = new FiverrJsonImporter();

        $listingId = 'UT_L1';

        // Seed gigs (two gigs)
        DB::table($importer->getFiverrGigsTable())->insert([
            [
                'general__gigId'                          => 91001,
                'sellerCard__memberSince'                 => 100,
                'sellerCard__responseTime'                => 2,
                'sellerCard__recentDelivery'              => 1700000000000,
                'overview__gig__rating'                   => 4.5,
                'overview__gig__ratingsCount'             => 10,
                'overview__gig__ordersInQueue'            => 2,
                'topNav__gigCollectedCount'               => 1,
                'portfolio__projectsCount'                => 2,
                'seo__description__deliveryTime'          => '1 day',
                'seo__schemaMarkup__gigOffers__lowPrice'  => '50.00',
                'seo__schemaMarkup__gigOffers__highPrice' => '100.00',
                'seller__user__joinedAt'                  => 1500000000,
                'seller__sellerLevel'                     => 'level_one',
                'seller__isPro'                           => false,
                'seller__rating__count'                   => 5,
                'seller__rating__score'                   => 4.5,
                'seller__responseTime__inHours'           => 3,
                'seller__completedOrdersCount'            => 100,
                'created_at'                              => now(),
                'updated_at'                              => now(),
            ],
            [
                'general__gigId'                          => 91002,
                'sellerCard__memberSince'                 => 233,
                'sellerCard__responseTime'                => 4,
                'sellerCard__recentDelivery'              => 1710000000000,
                'overview__gig__rating'                   => 5.0,
                'overview__gig__ratingsCount'             => 20,
                'overview__gig__ordersInQueue'            => 0,
                'topNav__gigCollectedCount'               => 2,
                'portfolio__projectsCount'                => 1,
                'seo__description__deliveryTime'          => '3 days',
                'seo__schemaMarkup__gigOffers__lowPrice'  => '70.00',
                'seo__schemaMarkup__gigOffers__highPrice' => '150.00',
                'seller__user__joinedAt'                  => 1600000000,
                'seller__sellerLevel'                     => 'level_two',
                'seller__isPro'                           => true,
                'seller__rating__count'                   => 7,
                'seller__rating__score'                   => 4.9,
                'seller__responseTime__inHours'           => 1,
                'seller__completedOrdersCount'            => 200,
                'created_at'                              => now(),
                'updated_at'                              => now(),
            ],
        ]);

        // Seed listings JSON with positions 0 and 1
        $listingsPayload = json_encode([
            ['gigs' => [['gigId' => 91001, 'pos' => 0], ['gigId' => 91002, 'pos' => 1]]],
        ]);

        DB::table($importer->getFiverrListingsTable())->insert([
            'currency__rate'          => 1.0,
            'listingAttributes__id'   => $listingId,
            'listings'                => $listingsPayload,
            'stats_processed_at'      => now(), // candidate
            'gigs_stats_processed_at' => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
        $lrowForStats = DB::table($importer->getFiverrListingsTable())
            ->where('listingAttributes__id', $listingId)
            ->orderByDesc('id')
            ->first();

        // Ensure listings_gigs has processed rows so the join includes this listing
        DB::table($importer->getFiverrListingsGigsTable())->insert([
            [
                'listingAttributes__id' => $listingId,
                'gigId'                 => 91001,
                'processed_at'          => now(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'listingAttributes__id' => $listingId,
                'gigId'                 => 91002,
                'processed_at'          => now(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);

        // Seed a stats row to be updated
        DB::table($importer->getFiverrListingsStatsTable())->insert([
            'listingAttributes__id'  => $listingId,
            'fiverr_listings_row_id' => $lrowForStats->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $res = $importer->processGigsStatsBatch(batchSize: 10, low: 0, high: 7, useCache: false);
        $this->assertSame(1, $res['rows_processed']);
        $this->assertSame(1, $res['updated']);
        $this->assertSame(0, $res['skipped']);

        $row = DB::table($importer->getFiverrListingsStatsTable())->where('listingAttributes__id', $listingId)->first();
        $this->assertNotNull($row);

        // Verify a representative json___ field preserves position order
        $ratings = array_map('floatval', json_decode($row->{'json___overview__gig__rating'}, true));
        $this->assertSame([4.5, 5.0], $ratings);

        // Verify a representative avg___ field
        $this->assertSame(4.75, (float) $row->{'avg___overview__gig__rating'});

        // Verify scores (approx)
        $this->assertSame(30.0, (float) $row->{'score_1'});
        $this->assertEqualsWithDelta(115.77, (float) $row->{'score_2'}, 0.1);
        $this->assertEqualsWithDelta(0.259, (float) $row->{'score_3'}, 0.005);

        // Verify the source listing got marked as processed
        $lrow = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', $listingId)->first();
        $this->assertNotNull($lrow->{'gigs_stats_processed_at'});
        $status = json_decode($lrow->{'gigs_stats_processed_status'}, true);
        $this->assertSame($listingId, $status['listingAttributes__id']);
        $this->assertSame(1, $status['updated']);
    }

    public function test_processGigsStatsBatch_skips_when_no_gigs_in_window(): void
    {
        $importer = new FiverrJsonImporter();

        $listingId = 'UT_L2';

        // Seed gigs table
        DB::table($importer->getFiverrGigsTable())->insert([
            [
                'general__gigId'                 => 92001,
                'overview__gig__rating'          => 4.0,
                'seo__description__deliveryTime' => '10 days',
                'created_at'                     => now(),
                'updated_at'                     => now(),
            ],
        ]);

        // Seed listings JSON with positions outside [0,1]
        $listingsPayload = json_encode([
            ['gigs' => [['gigId' => 92001, 'pos' => 10]]],
        ]);

        DB::table($importer->getFiverrListingsTable())->insert([
            'listingAttributes__id'   => $listingId,
            'listings'                => $listingsPayload,
            'stats_processed_at'      => now(),
            'gigs_stats_processed_at' => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
        $lrowForStats2 = DB::table($importer->getFiverrListingsTable())
            ->where('listingAttributes__id', $listingId)
            ->orderByDesc('id')
            ->first();

        DB::table($importer->getFiverrListingsGigsTable())->insert([
            'listingAttributes__id' => $listingId,
            'gigId'                 => 92001,
            'processed_at'          => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Seed stats row to be updated (should end up skipped)
        DB::table($importer->getFiverrListingsStatsTable())->insert([
            'listingAttributes__id'  => $listingId,
            'fiverr_listings_row_id' => $lrowForStats2->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $res = $importer->processGigsStatsBatch(batchSize: 10, low: 0, high: 1, useCache: false);
        $this->assertSame(1, $res['rows_processed']);
        $this->assertSame(0, $res['updated']);
        $this->assertSame(1, $res['skipped']);

        $lrow = DB::table($importer->getFiverrListingsTable())->where('listingAttributes__id', $listingId)->first();
        $this->assertNotNull($lrow->{'gigs_stats_processed_at'});
        $status = json_decode($lrow->{'gigs_stats_processed_status'}, true);
        $this->assertSame('no_gigs_in_window', $status['reason']);
        $this->assertSame($listingId, $status['listingAttributes__id']);
        $this->assertSame(0, $status['updated']);
    }

    public function test_processGigsStatsBatch_empty_batch_returns_zeros(): void
    {
        $importer = new FiverrJsonImporter();
        $res      = $importer->processGigsStatsBatch(batchSize: 10, useCache: false);
        $this->assertSame(0, $res['rows_processed']);
        $this->assertSame(0, $res['updated']);
        $this->assertSame(0, $res['skipped']);
    }

    public function test_processGigsStatsAll_empty_returns_zeros(): void
    {
        $importer = new FiverrJsonImporter();
        $res      = $importer->processGigsStatsAll(batchSize: 5, useCache: false);
        $this->assertSame(0, $res['rows_processed']);
        $this->assertSame(0, $res['updated']);
        $this->assertSame(0, $res['skipped']);
    }

    public function test_processGigsStatsAll_processes_multiple_batches(): void
    {
        $importer = new FiverrJsonImporter();

        // Create three candidate listings, but force batchSize=2 so we need >1 iteration
        $cases = [
            [
                'listingId' => 'UT_ALL_1',
                'gigs'      => [93001, 93002],
            ],
            [
                'listingId' => 'UT_ALL_2',
                'gigs'      => [94001, 94002],
            ],
            [
                'listingId' => 'UT_ALL_3',
                'gigs'      => [95001, 95002],
            ],
        ];

        // Seed gigs (2 per listing) with representative fields used by scoring
        foreach ($cases as $idx => $c) {
            $g1 = $c['gigs'][0];
            $g2 = $c['gigs'][1];
            DB::table($importer->getFiverrGigsTable())->insert([
                [
                    'general__gigId'                          => $g1,
                    'sellerCard__memberSince'                 => 100 + $idx,
                    'sellerCard__responseTime'                => 2,
                    'sellerCard__recentDelivery'              => 1700000000000,
                    'overview__gig__rating'                   => 4.5,
                    'overview__gig__ratingsCount'             => 10,
                    'overview__gig__ordersInQueue'            => 2,
                    'topNav__gigCollectedCount'               => 1,
                    'portfolio__projectsCount'                => 2,
                    'seo__description__deliveryTime'          => '1 day',
                    'seo__schemaMarkup__gigOffers__lowPrice'  => '50.00',
                    'seo__schemaMarkup__gigOffers__highPrice' => '100.00',
                    'seller__user__joinedAt'                  => 1500000000,
                    'seller__sellerLevel'                     => 'level_one',
                    'seller__isPro'                           => false,
                    'seller__rating__count'                   => 5,
                    'seller__rating__score'                   => 4.5,
                    'seller__responseTime__inHours'           => 3,
                    'seller__completedOrdersCount'            => 100,
                    'created_at'                              => now(),
                    'updated_at'                              => now(),
                ],
                [
                    'general__gigId'                          => $g2,
                    'sellerCard__memberSince'                 => 233 + $idx,
                    'sellerCard__responseTime'                => 4,
                    'sellerCard__recentDelivery'              => 1710000000000,
                    'overview__gig__rating'                   => 5.0,
                    'overview__gig__ratingsCount'             => 20,
                    'overview__gig__ordersInQueue'            => 0,
                    'topNav__gigCollectedCount'               => 2,
                    'portfolio__projectsCount'                => 1,
                    'seo__description__deliveryTime'          => '3 days',
                    'seo__schemaMarkup__gigOffers__lowPrice'  => '70.00',
                    'seo__schemaMarkup__gigOffers__highPrice' => '150.00',
                    'seller__user__joinedAt'                  => 1600000000,
                    'seller__sellerLevel'                     => 'level_two',
                    'seller__isPro'                           => true,
                    'seller__rating__count'                   => 7,
                    'seller__rating__score'                   => 4.9,
                    'seller__responseTime__inHours'           => 1,
                    'seller__completedOrdersCount'            => 200,
                    'created_at'                              => now(),
                    'updated_at'                              => now(),
                ],
            ]);

            // Seed listing with positions 0 and 1
            $listingsPayload = json_encode([
                ['gigs' => [['gigId' => $g1, 'pos' => 0], ['gigId' => $g2, 'pos' => 1]]],
            ]);

            DB::table($importer->getFiverrListingsTable())->insert([
                'listingAttributes__id'   => $c['listingId'],
                'listings'                => $listingsPayload,
                'stats_processed_at'      => now(),
                'gigs_stats_processed_at' => null,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
            $lrow = DB::table($importer->getFiverrListingsTable())
                ->where('listingAttributes__id', $c['listingId'])
                ->orderByDesc('id')
                ->first();

            // listings_gigs join rows (one per gig)
            DB::table($importer->getFiverrListingsGigsTable())->insert([
                [
                    'listingAttributes__id' => $c['listingId'],
                    'gigId'                 => $g1,
                    'processed_at'          => now(),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ],
                [
                    'listingAttributes__id' => $c['listingId'],
                    'gigId'                 => $g2,
                    'processed_at'          => now(),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ],
            ]);

            // stats row to be updated
            DB::table($importer->getFiverrListingsStatsTable())->insert([
                'listingAttributes__id'  => $c['listingId'],
                'fiverr_listings_row_id' => $lrow->id,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }

        // Run with batchSize 2 to require multiple iterations
        $res = $importer->processGigsStatsAll(batchSize: 2, low: 0, high: 7, useCache: false);
        $this->assertSame(3, $res['rows_processed']);
        $this->assertSame(3, $res['updated']);
        $this->assertSame(0, $res['skipped']);

        // All three listings should be marked processed
        foreach ($cases as $c) {
            $lrow = DB::table($importer->getFiverrListingsTable())
                ->where('listingAttributes__id', $c['listingId'])
                ->orderByDesc('id')
                ->first();
            $this->assertNotNull($lrow->{'gigs_stats_processed_at'});
        }
    }

    public function test_processGigsStatsAll_single_batch_completes(): void
    {
        $importer = new FiverrJsonImporter();

        $cases = [
            ['listingId' => 'UT_ALL_SB_1', 'gigs' => [96001, 96002]],
            ['listingId' => 'UT_ALL_SB_2', 'gigs' => [97001, 97002]],
        ];

        foreach ($cases as $idx => $c) {
            $g1 = $c['gigs'][0];
            $g2 = $c['gigs'][1];

            // gigs
            DB::table($importer->getFiverrGigsTable())->insert([
                [
                    'general__gigId'                          => $g1,
                    'sellerCard__memberSince'                 => 100 + $idx,
                    'sellerCard__responseTime'                => 2,
                    'sellerCard__recentDelivery'              => 1700000000000,
                    'overview__gig__rating'                   => 4.5,
                    'overview__gig__ratingsCount'             => 10,
                    'overview__gig__ordersInQueue'            => 2,
                    'topNav__gigCollectedCount'               => 1,
                    'portfolio__projectsCount'                => 2,
                    'seo__description__deliveryTime'          => '1 day',
                    'seo__schemaMarkup__gigOffers__lowPrice'  => '50.00',
                    'seo__schemaMarkup__gigOffers__highPrice' => '100.00',
                    'seller__user__joinedAt'                  => 1500000000,
                    'seller__sellerLevel'                     => 'level_one',
                    'seller__isPro'                           => false,
                    'seller__rating__count'                   => 5,
                    'seller__rating__score'                   => 4.5,
                    'seller__responseTime__inHours'           => 3,
                    'seller__completedOrdersCount'            => 100,
                    'created_at'                              => now(),
                    'updated_at'                              => now(),
                ],
                [
                    'general__gigId'                          => $g2,
                    'sellerCard__memberSince'                 => 233 + $idx,
                    'sellerCard__responseTime'                => 4,
                    'sellerCard__recentDelivery'              => 1710000000000,
                    'overview__gig__rating'                   => 5.0,
                    'overview__gig__ratingsCount'             => 20,
                    'overview__gig__ordersInQueue'            => 0,
                    'topNav__gigCollectedCount'               => 2,
                    'portfolio__projectsCount'                => 1,
                    'seo__description__deliveryTime'          => '3 days',
                    'seo__schemaMarkup__gigOffers__lowPrice'  => '70.00',
                    'seo__schemaMarkup__gigOffers__highPrice' => '150.00',
                    'seller__user__joinedAt'                  => 1600000000,
                    'seller__sellerLevel'                     => 'level_two',
                    'seller__isPro'                           => true,
                    'seller__rating__count'                   => 7,
                    'seller__rating__score'                   => 4.9,
                    'seller__responseTime__inHours'           => 1,
                    'seller__completedOrdersCount'            => 200,
                    'created_at'                              => now(),
                    'updated_at'                              => now(),
                ],
            ]);

            // listing with positions 0,1
            $listingsPayload = json_encode([
                ['gigs' => [['gigId' => $g1, 'pos' => 0], ['gigId' => $g2, 'pos' => 1]]],
            ]);
            DB::table($importer->getFiverrListingsTable())->insert([
                'listingAttributes__id'   => $c['listingId'],
                'listings'                => $listingsPayload,
                'stats_processed_at'      => now(),
                'gigs_stats_processed_at' => null,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
            $lrow = DB::table($importer->getFiverrListingsTable())
                ->where('listingAttributes__id', $c['listingId'])
                ->orderByDesc('id')
                ->first();

            DB::table($importer->getFiverrListingsGigsTable())->insert([
                [
                    'listingAttributes__id' => $c['listingId'],
                    'gigId'                 => $g1,
                    'processed_at'          => now(),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ],
                [
                    'listingAttributes__id' => $c['listingId'],
                    'gigId'                 => $g2,
                    'processed_at'          => now(),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ],
            ]);

            DB::table($importer->getFiverrListingsStatsTable())->insert([
                'listingAttributes__id'  => $c['listingId'],
                'fiverr_listings_row_id' => $lrow->id,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }

        // batchSize large enough to finish in one iteration
        $res = $importer->processGigsStatsAll(batchSize: 10, low: 0, high: 7, useCache: false);
        $this->assertSame(2, $res['rows_processed']);
        $this->assertSame(2, $res['updated']);
        $this->assertSame(0, $res['skipped']);

        foreach ($cases as $c) {
            $lrow = DB::table($importer->getFiverrListingsTable())
                ->where('listingAttributes__id', $c['listingId'])
                ->orderByDesc('id')
                ->first();
            $this->assertNotNull($lrow->{'gigs_stats_processed_at'});
        }
    }
}
