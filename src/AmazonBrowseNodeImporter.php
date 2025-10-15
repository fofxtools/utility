<?php

namespace FOfX\Utility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AmazonBrowseNodeImporter
{
    // The database table being imported to
    protected string $tableName          = 'amazon_browse_nodes';
    protected string $tableMigrationPath = __DIR__ . '/../database/migrations/2025_10_14_184118_create_amazon_browse_nodes_table.php';
    // Folder expected to be located inside Storage::disk('local')
    protected string $folderName = 'amazon';
    // The file being imported from (from https://letsnarrowdown.com/)
    protected string $fileName = 'amazon_product_categories.csv';

    // Hardcoded mappings for browse nodes that have string identifiers rather than numeric IDs
    protected array $hardcodedMappings = [
        'digital-music-album' => 324381011,
        'digital-music-track' => 324382011,
    ];

    /**
     * Constructor
     *
     * No-arg constructor. Properties have defaults and can be customized via public setters.
     */
    public function __construct()
    {
        // Intentionally empty; defaults are set via properties and can be customized via setters.
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Set the table name.
     *
     * @param string $tableName
     *
     * @return void
     */
    public function setTableName(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    /**
     * Get the table migration path.
     *
     * @return string
     */
    public function getTableMigrationPath(): string
    {
        return $this->tableMigrationPath;
    }

    /**
     * Set the table migration path.
     *
     * @param string $path
     *
     * @return void
     */
    public function setTableMigrationPath(string $path): void
    {
        $this->tableMigrationPath = $path;
    }

    /**
     * Get the folder name.
     *
     * @return string
     */
    public function getFolderName(): string
    {
        return $this->folderName;
    }

    /**
     * Set the folder name.
     *
     * @param string $folderName
     *
     * @return void
     */
    public function setFolderName(string $folderName): void
    {
        $this->folderName = $folderName;
    }

    /**
     * Get the file name.
     *
     * @return string
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * Set the file name.
     *
     * @param string $fileName
     *
     * @return void
     */
    public function setFileName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    /**
     * Get the hardcoded mappings.
     *
     * @return array
     */
    public function getHardcodedMappings(): array
    {
        return $this->hardcodedMappings;
    }

    /**
     * Set the hardcoded mappings.
     *
     * @param array $hardcodedMappings
     *
     * @return void
     */
    public function setHardcodedMappings(array $hardcodedMappings): void
    {
        $this->hardcodedMappings = $hardcodedMappings;
    }

    /**
     * Collect nodes from CSV file (no database operations)
     *
     * CSV format: Department (ID),Node1 (ID),Node2 (ID),...
     *
     * @return array{nodes:array,stats:array}
     */
    public function collectNodesFromCsv(): array
    {
        $path = Storage::disk('local')->path($this->folderName . '/' . $this->fileName);

        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        Log::debug('Collecting nodes from CSV', ['path' => $path]);

        $handle = fopen($path, 'r');
        fgetcsv($handle); // Skip header

        $nodesToInsert = [];
        $existingNodes = [];
        $stats         = [
            'rows_processed'           => 0,
            'rows_skipped_empty'       => 0,
            'rows_skipped_non_numeric' => 0,
            'total_node_instances'     => 0,
            'nodes_to_insert'          => 0,
            'nodes_skipped_duplicate'  => 0,
        ];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                $stats['rows_skipped_empty']++;

                continue;
            }

            // Parse all columns (department + child nodes)
            $allNodes = [];
            for ($i = 0; $i < count($row); $i++) {
                $nodeStr = trim($row[$i]);
                if (!empty($nodeStr)) {
                    $allNodes[] = $nodeStr;
                }
            }

            if (empty($allNodes)) {
                $stats['rows_skipped_empty']++;

                continue;
            }

            $stats['rows_processed']++;

            // Track parent ID for building hierarchy
            $currentParentId = null;

            // Process each node in the path
            foreach ($allNodes as $nodeIndex => $nodeStr) {
                $stats['total_node_instances']++;

                // Extract browse node ID
                $browseNodeId = null;

                // First try to extract numeric ID
                if (preg_match('/\((\d+)\)/', $nodeStr, $matches)) {
                    $browseNodeId = (int)$matches[1];
                } else {
                    // Check for hardcoded non-numeric identifiers
                    if (preg_match('/\(([a-z0-9\-]+)\)/', $nodeStr, $matches)) {
                        $identifier = $matches[1];
                        if (isset($this->hardcodedMappings[$identifier])) {
                            $browseNodeId = $this->hardcodedMappings[$identifier];
                        }
                    }
                }

                // If no valid ID found, skip this node and rest of path
                if ($browseNodeId === null) {
                    $stats['rows_skipped_non_numeric']++;

                    break;
                }

                // Extract node name (everything before the parentheses)
                $nodeName = trim(preg_replace('/\s*\([^\)]+\)$/', '', $nodeStr));

                // Level: 0 for department, 1+ for children
                $level = $nodeIndex;

                // Build the full path up to this node
                $pathParts = array_slice($allNodes, 0, $nodeIndex + 1);
                $fullPath  = implode(',', $pathParts);

                // Check if we've already seen this node
                if (!isset($existingNodes[$browseNodeId])) {
                    // Add to collection
                    $nodesToInsert[] = [
                        'browse_node_id'   => $browseNodeId,
                        'name'             => $nodeName,
                        'parent_id'        => $currentParentId,
                        'path'             => $fullPath,
                        'level'            => $level,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                        'processed_at'     => null,
                        'processed_status' => null,
                    ];

                    $existingNodes[$browseNodeId] = true;
                    $stats['nodes_to_insert']++;

                    if ($stats['rows_processed'] % 1000 === 0) {
                        Log::debug('Processed {rows_processed} rows, collected {nodes_to_insert} unique nodes', [
                            'rows_processed'  => $stats['rows_processed'],
                            'nodes_to_insert' => $stats['nodes_to_insert'],
                        ]);
                    }
                } else {
                    $stats['nodes_skipped_duplicate']++;
                }

                // This node becomes the parent for the next node in the path
                $currentParentId = $browseNodeId;
            }
        }

        fclose($handle);

        return [
            'nodes' => $nodesToInsert,
            'stats' => $stats,
        ];
    }

    /**
     * Insert nodes into database using batch inserts
     *
     * @param array $nodesToInsert Array of nodes to insert
     * @param int   $batchSize     Batch size for inserts (default 500)
     *
     * @return array{inserted:int,skipped:int,errors:int}
     */
    public function insertNodesByLevel(array $nodesToInsert, int $batchSize = 500): array
    {
        Log::debug('Inserting nodes by level', ['total_nodes' => count($nodesToInsert), 'batch_size' => $batchSize]);

        // Group nodes by level
        $byLevel = [];
        foreach ($nodesToInsert as $node) {
            $level             = $node['level'];
            $byLevel[$level][] = $node;
        }

        Log::debug('Grouped nodes by level', ['levels' => count($byLevel)]);

        $inserted = 0;
        $skipped  = 0;

        // Insert level by level (ensures parents exist before children)
        ksort($byLevel); // Sort by level to ensure order

        foreach ($byLevel as $level => $nodesAtLevel) {
            Log::debug('Inserting level {level}', ['level' => $level, 'nodes' => count($nodesAtLevel)]);

            // Split into batches
            $batches = array_chunk($nodesAtLevel, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $batchInserted = DB::table($this->tableName)->insertOrIgnore($batch);
                $inserted += $batchInserted;
                $skipped += count($batch) - $batchInserted;

                if (($batchIndex + 1) % 10 === 0) {
                    Log::debug('Inserted {count} nodes at level {level}', [
                        'count' => ($batchIndex + 1) * $batchSize,
                        'level' => $level,
                    ]);
                }
            }

            Log::debug('Level {level} complete', ['level' => $level, 'inserted' => count($nodesAtLevel)]);
        }

        Log::debug('Insert complete', ['total_inserted' => $inserted, 'total_skipped' => $skipped]);

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'errors'   => 0,
        ];
    }

    /**
     * Import browse nodes from CSV file (orchestrates two-pass approach)
     *
     * @param int $batchSize Batch size for inserts (default 500)
     *
     * @return array{inserted:int,skipped:int,errors:int}
     */
    public function importBrowseNodesFromCsv(int $batchSize = 500): array
    {
        ensure_table_exists($this->tableName, $this->tableMigrationPath);

        Log::debug('Starting browse nodes import from CSV', ['batch_size' => $batchSize]);

        // PASS 1: Collect all nodes from CSV (no database operations)
        $pass1Result   = $this->collectNodesFromCsv();
        $nodesToInsert = $pass1Result['nodes'];
        $stats         = $pass1Result['stats'];

        // PASS 2: Insert nodes by level using batch inserts
        $pass2Result = $this->insertNodesByLevel($nodesToInsert, $batchSize);

        Log::debug('Browse nodes import complete', [
            'inserted' => $pass2Result['inserted'],
            'skipped'  => $stats['nodes_skipped_duplicate'],
            'errors'   => $stats['rows_skipped_non_numeric'],
        ]);

        return [
            'inserted' => $pass2Result['inserted'],
            'skipped'  => $stats['nodes_skipped_duplicate'],
            'errors'   => $stats['rows_skipped_non_numeric'],
        ];
    }
}
