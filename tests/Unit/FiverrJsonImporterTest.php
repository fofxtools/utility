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

    public function test_importListingsJson_inserts_row(): void
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
            $res = $importer->importListingsJson($json);
            $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
            $this->assertSame(1, DB::table($importer->getFiverrListingsTable())->count());
        } finally {
            unlink($json);
        }
    }

    public function test_importGigJson_inserts_row(): void
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
            $res = $importer->importGigJson($json);
            $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
            $this->assertSame(1, DB::table($importer->getFiverrGigsTable())->count());
        } finally {
            unlink($json);
        }
    }

    public function test_importSellerProfileJson_inserts_row(): void
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
            $res = $importer->importSellerProfileJson($json);
            $this->assertSame(['inserted' => 1, 'skipped' => 0], $res);
            $this->assertSame(1, DB::table($importer->getFiverrSellerProfilesTable())->count());
        } finally {
            unlink($json);
        }
    }

    public function test_resetListingsProcessed_clears_flags_and_returns_count(): void
    {
        $importer = new FiverrJsonImporter();

        // Seed 2 listings via importer to ensure schema and minimal required fields
        $payload1 = ['listingAttributes' => ['id' => 'L10'], 'listings' => [['gigs' => []]]];
        $payload2 = ['listingAttributes' => ['id' => 'L11'], 'listings' => [['gigs' => []]]];
        $json1    = tempnam(sys_get_temp_dir(), 'importer_json_');
        $json2    = tempnam(sys_get_temp_dir(), 'importer_json_');
        file_put_contents($json1, json_encode($payload1));
        file_put_contents($json2, json_encode($payload2));

        try {
            $importer->importListingsJson($json1);
            $importer->importListingsJson($json2);
        } finally {
            unlink($json1);
            unlink($json2);
        }

        // Mark them processed
        DB::table($importer->getFiverrListingsTable())->update([
            'processed_at'     => now(),
            'processed_status' => json_encode(['x' => 1], $importer->getJsonFlags()),
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

    public function test_resetListingsProcessed_noop_returns_zero(): void
    {
        $importer = new FiverrJsonImporter();
        $updated  = $importer->resetListingsProcessed();
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
                $importer->importListingsJson($json);
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
            $importer->importListingsJson($json);
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
            $importer->importListingsJson($json);
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
            $importer->importListingsJson($json);
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
                $importer->importListingsJson($json);
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
        ];
    }

    #[DataProvider('provider_getSellerLevelNumeric')]
    public function test_getSellerLevelNumeric(string $input, int $expected): void
    {
        $importer = new FiverrJsonImporter();
        $this->assertSame($expected, $importer->getSellerLevelNumeric($input));
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
}
