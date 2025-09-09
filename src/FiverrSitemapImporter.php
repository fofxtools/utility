<?php

namespace FOfX\Utility;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiverrSitemapImporter
{
    protected int $batchSize                  = 100;
    protected string $categoriesSitemapPath   = __DIR__ . '/../resources/sitemap_categories.xml';
    protected string $categoriesTableName     = 'fiverr_sitemap_categories';
    protected string $categoriesMigrationPath = __DIR__ . '/../database/migrations/2025_09_02_181130_create_fiverr_sitemap_categories_table.php';
    protected string $tagsSitemapPath         = __DIR__ . '/../resources/sitemap_tags.xml';
    protected string $tagsTableName           = 'fiverr_sitemap_tags';
    protected string $tagsMigrationPath       = __DIR__ . '/../database/migrations/2025_09_03_211440_create_fiverr_sitemap_tags_table.php';

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
     * Get the current batch size.
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Set the batch size (min 1).
     *
     * @param int $batchSize
     *
     * @return void
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = max(1, $batchSize);
    }

    /**
     * Get the categories sitemap path.
     *
     * @return string
     */
    public function getCategoriesSitemapPath(): string
    {
        return $this->categoriesSitemapPath;
    }

    /**
     * Set the categories sitemap path.
     *
     * @param string $path
     *
     * @return void
     */
    public function setCategoriesSitemapPath(string $path): void
    {
        $this->categoriesSitemapPath = $path;
    }

    /**
     * Get the categories table name.
     *
     * @return string
     */
    public function getCategoriesTableName(): string
    {
        return $this->categoriesTableName;
    }

    /**
     * Set the categories table name.
     *
     * @param string $tableName
     *
     * @return void
     */
    public function setCategoriesTableName(string $tableName): void
    {
        $this->categoriesTableName = $tableName;
    }

    /**
     * Get the categories migration path.
     *
     * @return string
     */
    public function getCategoriesMigrationPath(): string
    {
        return $this->categoriesMigrationPath;
    }

    /**
     * Set the categories migration path.
     *
     * @param string $path
     *
     * @return void
     */
    public function setCategoriesMigrationPath(string $path): void
    {
        $this->categoriesMigrationPath = $path;
    }

    /**
     * Get the tags sitemap path.
     *
     * @return string
     */
    public function getTagsSitemapPath(): string
    {
        return $this->tagsSitemapPath;
    }

    /**
     * Set the tags sitemap path.
     *
     * @param string $path
     *
     * @return void
     */
    public function setTagsSitemapPath(string $path): void
    {
        $this->tagsSitemapPath = $path;
    }

    /**
     * Get the tags table name.
     *
     * @return string
     */
    public function getTagsTableName(): string
    {
        return $this->tagsTableName;
    }

    /**
     * Set the tags table name.
     *
     * @param string $tableName
     *
     * @return void
     */
    public function setTagsTableName(string $tableName): void
    {
        $this->tagsTableName = $tableName;
    }

    /**
     * Get the tags migration path.
     *
     * @return string
     */
    public function getTagsMigrationPath(): string
    {
        return $this->tagsMigrationPath;
    }

    /**
     * Set the tags migration path.
     *
     * @param string $path
     *
     * @return void
     */
    public function setTagsMigrationPath(string $path): void
    {
        $this->tagsMigrationPath = $path;
    }

    /**
     * Load the XML file and return a DOMXPath with required namespaces registered.
     *
     * @param string $sitemapPath Absolute path to the sitemap XML
     *
     * @throws \RuntimeException If the XML cannot be parsed
     *
     * @return DOMXPath
     */
    public function loadDom(string $sitemapPath): DOMXPath
    {
        $dom                     = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        libxml_use_internal_errors(true);
        $loaded = $dom->load($sitemapPath);
        if (!$loaded) {
            $errs = array_map(fn ($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            throw new \RuntimeException("Failed to parse XML file: {$sitemapPath}; Errors: " . implode(' | ', $errs));
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

        return $xpath;
    }

    /**
     * Derive a slug from the URL path (last segment). Returns null for homepage.
     *
     * @param string $loc
     *
     * @return string|null
     */
    public function deriveSlug(string $loc): ?string
    {
        $path = trim((string) (parse_url($loc, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return null;
        }
        $parts = array_values(array_filter(explode('/', $path), fn ($p) => $p !== ''));

        return end($parts) ?: null;
    }

    /**
     * Parse category-related IDs from the alternate href query string (categories only).
     *
     * @param string|null $alternateHref
     *
     * @return array{0:int|null,1:int|null,2:int|null} [category_id, sub_category_id, nested_sub_category_id]
     */
    public function parseCategoryIdsFromAlternateHref(?string $alternateHref): array
    {
        $categoryId          = null;
        $subCategoryId       = null;
        $nestedSubCategoryId = null;
        if ($alternateHref) {
            // DOM returns decoded attribute values already; safe to parse
            $q = parse_url($alternateHref, PHP_URL_QUERY) ?: '';
            parse_str($q, $params);
            $categoryId          = isset($params['category_id']) ? (int) $params['category_id'] : null;
            $subCategoryId       = isset($params['sub_category_id']) ? (int) $params['sub_category_id'] : null;
            $nestedSubCategoryId = isset($params['nested_sub_category_id']) ? (int) $params['nested_sub_category_id'] : null;
        }

        return [$categoryId, $subCategoryId, $nestedSubCategoryId];
    }

    /**
     * Insert a batch into a specific table using insertOrIgnore.
     *
     * @param string                         $tableName Table name
     * @param array<int,array<string,mixed>> $batch     Array of row data
     *
     * @return array{inserted:int,skipped:int}
     */
    public function insertBatchToTable(string $tableName, array $batch): array
    {
        if (empty($batch)) {
            return ['inserted' => 0, 'skipped' => 0];
        }
        $count    = count($batch);
        $inserted = DB::table($tableName)->insertOrIgnore($batch);
        $skipped  = $count - $inserted;

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Import the categories sitemap into the categories table in batches.
     *
     * @throws \RuntimeException If required files are missing or the XML cannot be parsed
     *
     * @return array{processed:int,with_alternate:int,inserted:int,skipped:int,batches:int,duration_sec:float}
     */
    public function importCategories(): array
    {
        $start = microtime(true);
        ensure_table_exists($this->categoriesTableName, $this->categoriesMigrationPath);

        $sitemapPath = $this->categoriesSitemapPath;
        if (!file_exists($sitemapPath)) {
            throw new \RuntimeException("Sitemap XML not found: {$sitemapPath}");
        }

        Log::debug('Starting sitemap import', [
            'table'     => $this->categoriesTableName,
            'sitemap'   => $sitemapPath,
            'batchSize' => $this->batchSize,
        ]);

        $xpath    = $this->loadDom($sitemapPath);
        $urlNodes = $xpath->query('//sm:url');
        if (!$urlNodes) {
            throw new \RuntimeException('Failed to query entries from sitemap XML.');
        }

        $total         = 0;
        $withAlternate = 0;
        $inserted      = 0;
        $skipped       = 0;
        $batchNum      = 0;
        $batch         = [];
        $batchTs       = null; // Per-batch timestamp for created_at/updated_at

        foreach ($urlNodes as $urlNode) {
            $locList = $xpath->query('sm:loc', $urlNode);
            $locNode = ($locList instanceof \DOMNodeList) ? $locList->item(0) : null;
            if (!$locNode) {
                continue;
            }
            $loc = trim($locNode->textContent);

            $priority     = null;
            $priorityList = $xpath->query('sm:priority', $urlNode);
            $priorityNode = ($priorityList instanceof \DOMNodeList) ? $priorityList->item(0) : null;
            if ($priorityNode) {
                $priorityVal = trim($priorityNode->textContent);
                $priority    = is_numeric($priorityVal) ? (float) $priorityVal : null;
            }

            $alternateHref = null;
            $linkList      = $xpath->query('xhtml:link[@rel="alternate"]', $urlNode);
            $linkNode      = ($linkList instanceof \DOMNodeList) ? $linkList->item(0) : null;
            if ($linkNode && $linkNode->attributes && $linkNode->attributes->getNamedItem('href')) {
                $alternateHref = $linkNode->attributes->getNamedItem('href')->nodeValue;
            }

            if (!empty($alternateHref)) {
                $withAlternate++;
            }

            [$categoryId, $subCategoryId, $nestedSubCategoryId] = $this->parseCategoryIdsFromAlternateHref($alternateHref);
            $slug                                               = $this->deriveSlug($loc);

            // Set per-batch timestamps on first row of batch
            $batchTs = $batchTs ?? now();

            $batch[] = [
                'url'                    => $loc,
                'slug'                   => $slug,
                'priority'               => $priority,
                'alternate_href'         => $alternateHref,
                'category_id'            => $categoryId,
                'sub_category_id'        => $subCategoryId,
                'nested_sub_category_id' => $nestedSubCategoryId,
                'created_at'             => $batchTs,
                'updated_at'             => $batchTs,
                'processed_at'           => null,
                'processed_status'       => null,
            ];

            $total++;

            if (count($batch) >= $this->batchSize) {
                $batchNum++;
                $res = $this->insertBatchToTable($this->categoriesTableName, $batch);
                $inserted += $res['inserted'];
                $skipped += $res['skipped'];
                Log::debug('Batch inserted', [
                    'batch'        => $batchNum,
                    'count'        => count($batch),
                    'inserted'     => $res['inserted'],
                    'skipped'      => $res['skipped'],
                    'inserted_cum' => $inserted,
                    'skipped_cum'  => $skipped,
                ]);
                $batch   = [];
                $batchTs = null; // Reset so next batch gets a fresh timestamp
            }
        }

        if (!empty($batch)) {
            $batchNum++;
            $res = $this->insertBatchToTable($this->categoriesTableName, $batch);
            $inserted += $res['inserted'];
            $skipped += $res['skipped'];
            Log::debug('Final batch inserted', [
                'batch'        => $batchNum,
                'count'        => count($batch),
                'inserted'     => $res['inserted'],
                'skipped'      => $res['skipped'],
                'inserted_cum' => $inserted,
                'skipped_cum'  => $skipped,
            ]);
        }

        $duration = microtime(true) - $start;

        $stats = [
            'processed'      => $total,
            'with_alternate' => $withAlternate,
            'inserted'       => $inserted,
            'skipped'        => $skipped,
            'batches'        => $batchNum,
            'duration_sec'   => $duration,
        ];

        Log::debug('Sitemap import completed', $stats);

        return $stats;
    }

    /**
     * Import the tags sitemap into the tags table in batches.
     *
     * @return array{processed:int,inserted:int,skipped:int,batches:int,duration_sec:float}
     */
    public function importTags(): array
    {
        $start = microtime(true);
        ensure_table_exists($this->tagsTableName, $this->tagsMigrationPath);

        $sitemapPath = $this->tagsSitemapPath;
        if (!file_exists($sitemapPath)) {
            throw new \RuntimeException("Sitemap XML not found: {$sitemapPath}");
        }

        Log::debug('Starting sitemap import', [
            'table'     => $this->tagsTableName,
            'sitemap'   => $sitemapPath,
            'batchSize' => $this->batchSize,
        ]);

        $xpath    = $this->loadDom($sitemapPath);
        $urlNodes = $xpath->query('//sm:url');
        if (!$urlNodes) {
            throw new \RuntimeException('No <url> entries found in sitemap.');
        }

        $total    = 0;
        $inserted = 0;
        $skipped  = 0;
        $batches  = 0;
        $batch    = [];
        $batchTs  = null;

        foreach ($urlNodes as $urlNode) {
            $locList = $xpath->query('sm:loc', $urlNode);
            $locNode = ($locList instanceof \DOMNodeList) ? $locList->item(0) : null;
            if (!$locNode) {
                continue;
            }
            $loc = trim($locNode->textContent);
            if ($loc === '') {
                continue;
            }

            $slug = $this->deriveSlug($loc);

            $batchTs = $batchTs ?? now();
            $batch[] = [
                'url'              => $loc,
                'slug'             => $slug,
                'created_at'       => $batchTs,
                'updated_at'       => $batchTs,
                'processed_at'     => null,
                'processed_status' => null,
            ];

            $total++;

            if (count($batch) >= $this->batchSize) {
                $batches++;
                $res = $this->insertBatchToTable($this->tagsTableName, $batch);
                $inserted += $res['inserted'];
                $skipped += $res['skipped'];
                Log::debug('Batch inserted', [
                    'batch'        => $batches,
                    'count'        => count($batch),
                    'inserted'     => $res['inserted'],
                    'skipped'      => $res['skipped'],
                    'inserted_cum' => $inserted,
                    'skipped_cum'  => $skipped,
                ]);
                $batch   = [];
                $batchTs = null;
            }
        }

        if (!empty($batch)) {
            $batches++;
            $res = $this->insertBatchToTable($this->tagsTableName, $batch);
            $inserted += $res['inserted'];
            $skipped += $res['skipped'];
            Log::debug('Final batch inserted', [
                'batch'        => $batches,
                'count'        => count($batch),
                'inserted'     => $res['inserted'],
                'skipped'      => $res['skipped'],
                'inserted_cum' => $inserted,
                'skipped_cum'  => $skipped,
            ]);
        }

        $duration = microtime(true) - $start;

        $stats = [
            'processed'    => $total,
            'inserted'     => $inserted,
            'skipped'      => $skipped,
            'batches'      => $batches,
            'duration_sec' => $duration,
        ];

        Log::debug('Sitemap import completed', $stats);

        return $stats;
    }
}
