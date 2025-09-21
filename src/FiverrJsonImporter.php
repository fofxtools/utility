<?php

declare(strict_types=1);

namespace FOfX\Utility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
     * Reset stats processing status for listings so they can be re-processed for stats.
     * Sets stats_processed_at and stats_processed_status to null for all rows.
     *
     * @return int Number of rows updated
     */
    public function resetListingsStatsProcessed(): int
    {
        $updated = DB::table($this->fiverrListingsTable)
            ->update([
                'stats_processed_at'     => null,
                'stats_processed_status' => null,
            ]);

        Log::debug('Reset stats processed status for fiverr_listings', ['rows_updated' => $updated]);

        return $updated;
    }

    /**
     * Reset gigs stats processing status for listings so they can be re-processed for gigs stats.
     * Sets gigs_stats_processed_at and gigs_stats_processed_status to null for all rows in fiverr_listings.
     *
     * @return int Number of rows updated
     */
    public function resetListingsGigsStatsProcessed(): int
    {
        $updated = DB::table($this->fiverrListingsTable)
            ->update([
                'gigs_stats_processed_at'     => null,
                'gigs_stats_processed_status' => null,
            ]);

        Log::debug('Reset gigs stats processed status for fiverr_listings', ['rows_updated' => $updated]);

        return $updated;
    }

    /**
     * Reset processing status for gigs so they can be re-processed.
     * Sets processed_at and processed_status to null for all rows in fiverr_gigs.
     *
     * @return int Number of rows updated
     */
    public function resetGigsProcessed(): int
    {
        $updated = DB::table($this->fiverrGigsTable)
            ->update([
                'processed_at'     => null,
                'processed_status' => null,
            ]);

        Log::debug('Reset processed status for fiverr_gigs', ['rows_updated' => $updated]);

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
            ->select([
                'id',
                'listings',
                'listingAttributes__id',
                'displayData__categoryName',
                'displayData__subCategoryName',
                'displayData__nestedSubCategoryName',
                'displayData__cachedSlug',
                'displayData__name',
            ])
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

            // Parent listing-derived fields to overlay into each gig row (if missing)
            $parentOverlay = [
                'listingAttributes__id'              => $r->listingAttributes__id ?? null,
                'displayData__categoryName'          => $r->displayData__categoryName ?? null,
                'displayData__subCategoryName'       => $r->displayData__subCategoryName ?? null,
                'displayData__nestedSubCategoryName' => $r->displayData__nestedSubCategoryName ?? null,
                'displayData__cachedSlug'            => $r->displayData__cachedSlug ?? null,
                'displayData__name'                  => $r->displayData__name ?? null,
            ];

            $batch = [];
            foreach ($gigs as $g) {
                $row = $this->extractAndEncode($g, $targetColumns, $targetTextCols);

                foreach ($parentOverlay as $col => $val) {
                    $row[$col] = $val;
                }

                $batch[] = $row;
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

        Log::debug('Starting processListingsGigsAll...', ['batchSize' => $batchSize]);

        do {
            $stats = $this->processListingsGigsBatch($batchSize);
            $grandRows += $stats['rows_processed'];
            $grandGigs += $stats['gigs_seen'];
            $grandIns += $stats['inserted'];
        } while ($stats['rows_processed'] > 0);

        Log::debug('Completed processListingsGigsAll', [
            'rows_processed' => $grandRows,
            'gigs_seen'      => $grandGigs,
            'inserted'       => $grandIns,
        ]);

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
            // In fiverr_gigs seller__sellerLevel
            'new_seller' => 0,
            'level_one'  => 1,
            'level_two'  => 2,
            'level_trs'  => 3,
        ];

        $key = strtolower(trim($level));

        return $map[$key] ?? 0;
    }

    /**
     * Adjust seller level using Fiverr thresholds and return max(existing, inferred).
     *
     * Thresholds (inclusive):
     * - TR (3): rating >= 4.7 and count >= 40
     * - Level 2 (2): rating >= 4.6 and count >= 20
     * - Level 1 (1): rating >= 4.4 and count >= 5
     *
     * If $rating is null, infer from count only: 40→3, 20→2, 5→1, else 0.
     */
    public function getSellerLevelAdjusted(int $sellerLevel, int $count, ?float $rating = null): int
    {
        $inferred = 0;

        if ($rating === null) {
            if ($count >= 40) {
                $inferred = 3;
            } elseif ($count >= 20) {
                $inferred = 2;
            } elseif ($count >= 5) {
                $inferred = 1;
            } else {
                $inferred = 0;
            }
        } else {
            if ($rating >= 4.7 && $count >= 40) {
                $inferred = 3;
            } elseif ($rating >= 4.6 && $count >= 20) {
                $inferred = 2;
            } elseif ($rating >= 4.4 && $count >= 5) {
                $inferred = 1;
            } else {
                $inferred = 0;
            }
        }

        return max($sellerLevel, $inferred);
    }

    /**
     * Calculate counts and weighted average of seller levels.
     *
     * Reads the 'seller_level' string from each item, maps it via getSellerLevelNumeric(),
     * and averages with equal weight per item. Returns bucket counts and the weighted average.
     *
     * @param array<int,array<string,mixed>> $items List of items (e.g., gigs)
     *
     * @return array{na:int,level_one:int,level_two:int,top_rated:int,weighted_avg:float|null}
     */
    public function calculateSellerLevelStats(array $items): array
    {
        $counts = ['na' => 0, 'level_one' => 0, 'level_two' => 0, 'top_rated' => 0];
        $sum    = 0.0;
        $total  = 0;

        foreach ($items as $it) {
            $lvl = (string) ($it['seller_level'] ?? 'na');
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
     * Calculate adjusted seller levels for gigs and their average.
     *
     * For each gig:
     * - Gets the existing numeric seller level
     * - Reads seller reviews count from seller_rating.count (missing => 0)
     * - Computes adjusted = getSellerLevelAdjusted(existing, count, null)
     * - Returns the per-gig adjusted values and their average
     *
     * @param array<int,array<string,mixed>> $gigs
     * @param bool                           $useRatings When true, pass seller_rating.score to getSellerLevelAdjusted; otherwise pass null
     *
     * @return array{values:array<int,int>,avg:float|null}
     */
    public function calculateSellerLevelAdjustedStats(array $gigs, bool $useRatings = false): array
    {
        $values = [];
        $sum    = 0.0;

        foreach ($gigs as $g) {
            $lvlRaw = $g['seller_level'] ?? null;
            $lvlNum = is_string($lvlRaw) ? $this->getSellerLevelNumeric($lvlRaw) : 0;

            $cnt    = 0;
            $rating = null;
            if (isset($g['seller_rating']) && is_array($g['seller_rating'])) {
                $c = $g['seller_rating']['count'] ?? null;
                if (is_numeric($c)) {
                    $cnt = (int) $c;
                }
                $r = $g['seller_rating']['score'] ?? null;
                if (is_numeric($r)) {
                    $rating = (float) $r;
                }
            }

            $adj      = $this->getSellerLevelAdjusted($lvlNum, $cnt, $useRatings ? $rating : null);
            $values[] = $adj;
            $sum += $adj;
        }

        $avg = count($values) > 0 ? $sum / count($values) : null;

        return ['values' => $values, 'avg' => $avg];
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
        // Pre-fill output with all stats keys in migration file column order (null by default)
        $orderedKeys = [
            'fiverr_listings_row_id',
            'listingAttributes__id',

            // Category information
            'categoryIds__categoryId',
            'categoryIds__subCategoryId',
            'categoryIds__nestedSubCategoryId',
            'displayData__categoryName',
            'displayData__subCategoryName',
            'displayData__nestedSubCategoryName',
            'displayData__cachedSlug',
            'displayData__name',

            // General information
            'currency__rate',
            'rawListingData__has_more',
            'countryCode',
            'assumedLanguage',
            'v2__report__search_total_results',
            'appData__pagination__page',
            'appData__pagination__page_size',
            'appData__pagination__total',

            // Gig type counts
            'cnt___listings__gigs__type___promoted_gigs',
            'cnt___listings__gigs__type___fiverr_choice',
            'cnt___listings__gigs__type___fixed_pricing',
            'cnt___listings__gigs__type___pro',
            'cnt___listings__gigs__type___missing',
            'cnt___listings__gigs__type___other',

            // Other gig counts and averages
            'cnt___listings__gigs__is_fiverr_choice',
            'cnt___listings__gigs__packages__recommended__extra_fast',
            'avg___listings__gigs__packages__recommended__price',
            'avg___listings__gigs__packages__recommended__duration',
            'avg___listings__gigs__packages__recommended__price_tier',
            'cnt___listings__gigs__is_pro',
            'cnt___listings__gigs__is_featured',
            'cnt___listings__gigs__seller_online',
            'cnt___listings__gigs__offer_consultation',
            'cnt___listings__gigs__personalized_pricing_fail',
            'cnt___listings__gigs__has_recurring_option',
            'avg___listings__gigs__buying_review_rating_count',
            'avg___listings__gigs__buying_review_rating',

            // Seller level (from gigs)
            'cnt___listings__gigs__seller_level___na',
            'cnt___listings__gigs__seller_level___level_one_seller',
            'cnt___listings__gigs__seller_level___level_two_seller',
            'cnt___listings__gigs__seller_level___top_rated_seller',
            'avg___listings__gigs__seller_level',

            // Remaining listings fields
            'avg___listings__gigs__seller_rating__count',
            'avg___listings__gigs__seller_rating__score',
            'cnt___listings__gigs__is_seller_unavailable',
            'avg___listings__gigs__price_i',
            'avg___listings__gigs__package_i',
            'cnt___listings__gigs__extra_fast',
            'avg___listings__gigs__num_of_packages',

            // Facets
            'facets__has_hourly___true___count',
            'facets__is_agency___true___count',
            'facets__is_pa_online___true___count',
            'facets__is_seller_online___true___count',
            'facets__pro___true___count',
            'facets__seller_language___en___count',
            'facets__seller_level___na___count',
            'facets__seller_level___level_one_seller___count',
            'facets__seller_level___level_two_seller___count',
            'facets__seller_level___top_rated_seller___count',
            'avg___facets___seller_level',
            'facets__seller_location___us___count',
            'facets__service_offerings__offer_consultation___count',
            'facets__service_offerings__subscription___count',

            // Price buckets
            'priceBucketsSkeleton___0___max',
            'priceBucketsSkeleton___1___max',
            'priceBucketsSkeleton___2___max',

            // Tracking (copy-through)
            'tracking__isNonExperiential',
            'tracking__fiverrChoiceGigPosition',
            'tracking__hasFiverrChoiceGigs',
            'tracking__hasPromotedGigs',
            'tracking__promotedGigsCount',
            'tracking__searchAutoComplete__is_autocomplete',
        ];

        $out = array_fill_keys($orderedKeys, null);

        // Base copy-through fields from listings row
        $out['fiverr_listings_row_id']                        = (int) ($listingsRow['id'] ?? 0);
        $out['listingAttributes__id']                         = $listingsRow['listingAttributes__id'] ?? null;
        $out['categoryIds__categoryId']                       = $listingsRow['categoryIds__categoryId'] ?? null;
        $out['categoryIds__subCategoryId']                    = $listingsRow['categoryIds__subCategoryId'] ?? null;
        $out['categoryIds__nestedSubCategoryId']              = $listingsRow['categoryIds__nestedSubCategoryId'] ?? null;
        $out['displayData__categoryName']                     = $listingsRow['displayData__categoryName'] ?? null;
        $out['displayData__subCategoryName']                  = $listingsRow['displayData__subCategoryName'] ?? null;
        $out['displayData__nestedSubCategoryName']            = $listingsRow['displayData__nestedSubCategoryName'] ?? null;
        $out['displayData__cachedSlug']                       = $listingsRow['displayData__cachedSlug'] ?? null;
        $out['displayData__name']                             = $listingsRow['displayData__name'] ?? null;
        $out['currency__rate']                                = $listingsRow['currency__rate'] ?? null;
        $out['rawListingData__has_more']                      = $listingsRow['rawListingData__has_more'] ?? null;
        $out['countryCode']                                   = $listingsRow['countryCode'] ?? null;
        $out['assumedLanguage']                               = $listingsRow['assumedLanguage'] ?? null;
        $out['v2__report__search_total_results']              = $listingsRow['v2__report__search_total_results'] ?? null;
        $out['appData__pagination__page']                     = $listingsRow['appData__pagination__page'] ?? null;
        $out['appData__pagination__page_size']                = $listingsRow['appData__pagination__page_size'] ?? null;
        $out['appData__pagination__total']                    = $listingsRow['appData__pagination__total'] ?? null;
        $out['tracking__isNonExperiential']                   = $listingsRow['tracking__isNonExperiential'] ?? null;
        $out['tracking__fiverrChoiceGigPosition']             = $listingsRow['tracking__fiverrChoiceGigPosition'] ?? null;
        $out['tracking__hasFiverrChoiceGigs']                 = $listingsRow['tracking__hasFiverrChoiceGigs'] ?? null;
        $out['tracking__hasPromotedGigs']                     = $listingsRow['tracking__hasPromotedGigs'] ?? null;
        $out['tracking__promotedGigsCount']                   = $listingsRow['tracking__promotedGigsCount'] ?? null;
        $out['tracking__searchAutoComplete__is_autocomplete'] = $listingsRow['tracking__searchAutoComplete__is_autocomplete'] ?? null;

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
            $sellerLevelItems[] = ['seller_level' => (string) ($g['seller_level'] ?? null)];
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
        $lvlStats                                                     = $this->calculateSellerLevelStats($sellerLevelItems);
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

    /**
     * Process a batch of unprocessed listings into fiverr_listings_stats.
     *
     * - Reads listings row fields (listings, facets, price buckets, tracking, etc.)
     * - Computes a stats row via computeListingsStatsRow()
     * - Inserts into fiverr_listings_stats using insertOrIgnore
     * - Updates the source fiverr_listings row stats_processed_* fields
     *
     * @param int $batchSize
     *
     * @return array{rows_processed:int,inserted:int,skipped:int}
     */
    public function processListingsStatsBatch(int $batchSize = 100): array
    {
        ensure_table_exists($this->fiverrListingsStatsTable, $this->fiverrListingsStatsMigrationPath);
        ensure_table_exists($this->fiverrListingsTable, $this->fiverrListingsMigrationPath);

        $rows = DB::table($this->fiverrListingsTable)
            ->whereNull('stats_processed_at')
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            return ['rows_processed' => 0, 'inserted' => 0, 'skipped' => 0];
        }

        $inserted  = 0;
        $skipped   = 0;
        $processed = 0;

        foreach ($rows as $r) {
            // Convert stdClass to array and compute stats row
            $listingsRow = (array) $r;
            $statsRow    = $this->computeListingsStatsRow($listingsRow);

            // Insert into stats table
            $res = $this->insertRows($this->fiverrListingsStatsTable, $statsRow);
            $inserted += (int) $res['inserted'];
            $skipped += (int) $res['skipped'];

            // Update source row processed markers
            DB::table($this->fiverrListingsTable)->where('id', $r->id)->update([
                'stats_processed_at'     => now(),
                'stats_processed_status' => json_encode(
                    [
                        'row_id'   => (int) $r->id,
                        'inserted' => (int) $res['inserted'],
                        'skipped'  => (int) $res['skipped'],
                    ],
                    $this->jsonFlags
                ),
            ]);

            $processed++;
        }

        return ['rows_processed' => $processed, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Process all unprocessed listings into fiverr_listings_stats in batches.
     *
     * @param int $batchSize
     *
     * @return array{rows_processed:int,inserted:int,skipped:int}
     */
    public function processListingsStatsAll(int $batchSize = 100): array
    {
        $grandProcessed = 0;
        $grandInserted  = 0;
        $grandSkipped   = 0;

        Log::debug('Starting processListingsStatsAll...', ['batchSize' => $batchSize]);

        do {
            $res = $this->processListingsStatsBatch($batchSize);
            $grandProcessed += $res['rows_processed'];
            $grandInserted += $res['inserted'];
            $grandSkipped += $res['skipped'];
        } while ($res['rows_processed'] > 0);

        Log::debug('Completed processListingsStatsAll', [
            'rows_processed' => $grandProcessed,
            'inserted'       => $grandInserted,
            'skipped'        => $grandSkipped,
        ]);

        return [
            'rows_processed' => $grandProcessed,
            'inserted'       => $grandInserted,
            'skipped'        => $grandSkipped,
        ];
    }

    /**
     * Build and cache a map of listingAttributes__id => decoded listings array.
     *
     * @param int  $chunkSize         Chunk size for DB query
     * @param bool $useCache          Use Laravel Cache (default true)
     * @param bool $forceCacheRebuild Force cache rebuild even if cache is present (default false)
     *
     * @return array<string,array<mixed>>
     */
    public function getAllListingsData(int $chunkSize = 100, bool $useCache = true, bool $forceCacheRebuild = false): array
    {
        // Using Laravel Cache for type-safe serialization
        $cacheKey = 'utility:all_listings_data:cache';

        $build = function () use ($chunkSize): array {
            // Build map from DB: listingAttributes__id => decoded listings JSON
            $map = [];
            DB::table($this->fiverrListingsTable)
                ->select(['id', 'listingAttributes__id', 'listings'])
                ->orderBy('id', 'asc')
                ->chunkById($chunkSize, function ($rows) use (&$map) {
                    foreach ($rows as $r) {
                        $listingId = $r->listingAttributes__id ?? null;

                        if ($listingId === null) {
                            continue;
                        }

                        if (!is_string($r->listings) || $r->listings === '') {
                            continue;
                        }

                        $decoded = json_decode($r->listings, true);

                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                            continue;
                        }

                        // Use string key to be explicit, but either string/int works in PHP array keys
                        $map[(string) $listingId] = $decoded;
                    }
                }, 'id');

            return $map;
        };

        if (!$useCache) {
            return $build();
        }

        if ($forceCacheRebuild) {
            Cache::forget($cacheKey);
        }

        return Cache::rememberForever($cacheKey, $build);
    }

    /**
     * Build and cache a map of listingId => [pos => gigId].
     *
     * Source of truth is getAllListingsData(). For each listing we iterate its gigs and
     * record the gigId at each position. Inner arrays are sorted by position (ascending).
     * When reading from Redis, numeric position keys are cast back to integers.
     *
     * @param bool $useCache          Use Laravel Cache (default true)
     * @param bool $forceCacheRebuild Force cache rebuild even if cache is present (default false)
     *
     * @return array<string,array<int,int>> listingId => [pos => gigId]
     */
    public function getListingToGigPositionsMap(bool $useCache = true, bool $forceCacheRebuild = false): array
    {
        // Using Laravel Cache for type-safe serialization
        $cacheKey = 'utility:listing_gig_pos:by_pos:cache';

        $build = function () use ($useCache, $forceCacheRebuild): array {
            // Build from getAllListingsData()
            $data = $this->getAllListingsData(useCache: $useCache, forceCacheRebuild: $forceCacheRebuild);

            $map = [];
            foreach ($data as $listingId => $decoded) {
                if (!isset($decoded[0]['gigs']) || !is_array($decoded[0]['gigs'])) {
                    continue;
                }

                $lid = (string) $listingId;
                foreach ($decoded[0]['gigs'] as $g) {
                    if (!isset($g['gigId'])) {
                        continue;
                    }

                    $pos = $g['pos'] ?? null;
                    if ($pos === null) {
                        continue;
                    }

                    $p             = (int) $pos;
                    $gid           = (int) $g['gigId'];
                    $map[$lid][$p] = $gid;
                }

                if (isset($map[$lid])) {
                    ksort($map[$lid]);
                }
            }

            return $map;
        };

        if (!$useCache) {
            return $build();
        }

        if ($forceCacheRebuild) {
            Cache::forget($cacheKey);
        }

        return Cache::rememberForever($cacheKey, $build);
    }

    /**
     * Build and cache a mapping of gigId => [listingId => pos] for gigs whose 'pos' (position) is within [low, high].
     *
     * Notes:
     * - If the same gig appears multiple times within the same listing, we keep the minimum position for that listing.
     * - The inner arrays are not guaranteed to be sorted; callers may sort by position if needed.
     * - Forcing cache rebuild cascades to the base listing map as well.
     *
     * @param int  $low               Low position (inclusive)
     * @param int  $high              High position (inclusive)
     * @param bool $useCache          Use Laravel Cache (default true)
     * @param bool $forceCacheRebuild Force cache rebuild even if cache is present (default false)
     *
     * @return array<int,array<string,int>> gigId => [listingId => pos]
     */
    public function getGigIdToListingIdMapBetweenPositions(int $low, int $high, bool $useCache = true, bool $forceCacheRebuild = false): array
    {
        // Validate range
        if ($low > $high) {
            throw new \InvalidArgumentException("Invalid position range: [{$low}, {$high}]");
        }

        // Using Laravel Cache; derive from listing-centric map
        $cacheKey = "utility:gig_to_listings_pos:{$low}:{$high}:cache";

        $build = function () use ($low, $high, $useCache, $forceCacheRebuild): array {
            $out       = [];
            $byListing = $this->getListingToGigPositionsMap($useCache, $forceCacheRebuild);

            foreach ($byListing as $listingId => $posMap) {
                foreach ($posMap as $pos => $gigId) {
                    $p = (int) $pos;

                    if ($p < $low || $p > $high) {
                        continue;
                    }

                    $gid = (int) $gigId;
                    $lid = (string) $listingId;

                    // Keep minimum position for each listing
                    if (!isset($out[$gid][$lid])) {
                        $out[$gid][$lid] = $p;
                    } else {
                        $out[$gid][$lid] = min($out[$gid][$lid], $p);
                    }
                }
            }

            return $out;
        };

        if (!$useCache) {
            return $build();
        }

        if ($forceCacheRebuild) {
            Cache::forget($cacheKey);
        }

        return Cache::rememberForever($cacheKey, $build);
    }

    /**
     * Parse a delivery time string like "1 day" or "3 days" into integer days.
     *
     * - Returns null for null/empty/non-numeric inputs
     * - Extracts the first integer found in the string and returns it
     *
     * @param string|null $s
     *
     * @return int|null Number of days, or null if not parseable
     */
    public function parseDeliveryTimeDays(?string $s): ?int
    {
        if ($s === null) {
            return null;
        }

        $s = trim($s);
        if ($s === '') {
            return null;
        }

        if (preg_match('/(\d+)/', $s, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extract arrays of values for selected fiverr_gigs columns for a given list of gigIds.
     *
     * This is a compute-only helper: it does not write to any table. The returned arrays
     * can later be JSON-encoded by a separate method when persisting into stats.
     *
     * @param array<int,int|string> $gigIds List of gig IDs (from general__gigId)
     *
     * @return array<string,array<int,mixed>> Map of column => list of values in the same order as input gigIds
     */
    public function extractGigFieldArrays(array $gigIds): array
    {
        // Normalize and de-duplicate gig IDs while preserving input order
        $seen    = [];
        $ordered = [];

        foreach ($gigIds as $id) {
            $gid = (int) $id;
            if ($gid > 0 && !isset($seen[$gid])) {
                $seen[$gid] = true;
                $ordered[]  = $gid;
            }
        }

        // Define the fiverr_gigs columns we need to extract
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
        ];

        // Initialize result arrays
        $result = [];
        foreach ($fields as $f) {
            $result[$f] = [];
        }
        // Derived field (not stored in DB): adjusted seller level per gig
        // Do not add to $fields since it is not a $fiverrGigsTable column, and $fields is used in the query
        $result['seller__sellerLevel___adjusted'] = [];

        if (empty($ordered)) {
            return $result;
        }

        // Fetch rows once and index by gigId for quick lookup
        $rows = DB::table($this->fiverrGigsTable)
            ->select(array_merge(['general__gigId'], $fields))
            ->whereIn('general__gigId', $ordered)
            ->get();

        $byId = [];
        foreach ($rows as $row) {
            $gid = (int) ($row->{'general__gigId'} ?? 0);
            if ($gid > 0) {
                $byId[$gid] = $row;
            }
        }

        // Populate arrays in the same order as the input gigIds
        foreach ($ordered as $gid) {
            if (!isset($byId[$gid])) {
                continue;
            }
            $row = $byId[$gid];
            foreach ($fields as $f) {
                // Access dynamic property safely
                $val = $row->{$f} ?? null;
                if ($f === 'seller__sellerLevel') {
                    $result[$f][] = $val === null ? null : $this->getSellerLevelNumeric((string) $val);
                } elseif ($f === 'seo__description__deliveryTime') {
                    $result[$f][] = $val === null ? null : $this->parseDeliveryTimeDays((string) $val);
                } else {
                    $result[$f][] = $val;
                }
            }

            // Compute adjusted seller level (count-only) using max(rating_count, completedOrders)
            $lvlRaw = $row->{'seller__sellerLevel'} ?? null;
            $lvlNum = is_string($lvlRaw) ? $this->getSellerLevelNumeric($lvlRaw) : 0;

            $ratingCount = $row->{'seller__rating__count'} ?? null;
            if (is_numeric($ratingCount)) {
                $ratingCount = (int) $ratingCount;
            }

            $completedOrdersCount = $row->{'seller__completedOrdersCount'} ?? null;
            if (is_numeric($completedOrdersCount)) {
                $completedOrdersCount = (int) $completedOrdersCount;
            }

            $count = max(0, $ratingCount, $completedOrdersCount);
            $adj   = $this->getSellerLevelAdjusted($lvlNum, $count, null);

            $result['seller__sellerLevel___adjusted'][] = $adj;
        }

        return $result;
    }

    /**
     * Compute averages of selected gig fields for a given listingAttributes__id within a position range.
     *
     * This is compute-only: it reads fiverr_listings and fiverr_gigs and returns an associative array
     * where keys are the same field names as extractGigFieldArrays() and values are float|null averages.
     *
     * @param string $listingAttributesId listingAttributes__id
     * @param int    $low                 Low position (inclusive)
     * @param int    $high                High position (inclusive)
     * @param bool   $useCache            Use Laravel Cache where applicable
     * @param bool   $forceCacheRebuild   Force Cache rebuild
     *
     * @return array<string,float|null> Map of field => average
     */
    public function computeListingGigFieldAverages(string $listingAttributesId, int $low = 0, int $high = 7, bool $useCache = true, bool $forceCacheRebuild = false): array
    {
        // Gather gigIds for this listing within [low, high] using listing->position map
        $byListing = $this->getListingToGigPositionsMap($useCache, $forceCacheRebuild);
        $posMap    = $byListing[$listingAttributesId] ?? [];

        $gigIds = [];
        foreach ($posMap as $pos => $gid) {
            $p = (int) $pos;
            if ($p >= $low && $p <= $high) {
                $gigIds[] = (int) $gid;
            }
        }

        // Get arrays of values for each field
        $arrays = $this->extractGigFieldArrays($gigIds);

        // Helper to average numeric-like values, ignoring nulls and non-numeric
        $avg = static function (array $values): ?float {
            $sum = 0.0;
            $cnt = 0;
            foreach ($values as $v) {
                if ($v === null) {
                    continue;
                }
                // Accept bool/int/float numeric; numeric strings cast via (float)
                if (is_bool($v)) {
                    $sum += $v ? 1.0 : 0.0;
                    $cnt++;
                } elseif (is_int($v) || is_float($v)) {
                    $sum += (float) $v;
                    $cnt++;
                } elseif (is_string($v)) {
                    $trim = trim($v);
                    if ($trim === '') {
                        continue;
                    }
                    // Cast numeric-looking strings (e.g., '15.00')
                    if (is_numeric($trim)) {
                        $sum += (float) $trim;
                        $cnt++;
                    }
                }
            }

            return $cnt > 0 ? $sum / $cnt : null;
        };

        $out = [];
        foreach ($arrays as $field => $values) {
            // Values for seller__sellerLevel are already numeric via extractGigFieldArrays()
            // Prices arrive as strings from DB; avg() will cast numeric strings
            $out[$field] = $avg($values);
        }

        return $out;
    }

    /**
     * Compute score_1, score_2, score_3 for a stats row using avg___ columns.
     *
     * Inputs used (from fiverr_listings_stats):
     * - avg___overview__gig__ordersInQueue
     * - avg___seo__description__deliveryTime
     * - avg___seo__schemaMarkup__gigOffers__lowPrice
     * - avg___overview__gig__rating
     * - avg___overview__gig__ratingsCount
     * - avg___seller__sellerLevel
     * - avg___seller__sellerLevel___adjusted
     *
     * Rules:
     * - score_1 = (ordersInQueue / deliveryTime) * lowPrice
     *   - Null if any input is null
     *   - Null if deliveryTime <= 0 (avoid divide-by-zero)
     * - score_2 = rating * ln(ratingsCount) * (sellerLevelAdjusted^2)
     *   - Null if any input is null
     *   - Null if ratingsCount <= 0 (invalid logarithm)
     * - score_3 = score_1 / score_2
     *   - Null if score_1 is null or score_2 is null
     *   - Null if score_2 == 0 (avoid divide-by-zero)
     *
     * All numeric-like strings are coerced to float; non-numeric strings are treated as null.
     *
     * @param array<string,mixed> $statsRow Row from fiverr_listings_stats
     *
     * @return array{score_1:float|null,score_2:float|null,score_3:float|null}
     */
    public function computeScoresForStatsRow(array $statsRow): array
    {
        $toFloat = static function ($v): ?float {
            if ($v === null) {
                return null;
            }
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }
            if (is_bool($v)) {
                return $v ? 1.0 : 0.0;
            }
            if (is_string($v)) {
                $t = trim($v);
                if ($t === '' || !is_numeric($t)) {
                    return null;
                }

                return (float) $t;
            }

            return null;
        };

        $ordersInQueue       = $toFloat($statsRow['avg___overview__gig__ordersInQueue'] ?? null);
        $deliveryDays        = $toFloat($statsRow['avg___seo__description__deliveryTime'] ?? null);
        $lowPrice            = $toFloat($statsRow['avg___seo__schemaMarkup__gigOffers__lowPrice'] ?? null);
        $rating              = $toFloat($statsRow['avg___overview__gig__rating'] ?? null);
        $ratingsCount        = $toFloat($statsRow['avg___overview__gig__ratingsCount'] ?? null);
        $sellerLevel         = $toFloat($statsRow['avg___seller__sellerLevel'] ?? null);
        $sellerLevelAdjusted = $toFloat($statsRow['avg___seller__sellerLevel___adjusted'] ?? null);

        // score_1
        $score1 = null;
        if ($ordersInQueue !== null && $deliveryDays !== null && $lowPrice !== null) {
            if ($deliveryDays > 0.0) {
                $score1 = ($ordersInQueue / $deliveryDays) * $lowPrice;
            } else {
                $score1 = null;
            }
        }

        // score_2
        $score2 = null;
        if ($rating !== null && $ratingsCount !== null && $sellerLevelAdjusted !== null) {
            if ($ratingsCount > 0.0) {
                $score2 = $rating * log($ratingsCount) * ($sellerLevelAdjusted * $sellerLevelAdjusted);
            } else {
                $score2 = null;
            }
        }

        // score_3
        $score3 = null;
        if ($score1 !== null && $score2 !== null && $score2 != 0.0) {
            $score3 = $score1 / $score2;
        } else {
            $score3 = null;
        }

        return [
            'score_1' => $score1,
            'score_2' => $score2,
            'score_3' => $score3,
        ];
    }

    /**
     * Process gigs-based stats for listings that already have listing-snapshot stats but no gigs stats yet.
     *
     * - Picks listings where fiverr_listings.stats_processed_at IS NOT NULL and gigs_stats_processed_at IS NULL
     * - Further filters to those listingAttributes__id that exist in fiverr_listings_gigs with processed_at IS NOT NULL
     * - For each listing, computes per-field arrays (json___*) and averages (avg___*) from the fiverr_gigs table
     *   using extractGigFieldArrays() and computeListingGigFieldAverages()
     * - Computes score_1/score_2/score_3 using computeScoresForStatsRow() and writes them to stats row
     * - Marks fiverr_listings.gigs_stats_processed_* for bookkeeping
     *
     * @param int  $batchSize         Batch size
     * @param int  $low               Low position (inclusive) for gigs window
     * @param int  $high              High position (inclusive) for gigs window
     * @param bool $useCache          Use Laravel Cache where applicable
     * @param bool $forceCacheRebuild Force Cache rebuild
     *
     * @return array{rows_processed:int,updated:int,skipped:int}
     */
    public function processGigsStatsBatch(
        int $batchSize = 100,
        int $low = 0,
        int $high = 7,
        bool $useCache = true,
        bool $forceCacheRebuild = false
    ): array {
        ensure_table_exists($this->fiverrListingsTable, $this->fiverrListingsMigrationPath);
        ensure_table_exists($this->fiverrListingsGigsTable, $this->fiverrListingsGigsMigrationPath);
        ensure_table_exists($this->fiverrListingsStatsTable, $this->fiverrListingsStatsMigrationPath);
        // $fiverrGigsTable used indirectly inside helper methods
        ensure_table_exists($this->fiverrGigsTable, $this->fiverrGigsMigrationPath);

        // Select candidate listings (already snapshot-processed, not yet gigs-stats-processed), ordered by id asc
        $rows = DB::table($this->fiverrListingsTable . ' as l')
            ->join($this->fiverrListingsGigsTable . ' as lg', 'l.listingAttributes__id', '=', 'lg.listingAttributes__id')
            ->whereNotNull('l.stats_processed_at')
            ->whereNull('l.gigs_stats_processed_at')
            ->whereNotNull('lg.processed_at')
            ->orderBy('l.id', 'asc')
            ->select(['l.id', 'l.listingAttributes__id'])
            ->distinct()
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            return ['rows_processed' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $processed = 0;
        $updated   = 0;
        $skipped   = 0;

        // Precompute listing->position map once per batch and filter inline by [low, high]
        $byListing = $this->getListingToGigPositionsMap($useCache, $forceCacheRebuild);

        foreach ($rows as $r) {
            $processed++;
            $listingId = (string) $r->listingAttributes__id;

            $posMap = $byListing[$listingId] ?? [];
            $gigIds = [];
            foreach ($posMap as $pos => $gid) {
                $p = (int) $pos;
                if ($p >= $low && $p <= $high) {
                    $gigIds[] = (int) $gid;
                }
            }

            if (count($gigIds) === 0) {
                // No gigs in the window; skip but still mark processed to avoid infinite retries
                DB::table($this->fiverrListingsTable)->where('id', $r->id)->update([
                    'gigs_stats_processed_at'     => now(),
                    'gigs_stats_processed_status' => json_encode([
                        'listingAttributes__id' => $listingId,
                        'updated'               => 0,
                        'reason'                => 'no_gigs_in_window',
                    ], $this->jsonFlags),
                ]);
                $skipped++;

                continue;
            }

            // Build per-field arrays and averages using existing helpers
            $arrays = $this->extractGigFieldArrays($gigIds);
            // Use Redis for averages to avoid loading all listings JSON into memory
            $avgs = $this->computeListingGigFieldAverages($listingId, $low, $high, $useCache, $forceCacheRebuild);

            // Prepare update payload: json___* and avg___*
            $update = [];
            foreach ($arrays as $field => $values) {
                $update['json___' . $field] = json_encode($values, $this->jsonFlags);
            }
            foreach ($avgs as $field => $avg) {
                $update['avg___' . $field] = $avg;
            }

            // Compute scores from the avg___ fields
            $scores = $this->computeScoresForStatsRow($update);
            $update = array_merge($update, $scores);

            // Update the stats row for this listing
            $aff = DB::table($this->fiverrListingsStatsTable)
                ->where('listingAttributes__id', $listingId)
                ->update($update);

            // Mark source listing
            DB::table($this->fiverrListingsTable)->where('id', $r->id)->update([
                'gigs_stats_processed_at'     => now(),
                'gigs_stats_processed_status' => json_encode([
                    'listingAttributes__id' => $listingId,
                    'updated'               => (int) $aff,
                ], $this->jsonFlags),
            ]);

            $updated += (int) $aff;
        }

        return [
            'rows_processed' => $processed,
            'updated'        => $updated,
            'skipped'        => $skipped,
        ];
    }

    /**
     * Process gigs-based stats for ALL candidate listings by repeatedly calling processGigsStatsBatch().
     *
     * Returns the aggregate counts across all batches.
     *
     * @param int  $batchSize         Batch size
     * @param int  $low               Low position (inclusive) for gigs window
     * @param int  $high              High position (inclusive) for gigs window
     * @param bool $useCache          Use Laravel Cache where applicable
     * @param bool $forceCacheRebuild Force Cache rebuild
     *
     * @return array{rows_processed:int,updated:int,skipped:int}
     */
    public function processGigsStatsAll(
        int $batchSize = 100,
        int $low = 0,
        int $high = 7,
        bool $useCache = true,
        bool $forceCacheRebuild = false
    ): array {
        $total = [
            'rows_processed' => 0,
            'updated'        => 0,
            'skipped'        => 0,
        ];

        Log::debug('Starting processGigsStatsAll...', [
            'batchSize'         => $batchSize,
            'low'               => $low,
            'high'              => $high,
            'useCache'          => $useCache,
            'forceCacheRebuild' => $forceCacheRebuild,
        ]);

        do {
            $res = $this->processGigsStatsBatch($batchSize, $low, $high, $useCache, $forceCacheRebuild);
            $total['rows_processed'] += (int) ($res['rows_processed']);
            $total['updated'] += (int) ($res['updated']);
            $total['skipped'] += (int) ($res['skipped']);
        } while (($res['rows_processed']) > 0);

        Log::debug('Completed processGigsStatsAll', $total);

        return $total;
    }
}
