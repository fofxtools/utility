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

    protected string $fiverrListingsMigrationPath       = __DIR__ . '/../database/migrations/2025_09_05_175701_create_fiverr_listings_table.php';
    protected string $fiverrGigsMigrationPath           = __DIR__ . '/../database/migrations/2025_09_06_005341_create_fiverr_gigs_table.php';
    protected string $fiverrSellerProfilesMigrationPath = __DIR__ . '/../database/migrations/2025_09_06_021235_create_fiverr_seller_profiles_table.php';
    protected string $fiverrListingsGigsMigrationPath   = __DIR__ . '/../database/migrations/2025_09_06_030326_create_fiverr_listings_gigs_table.php';

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

    /**
     * Process all unprocessed listings in batches until none remain.
     *
     * @param int $batchSize
     *
     * @return array{rows_processed:int,gigs_seen:int,inserted:int}
     */
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
}
