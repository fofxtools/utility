<?php

declare(strict_types=1);

namespace FOfX\Utility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Importer for decoding Fiverr embedded JSON and inserting into fiverr_* tables.
 * - Reads JSON from file
 * - Maps JSON to table columns using "__" delimiter
 * - JSON-encodes complex values for TEXT/MEDIUMTEXT/LONGTEXT columns
 * - Inserts rows using insertOrIgnore
 */
class FiverrJsonImporter
{
    protected array $excludedColumns      = ['id', 'created_at', 'updated_at', 'processed_at', 'processed_status'];
    protected int $jsonFlags              = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    protected string $columnPathDelimiter = '__';

    protected string $fiverrListingsTable       = 'fiverr_listings';
    protected string $fiverrGigsTable           = 'fiverr_gigs';
    protected string $fiverrSellerProfilesTable = 'fiverr_seller_profiles';
    protected string $fiverrListingsGigsTable   = 'fiverr_listings_gigs';
    protected string $fiverrListingsStatsTable  = 'fiverr_listings_stats';

    protected string $fiverrListingsMigrationPath       = __DIR__ . '/../database/migrations/2025_09_05_175701_create_fiverr_listings_table.php';
    protected string $fiverrGigsMigrationPath           = __DIR__ . '/../database/migrations/2025_09_06_005341_create_fiverr_gigs_table.php';
    protected string $fiverrSellerProfilesMigrationPath = __DIR__ . '/../database/migrations/2025_09_06_021235_create_fiverr_seller_profiles_table.php';
    protected string $fiverrListingsGigsMigrationPath   = __DIR__ . '/../database/migrations/2025_09_06_030326_create_fiverr_listings_gigs_table.php';
    protected string $fiverrListingsStatsMigrationPath  = __DIR__ . '/../database/migrations/2025_09_10_003356_create_fiverr_listings_stats_table.php';

    public function getExcludedColumns(): array
    {
        return $this->excludedColumns;
    }

    public function setExcludedColumns(array $columns): void
    {
        $this->excludedColumns = array_values($columns);
    }

    public function getJsonFlags(): int
    {
        return $this->jsonFlags;
    }

    public function setJsonFlags(int $flags): void
    {
        $this->jsonFlags = $flags;
    }

    public function getColumnPathDelimiter(): string
    {
        return $this->columnPathDelimiter;
    }

    public function setColumnPathDelimiter(string $columnPathDelimiter): void
    {
        $this->columnPathDelimiter = $columnPathDelimiter;
    }

    public function getFiverrListingsTable(): string
    {
        return $this->fiverrListingsTable;
    }

    public function setFiverrListingsTable(string $name): void
    {
        $this->fiverrListingsTable = $name;
    }

    public function getFiverrGigsTable(): string
    {
        return $this->fiverrGigsTable;
    }

    public function setFiverrGigsTable(string $name): void
    {
        $this->fiverrGigsTable = $name;
    }

    public function getFiverrSellerProfilesTable(): string
    {
        return $this->fiverrSellerProfilesTable;
    }

    public function setFiverrSellerProfilesTable(string $name): void
    {
        $this->fiverrSellerProfilesTable = $name;
    }

    public function getFiverrListingsGigsTable(): string
    {
        return $this->fiverrListingsGigsTable;
    }

    public function setFiverrListingsGigsTable(string $name): void
    {
        $this->fiverrListingsGigsTable = $name;
    }

    public function getFiverrListingsMigrationPath(): string
    {
        return $this->fiverrListingsMigrationPath;
    }

    public function setFiverrListingsMigrationPath(string $path): void
    {
        $this->fiverrListingsMigrationPath = $path;
    }

    public function getFiverrGigsMigrationPath(): string
    {
        return $this->fiverrGigsMigrationPath;
    }

    public function setFiverrGigsMigrationPath(string $path): void
    {
        $this->fiverrGigsMigrationPath = $path;
    }

    public function getFiverrSellerProfilesMigrationPath(): string
    {
        return $this->fiverrSellerProfilesMigrationPath;
    }

    public function setFiverrSellerProfilesMigrationPath(string $path): void
    {
        $this->fiverrSellerProfilesMigrationPath = $path;
    }

    public function getFiverrListingsGigsMigrationPath(): string
    {
        return $this->fiverrListingsGigsMigrationPath;
    }

    public function getFiverrListingsStatsTable(): string
    {
        return $this->fiverrListingsStatsTable;
    }

    public function setFiverrListingsStatsTable(string $name): void
    {
        $this->fiverrListingsStatsTable = $name;
    }

    public function getFiverrListingsStatsMigrationPath(): string
    {
        return $this->fiverrListingsStatsMigrationPath;
    }

    public function setFiverrListingsStatsMigrationPath(string $path): void
    {
        $this->fiverrListingsStatsMigrationPath = $path;
    }

    public function setFiverrListingsGigsMigrationPath(string $path): void
    {
        $this->fiverrListingsGigsMigrationPath = $path;
    }

    /**
     * Load and decode a JSON file as an associative array.
     *
     * @throws \RuntimeException when file is missing or JSON is invalid
     */
    public function loadJsonFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("JSON file not found: {$path}");
        }
        $raw  = file_get_contents($path);
        $data = json_decode($raw ?: 'null', true);
        if (!is_array($data)) {
            throw new \RuntimeException("Failed to decode JSON: {$path}");
        }

        return $data;
    }

    /**
     * Return all column names for a table.
     * Uses Schema facade for driver-agnostic listing.
     *
     * @return string[]
     */
    public function getTableColumns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    /**
     * Return names of columns that are a TEXT-like type for the active driver.
     * This is used to decide which values should be JSON-encoded when complex.
     *
     * @throws \InvalidArgumentException When table name is invalid
     *
     * @return string[]
     */
    public function getTextColumns(string $table): array
    {
        // Validate table name for SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select("SHOW COLUMNS FROM {$table} WHERE Type LIKE '%text%'");

            return array_map(fn ($r) => $r->Field, $rows);
        }

        if ($driver === 'sqlite' || $driver === 'sqlite_memory') {
            // PRAGMA table_info returns: cid, name, type, notnull, dflt_value, pk
            $rows = DB::select("PRAGMA table_info('{$table}')");
            $out  = [];
            foreach ($rows as $r) {
                $type = strtoupper((string)($r->type ?? ''));
                if (str_contains($type, 'TEXT')) {
                    $out[] = (string) $r->name;
                }
            }

            return $out;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema='public' AND table_name = ?",
                [$table]
            );
            $out = [];
            foreach ($rows as $r) {
                $type = strtolower((string)($r->data_type ?? ''));
                if (in_array($type, ['text', 'json', 'jsonb'], true)) {
                    $out[] = (string) $r->column_name;
                }
            }

            return $out;
        }

        if ($driver === 'sqlsrv') {
            $rows = DB::select(
                'SELECT c.name AS column_name, t.name AS data_type FROM sys.columns c JOIN sys.types t ON c.user_type_id=t.user_type_id WHERE c.object_id = OBJECT_ID(?)',
                [$table]
            );
            $out = [];
            foreach ($rows as $r) {
                $type = strtolower((string)($r->data_type ?? ''));
                if (in_array($type, ['text', 'ntext', 'varchar', 'nvarchar'], true)) {
                    // Treat wide char types as potential JSON blobs when long
                    $out[] = (string) $r->column_name;
                }
            }

            return $out;
        }

        // Fallback: nothing
        return [];
    }

    /**
     * Remove excluded (auto-managed) columns from a set of columns.
     *
     * @param string[] $columns
     *
     * @return string[]
     */
    public function removeExcludedColumns(array $columns): array
    {
        return array_values(array_diff($columns, $this->excludedColumns));
    }

    /**
     * Given decoded JSON data and target columns, map values by path and JSON-encode complex values
     * for text columns. Does not modify processed_status even if it is text.
     *
     * @param array<string,mixed> $data
     * @param string[]            $columns
     * @param string[]            $textColumns
     *
     * @return array<string,mixed>
     */
    public function extractAndEncode(array $data, array $columns, array $textColumns): array
    {
        $mapped = extract_values_by_paths($data, $columns, $this->columnPathDelimiter);

        // Encode complex values for text columns (except processed_status)
        $encodeSet = array_values(array_diff($textColumns, ['processed_status']));
        foreach ($encodeSet as $col) {
            if (array_key_exists($col, $mapped)) {
                $val = $mapped[$col];
                if (is_array($val) || is_object($val)) {
                    $mapped[$col] = json_encode($val, $this->jsonFlags);
                }
            }
        }

        return $mapped;
    }

    /**
     * Normalize row input to a list of rows.
     *
     * Accepts either a single associative row (column => value) or a list of rows,
     * and always returns an array<int, array<string,mixed>> suitable for bulk insert.
     *
     * Examples:
     * - ['col' => 'val'] => [ ['col' => 'val'] ]
     * - [ ['col' => 'a'], ['col' => 'b'] ] => [ ['col' => 'a'], ['col' => 'b'] ]
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $data Row or list of rows
     *
     * @return array<int,array<string,mixed>> Normalized list of rows
     */
    protected function normalizeRows(array $data): array
    {
        // Accept single row (assoc) or list of rows; normalize to list using PHP 8.1+ array_is_list()
        if (!array_is_list($data)) {
            return [$data];
        }

        return $data;
    }

    /**
     * Insert one or more rows into a table using insertOrIgnore for dedup safety.
     *
     * Updates created_at and updated_at columns if the row doesn't define them.
     *
     * @param string                                             $table
     * @param array<int,array<string,mixed>>|array<string,mixed> $data  Row or list of rows
     *
     * @return array{inserted:int,skipped:int}
     */
    public function insertRows(string $table, array $data): array
    {
        $rows = $this->normalizeRows($data);
        if (empty($rows)) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        // Attach timestamps if the target table has them and the row doesn't define them
        $now = now();
        foreach ($rows as &$row) {
            if (!array_key_exists('created_at', $row)) {
                $row['created_at'] = $now;
            }
            if (!array_key_exists('updated_at', $row)) {
                $row['updated_at'] = $now;
            }
        }
        unset($row); // Unset since we passed by reference

        $count    = count($rows);
        $inserted = DB::table($table)->insertOrIgnore($rows);
        $skipped  = $count - (int) $inserted;

        return ['inserted' => (int) $inserted, 'skipped' => $skipped];
    }

    /**
     * Import a Fiverr listings JSON file into fiverr_listings.
     *
     * @param string $jsonPath Absolute or relative path to the JSON file
     *
     * @return array{inserted:int,skipped:int}
     */
    public function importListingsJson(string $jsonPath): array
    {
        ensure_table_exists($this->fiverrListingsTable, $this->fiverrListingsMigrationPath);
        $data     = $this->loadJsonFile($jsonPath);
        $columns  = $this->removeExcludedColumns($this->getTableColumns($this->fiverrListingsTable));
        $textCols = $this->getTextColumns($this->fiverrListingsTable);
        $payload  = $this->extractAndEncode($data, $columns, $textCols);

        return $this->insertRows($this->fiverrListingsTable, $payload);
    }

    /**
     * Import a Fiverr gig JSON file into fiverr_gigs.
     *
     * @param string $jsonPath Absolute or relative path to the JSON file
     *
     * @return array{inserted:int,skipped:int}
     */
    public function importGigJson(string $jsonPath): array
    {
        ensure_table_exists($this->fiverrGigsTable, $this->fiverrGigsMigrationPath);
        $data     = $this->loadJsonFile($jsonPath);
        $columns  = $this->removeExcludedColumns($this->getTableColumns($this->fiverrGigsTable));
        $textCols = $this->getTextColumns($this->fiverrGigsTable);
        $payload  = $this->extractAndEncode($data, $columns, $textCols);

        return $this->insertRows($this->fiverrGigsTable, $payload);
    }

    /**
     * Import a Fiverr seller profile JSON file into fiverr_seller_profiles.
     *
     * @param string $jsonPath Absolute or relative path to the JSON file
     *
     * @return array{inserted:int,skipped:int}
     */
    public function importSellerProfileJson(string $jsonPath): array
    {
        ensure_table_exists($this->fiverrSellerProfilesTable, $this->fiverrSellerProfilesMigrationPath);
        $data     = $this->loadJsonFile($jsonPath);
        $columns  = $this->removeExcludedColumns($this->getTableColumns($this->fiverrSellerProfilesTable));
        $textCols = $this->getTextColumns($this->fiverrSellerProfilesTable);
        $payload  = $this->extractAndEncode($data, $columns, $textCols);

        return $this->insertRows($this->fiverrSellerProfilesTable, $payload);
    }

    /**
     * Reset processeding status for listings so they can be re-processed.
     * Sets processed_at and processed_status to null for all rows.
     *
     * @return int Number of rows updated
     */
    public function resetListingsProcessed(): int
    {
        $updated = DB::table($this->fiverrListingsTable)
            ->update([
                'processed_at'     => null,
                'processed_status' => null,
            ]);

        Log::debug('Reset processed status for fiverr_listings', ['rows_updated' => $updated]);

        return $updated;
    }

    /**
     * Process a batch of unprocessed listings into fiverr_listings_gigs.
     *
     * - Read listings[0].gigs and insert into fiverr_listings_gigs.
     * - Uses whereNull('processed_at') with limit.
     * - Updates the source fiverr_listings row processed_* fields with per-row stats.
     *
     * @param int $batchSize
     *
     * @return array{rows_processed:int,gigs_seen:int,inserted:int}
     */
    public function processListingsGigsBatch(int $batchSize = 100): array
    {
        ensure_table_exists($this->fiverrListingsGigsTable, $this->fiverrListingsGigsMigrationPath);

        $targetColumns  = $this->removeExcludedColumns($this->getTableColumns($this->fiverrListingsGigsTable));
        $targetTextCols = $this->getTextColumns($this->fiverrListingsGigsTable);

        $rows = DB::table($this->fiverrListingsTable)
            ->select(['id', 'listings'])
            ->whereNull('processed_at')
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            return ['rows_processed' => 0, 'gigs_seen' => 0, 'inserted' => 0];
        }

        $totalRows = 0;
        $totalGigs = 0;
        $totalIns  = 0;

        foreach ($rows as $r) {
            $rowStats = [
                'listing_id' => $r->id,
                'gigs_seen'  => 0,
                'inserted'   => 0,
                'ignored'    => 0,
                'errors'     => [],
            ];

            $gigs = [];
            if (is_string($r->listings) && $r->listings !== '') {
                $decoded = json_decode($r->listings, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (isset($decoded[0]['gigs']) && is_array($decoded[0]['gigs'])) {
                        $gigs = $decoded[0]['gigs'];
                    }
                } else {
                    $rowStats['errors'][] = 'Invalid listings JSON';
                }
            }

            $rowStats['gigs_seen'] = count($gigs);

            $batch = [];
            foreach ($gigs as $g) {
                $batch[] = $this->extractAndEncode($g, $targetColumns, $targetTextCols);
            }

            $ins = 0;
            if (!empty($batch)) {
                $res = $this->insertRows($this->fiverrListingsGigsTable, $batch);
                $ins = (int) $res['inserted'];
            }

            $rowStats['inserted'] = $ins;
            $rowStats['ignored']  = max(0, $rowStats['gigs_seen'] - $ins);

            DB::table($this->fiverrListingsTable)->where('id', $r->id)->update([
                'processed_at'     => now(),
                'processed_status' => json_encode($rowStats, $this->jsonFlags),
            ]);

            $totalRows++;
            $totalGigs += $rowStats['gigs_seen'];
            $totalIns += $ins;
        }

        return [
            'rows_processed' => $totalRows,
            'gigs_seen'      => $totalGigs,
            'inserted'       => $totalIns,
        ];
    }

    public function processListingsGigsAll(int $batchSize = 100): array
    {
        $grandRows = 0;
        $grandGigs = 0;
        $grandIns  = 0;

        do {
            $stats = $this->processListingsGigsBatch($batchSize);
            $grandRows += $stats['rows_processed'];
            $grandGigs += $stats['gigs_seen'];
            $grandIns += $stats['inserted'];
        } while ($stats['rows_processed'] > 0);

        return [
            'rows_processed' => $grandRows,
            'gigs_seen'      => $grandGigs,
            'inserted'       => $grandIns,
        ];
    }

    /**
     * Map seller level string to numeric value.
     * 'na' => 0, 'level_one_seller' => 1, 'level_two_seller' => 2, 'top_rated_seller' => 3
     *
     * @param string $level Raw seller level string
     *
     * @return int Numeric level in [0..3]
     */
    public function getSellerLevelNumeric(string $level): int
    {
        $map = [
            'na'               => 0,
            'level_one_seller' => 1,
            'level_two_seller' => 2,
            'top_rated_seller' => 3,
        ];

        $key = strtolower(trim($level));

        return $map[$key] ?? 0;
    }

    /**
     * Calculate counts and weighted average of seller levels.
     *
     * Each item's $field is mapped via getSellerLevelNumeric() and contributes 1 weight
     * to the average. Returns bucket counts and the weighted average.
     *
     * @param array<int,array<string,mixed>> $items List of items (e.g., gigs or facet buckets)
     * @param string                         $field Field name that holds the seller level string (default 'seller_level')
     *
     * @return array{na:int,level_one:int,level_two:int,top_rated:int,weighted_avg:float|null}
     */
    public function calculateSellerLevelStats(array $items, string $field = 'seller_level'): array
    {
        $counts = ['na' => 0, 'level_one' => 0, 'level_two' => 0, 'top_rated' => 0];
        $sum    = 0.0;
        $total  = 0;

        foreach ($items as $it) {
            $lvl = (string) ($it[$field] ?? 'na');
            $num = $this->getSellerLevelNumeric($lvl);
            $sum += $num;
            $total++;

            switch ($num) {
                case 3:
                    $counts['top_rated']++;

                    break;
                case 2:
                    $counts['level_two']++;

                    break;
                case 1:
                    $counts['level_one']++;

                    break;
                default:
                    $counts['na']++;

                    break;
            }
        }

        $avg = $total > 0 ? $sum / $total : null;

        return [
            'na'           => $counts['na'],
            'level_one'    => $counts['level_one'],
            'level_two'    => $counts['level_two'],
            'top_rated'    => $counts['top_rated'],
            'weighted_avg' => $avg,
        ];
    }

    /**
     * Extract a facet count from an array of objects like: [{ id: 'X', count: N }, ...]
     *
     * @param array<int,array<string,mixed>> $facets   Array of facet objects
     * @param string                         $targetId The id to match
     *
     * @return int|null Integer count when found (0 if present but non-integer), null when not present
     */
    public function extractFacetCount(array $facets, string $targetId): ?int
    {
        foreach ($facets as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === $targetId) {
                $cnt = $row['count'] ?? null;
                if (is_int($cnt)) {
                    return $cnt;
                }
                if (is_numeric($cnt)) {
                    return (int) $cnt;
                }

                return 0; // present but non-integer
            }
        }

        return null; // not present
    }

    /**
     * Normalize gig type to one of the supported buckets.
     * Returns one of: 'promoted_gigs','fiverr_choice','fixed_pricing','pro','missing','other'
     *
     * @param string|null $type Raw type string
     *
     * @return string Normalized bucket name
     */
    public function categorizeGigType(?string $type): string
    {
        if ($type === null || trim($type) === '') {
            return 'missing';
        }

        $val = strtolower(trim($type));

        return in_array($val, ['promoted_gigs', 'fiverr_choice', 'fixed_pricing', 'pro'], true)
            ? $val
            : 'other';
    }

    /**
     * Compute a single stats row from a fiverr_listings row.
     *
     * Decodes the row's JSON fields (listings, facets, price buckets) and computes all counts and
     * weighted averages as described in the fiverr_listings_stats migration comments. Also copies
     * through scalar context fields from the listings row.
     *
     * @param array<string,mixed> $listingsRow Listings row as an associative array
     *
     * @return array<string,mixed> Prepared stats row ready for insertion into fiverr_listings_stats
     */
    public function computeListingsStatsRow(array $listingsRow): array
    {
        // Base copy-through fields from listings row
        $out = [
            'fiverr_listings_row_id'                        => (int) ($listingsRow['id'] ?? 0),
            'listingAttributes__id'                         => $listingsRow['listingAttributes__id'] ?? null,
            'currency__rate'                                => $listingsRow['currency__rate'] ?? null,
            'rawListingData__has_more'                      => $listingsRow['rawListingData__has_more'] ?? null,
            'countryCode'                                   => $listingsRow['countryCode'] ?? null,
            'assumedLanguage'                               => $listingsRow['assumedLanguage'] ?? null,
            'v2__report__search_total_results'              => $listingsRow['v2__report__search_total_results'] ?? null,
            'appData__pagination__page'                     => $listingsRow['appData__pagination__page'] ?? null,
            'appData__pagination__page_size'                => $listingsRow['appData__pagination__page_size'] ?? null,
            'appData__pagination__total'                    => $listingsRow['appData__pagination__total'] ?? null,
            'tracking__isNonExperiential'                   => $listingsRow['tracking__isNonExperiential'] ?? null,
            'tracking__fiverrChoiceGigPosition'             => $listingsRow['tracking__fiverrChoiceGigPosition'] ?? null,
            'tracking__hasFiverrChoiceGigs'                 => $listingsRow['tracking__hasFiverrChoiceGigs'] ?? null,
            'tracking__hasPromotedGigs'                     => $listingsRow['tracking__hasPromotedGigs'] ?? null,
            'tracking__promotedGigsCount'                   => $listingsRow['tracking__promotedGigsCount'] ?? null,
            'tracking__searchAutoComplete__is_autocomplete' => $listingsRow['tracking__searchAutoComplete__is_autocomplete'] ?? null,
        ];

        // Decode listings JSON and get gigs list
        $gigs = [];
        if (!empty($listingsRow['listings']) && is_string($listingsRow['listings'])) {
            $decoded = json_decode($listingsRow['listings'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['gigs']) && is_array($decoded[0]['gigs'])) {
                    $gigs = $decoded[0]['gigs'];
                }
            }
        }

        // Utility closures
        $addNum = static function (array &$arr, $v): void {
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                $arr[] = (float) $v;
            }
        };
        $avg = static function (array $nums): ?float {
            $n = count($nums);
            if ($n === 0) {
                return null;
            }

            return array_sum($nums) / $n;
        };

        // Initialize counters
        $typeCounts = [
            'promoted_gigs' => 0,
            'fiverr_choice' => 0,
            'fixed_pricing' => 0,
            'pro'           => 0,
            'missing'       => 0,
            'other'         => 0,
        ];
        $cnt_is_fiverr_choice          = 0;
        $cnt_packages_rec_extra_fast   = 0;
        $cnt_is_pro                    = 0;
        $cnt_is_featured               = 0;
        $cnt_seller_online             = 0;
        $cnt_offer_consultation        = 0;
        $cnt_personalized_pricing_fail = 0;
        $cnt_has_recurring_option      = 0;
        $cnt_is_seller_unavailable     = 0;
        $cnt_extra_fast                = 0;

        $nums_rec_price      = [];
        $nums_rec_duration   = [];
        $nums_rec_price_tier = [];
        $nums_buy_rev_cnt    = [];
        $nums_buy_rev        = [];
        $nums_seller_cnt     = [];
        $nums_seller_score   = [];
        $nums_price_i        = [];
        $nums_package_i      = [];
        $nums_num_packages   = [];

        $sellerLevelItems = [];

        foreach ($gigs as $g) {
            if (!is_array($g)) {
                continue;
            }
            // Type buckets
            $type   = $g['type'] ?? null;
            $bucket = $this->categorizeGigType(is_string($type) ? $type : null);
            $typeCounts[$bucket]++;

            // Booleans / counts
            if (!empty($g['is_fiverr_choice'])) {
                $cnt_is_fiverr_choice++;
            }
            if (!empty($g['is_pro'])) {
                $cnt_is_pro++;
            }
            if (!empty($g['is_featured'])) {
                $cnt_is_featured++;
            }
            if (!empty($g['seller_online'])) {
                $cnt_seller_online++;
            }
            if (!empty($g['offer_consultation'])) {
                $cnt_offer_consultation++;
            }
            if (!empty($g['personalized_pricing_fail'])) {
                $cnt_personalized_pricing_fail++;
            }
            if (!empty($g['has_recurring_option'])) {
                $cnt_has_recurring_option++;
            }
            if (!empty($g['is_seller_unavailable'])) {
                $cnt_is_seller_unavailable++;
            }
            if (!empty($g['extra_fast'])) {
                $cnt_extra_fast++;
            }

            // Packages recommended
            $rec = $g['packages']['recommended'] ?? null;
            if (is_array($rec)) {
                if (!empty($rec['extra_fast'])) {
                    $cnt_packages_rec_extra_fast++;
                }
                $addNum($nums_rec_price, $rec['price'] ?? null);
                $addNum($nums_rec_duration, $rec['duration'] ?? null);
                $addNum($nums_rec_price_tier, $rec['price_tier'] ?? null);
            }

            // Averages
            $addNum($nums_buy_rev_cnt, $g['buying_review_rating_count'] ?? null);
            $addNum($nums_buy_rev, $g['buying_review_rating'] ?? null);
            if (isset($g['seller_rating']) && is_array($g['seller_rating'])) {
                $addNum($nums_seller_cnt, $g['seller_rating']['count'] ?? null);
                $addNum($nums_seller_score, $g['seller_rating']['score'] ?? null);
            }
            $addNum($nums_price_i, $g['price_i'] ?? null);
            $addNum($nums_package_i, $g['package_i'] ?? null);
            $addNum($nums_num_packages, $g['num_of_packages'] ?? null);

            // Seller level item
            $sellerLevelItems[] = ['seller_level' => (string) ($g['seller_level'] ?? 'na')];
        }

        // Map type counts
        $out['cnt___listings__gigs__type___promoted_gigs'] = $typeCounts['promoted_gigs'];
        $out['cnt___listings__gigs__type___fiverr_choice'] = $typeCounts['fiverr_choice'];
        $out['cnt___listings__gigs__type___fixed_pricing'] = $typeCounts['fixed_pricing'];
        $out['cnt___listings__gigs__type___pro']           = $typeCounts['pro'];
        $out['cnt___listings__gigs__type___missing']       = $typeCounts['missing'];
        $out['cnt___listings__gigs__type___other']         = $typeCounts['other'];

        // Other counts
        $out['cnt___listings__gigs__is_fiverr_choice']                  = $cnt_is_fiverr_choice;
        $out['cnt___listings__gigs__packages__recommended__extra_fast'] = $cnt_packages_rec_extra_fast;
        $out['cnt___listings__gigs__is_pro']                            = $cnt_is_pro;
        $out['cnt___listings__gigs__is_featured']                       = $cnt_is_featured;
        $out['cnt___listings__gigs__seller_online']                     = $cnt_seller_online;
        $out['cnt___listings__gigs__offer_consultation']                = $cnt_offer_consultation;
        $out['cnt___listings__gigs__personalized_pricing_fail']         = $cnt_personalized_pricing_fail;
        $out['cnt___listings__gigs__has_recurring_option']              = $cnt_has_recurring_option;
        $out['cnt___listings__gigs__is_seller_unavailable']             = $cnt_is_seller_unavailable;
        $out['cnt___listings__gigs__extra_fast']                        = $cnt_extra_fast;

        // Averages from gigs
        $out['avg___listings__gigs__packages__recommended__price']      = $avg($nums_rec_price);
        $out['avg___listings__gigs__packages__recommended__duration']   = $avg($nums_rec_duration);
        $out['avg___listings__gigs__packages__recommended__price_tier'] = $avg($nums_rec_price_tier);
        $out['avg___listings__gigs__buying_review_rating_count']        = $avg($nums_buy_rev_cnt);
        $out['avg___listings__gigs__buying_review_rating']              = $avg($nums_buy_rev);
        $out['avg___listings__gigs__seller_rating__count']              = $avg($nums_seller_cnt);
        $out['avg___listings__gigs__seller_rating__score']              = $avg($nums_seller_score);
        $out['avg___listings__gigs__price_i']                           = $avg($nums_price_i);
        $out['avg___listings__gigs__package_i']                         = $avg($nums_package_i);
        $out['avg___listings__gigs__num_of_packages']                   = $avg($nums_num_packages);

        // Seller level stats from gigs
        $lvlStats                                                     = $this->calculateSellerLevelStats($sellerLevelItems, 'seller_level');
        $out['cnt___listings__gigs__seller_level___na']               = $lvlStats['na'];
        $out['cnt___listings__gigs__seller_level___level_one_seller'] = $lvlStats['level_one'];
        $out['cnt___listings__gigs__seller_level___level_two_seller'] = $lvlStats['level_two'];
        $out['cnt___listings__gigs__seller_level___top_rated_seller'] = $lvlStats['top_rated'];
        $out['avg___listings__gigs__seller_level']                    = $lvlStats['weighted_avg'];

        // Facets
        $decodeJsonArray = static function ($text) {
            if (is_string($text) && $text !== '') {
                $arr = json_decode($text, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
                    return $arr;
                }
            }

            return [];
        };

        $fac_has_hourly       = $decodeJsonArray($listingsRow['facets__has_hourly'] ?? null);
        $fac_is_agency        = $decodeJsonArray($listingsRow['facets__is_agency'] ?? null);
        $fac_is_pa_online     = $decodeJsonArray($listingsRow['facets__is_pa_online'] ?? null);
        $fac_is_seller_online = $decodeJsonArray($listingsRow['facets__is_seller_online'] ?? null);
        $fac_pro              = $decodeJsonArray($listingsRow['facets__pro'] ?? null);
        $fac_seller_language  = $decodeJsonArray($listingsRow['facets__seller_language'] ?? null);
        $fac_seller_level     = $decodeJsonArray($listingsRow['facets__seller_level'] ?? null);
        $fac_seller_location  = $decodeJsonArray($listingsRow['facets__seller_location'] ?? null);
        $fac_service_offering = $decodeJsonArray($listingsRow['facets__service_offerings'] ?? null);

        $out['facets__has_hourly___true___count']       = $this->extractFacetCount($fac_has_hourly, 'true');
        $out['facets__is_agency___true___count']        = $this->extractFacetCount($fac_is_agency, 'true');
        $out['facets__is_pa_online___true___count']     = $this->extractFacetCount($fac_is_pa_online, 'true');
        $out['facets__is_seller_online___true___count'] = $this->extractFacetCount($fac_is_seller_online, 'true');
        $out['facets__pro___true___count']              = $this->extractFacetCount($fac_pro, 'true');
        $out['facets__seller_language___en___count']    = $this->extractFacetCount($fac_seller_language, 'en');

        $fac_lvl_na  = $this->extractFacetCount($fac_seller_level, 'na') ?? 0;
        $fac_lvl_one = $this->extractFacetCount($fac_seller_level, 'level_one_seller') ?? 0;
        $fac_lvl_two = $this->extractFacetCount($fac_seller_level, 'level_two_seller') ?? 0;
        $fac_lvl_top = $this->extractFacetCount($fac_seller_level, 'top_rated_seller') ?? 0;
        $fac_lvl_sum = $fac_lvl_na + $fac_lvl_one + $fac_lvl_two + $fac_lvl_top;

        $out['facets__seller_level___na___count']               = $fac_lvl_na;
        $out['facets__seller_level___level_one_seller___count'] = $fac_lvl_one;
        $out['facets__seller_level___level_two_seller___count'] = $fac_lvl_two;
        $out['facets__seller_level___top_rated_seller___count'] = $fac_lvl_top;
        $out['avg___facets___seller_level']                     = $fac_lvl_sum > 0
            ? (0 * $fac_lvl_na + 1 * $fac_lvl_one + 2 * $fac_lvl_two + 3 * $fac_lvl_top) / $fac_lvl_sum
            : null;

        $out['facets__seller_location___us___count'] = $this->extractFacetCount($fac_seller_location, 'US');

        $out['facets__service_offerings__offer_consultation___count'] = $this->extractFacetCount($fac_service_offering, 'offer_consultation');
        $out['facets__service_offerings__subscription___count']       = $this->extractFacetCount($fac_service_offering, 'subscription');

        // Price buckets
        $priceBuckets                          = $decodeJsonArray($listingsRow['priceBucketsSkeleton'] ?? null);
        $out['priceBucketsSkeleton___0___max'] = isset($priceBuckets[0]['max']) && is_numeric($priceBuckets[0]['max']) ? (int) $priceBuckets[0]['max'] : null;
        $out['priceBucketsSkeleton___1___max'] = isset($priceBuckets[1]['max']) && is_numeric($priceBuckets[1]['max']) ? (int) $priceBuckets[1]['max'] : null;
        $out['priceBucketsSkeleton___2___max'] = isset($priceBuckets[2]['max']) && is_numeric($priceBuckets[2]['max']) ? (int) $priceBuckets[2]['max'] : null;

        return $out;
    }
}
