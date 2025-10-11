<?php

declare(strict_types=1);

namespace FOfX\Utility;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pdp\Rules;
use Pdp\Domain;
use FOfX\Helper;
use Symfony\Component\DomCrawler\Crawler;

/**
 * List all tables in the database in driver agnostic way
 *
 * @throws \Exception If the database driver is not supported
 *
 * @return array List of table names
 */
function get_tables(): array
{
    $connection = DB::connection();
    $driver     = $connection->getDriverName();

    Log::debug('Getting tables from database', ['driver' => $driver]);

    switch ($driver) {
        case 'sqlite':
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            return array_map(fn ($table) => $table->name, $tables);
        case 'mysql':
            $tables = DB::select('SHOW TABLES');

            return array_map(fn ($table) => array_values((array) $table)[0], $tables);
        case 'pgsql':
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public'");

            return array_map(fn ($table) => $table->tablename, $tables);
        case 'sqlsrv':
            $tables = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");

            return array_map(fn ($table) => $table->TABLE_NAME, $tables);
        default:
            throw new \Exception("Unsupported database driver: $driver");
    }
}

/**
 * Downloads the public suffix list for jeremykendall/php-domain-parser if it doesn't exist.
 *
 * @throws \RuntimeException If the file cannot be downloaded or saved
 *
 * @return string The path to the public suffix list file
 */
function download_public_suffix_list(): string
{
    $filename = 'public_suffix_list.dat';

    if (Storage::disk('local')->exists($filename)) {
        return Storage::disk('local')->path($filename);
    }

    $response = Http::get('https://publicsuffix.org/list/public_suffix_list.dat');

    if (!$response->successful()) {
        throw new \RuntimeException('Failed to download public suffix list');
    }

    Storage::disk('local')->put($filename, $response->body());

    return Storage::disk('local')->path($filename);
}

/**
 * Extract the registrable domain from a URL
 *
 * @param string $url      The URL to extract domain from
 * @param bool   $stripWww Whether to strip www prefix (default: true)
 *
 * @return string The registrable domain
 */
function extract_registrable_domain(string $url, bool $stripWww = true): string
{
    // Use php-domain-parser to get the registrable domain
    $pslPath          = download_public_suffix_list();
    $publicSuffixList = Rules::fromPath($pslPath);

    // Extract hostname from URL if it contains a protocol
    $hostname  = parse_url($url, PHP_URL_HOST) ?? $url;
    $domainObj = Domain::fromIDNA2008($hostname);

    // Use registrableDomain() to get the registrable domain
    $result            = $publicSuffixList->resolve($domainObj);
    $registrableDomain = $result->registrableDomain()->toString();

    // Strip the www if requested
    if ($stripWww) {
        $registrableDomain = Helper\strip_www($registrableDomain);
    }

    return $registrableDomain;
}

/**
 * Validate if a domain has a valid registrable domain and suffix
 *
 * @param string $domain The domain to validate
 *
 * @return bool True if valid, false otherwise
 */
function is_valid_domain(string $domain): bool
{
    // Reject IPs
    if (filter_var($domain, FILTER_VALIDATE_IP)) {
        return false;
    }

    try {
        $pslPath          = download_public_suffix_list();
        $publicSuffixList = Rules::fromPath($pslPath);
        $domainObj        = Domain::fromIDNA2008($domain);
        $result           = $publicSuffixList->resolve($domainObj);

        // Must be a known suffix (ICANN or private) and have eTLD+1
        return $result->suffix()->isKnown() && $result->registrableDomain()->value() !== null;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Extract the canonical URL from an HTML string
 *
 * @param string $html The HTML content to extract canonical URL from
 *
 * @return string|null The canonical URL if found, null otherwise
 */
function extract_canonical_url(string $html): ?string
{
    try {
        $crawler = new Crawler($html);
    } catch (\InvalidArgumentException $e) {
        return null;
    }

    // Use rel~="canonical" to match both strict rel="canonical" and other cases with extra terms
    // e.g., rel="canonical nofollow"
    $canonicalNodes = $crawler->filter('link[rel~="canonical"]');

    if ($canonicalNodes->count() === 0) {
        return null;
    }

    $href = $canonicalNodes->first()->attr('href');

    return $href !== null && trim($href) !== '' ? trim($href) : null;
}

/**
 * List suggested CSS selectors for embedded JSON script tags found in the HTML.
 * Emits precise selectors of the form 'script#<id>' for scripts that have an id.
 *
 * @param string $html          The HTML to scan
 * @param bool   $includeLdJson Whether to include application/ld+json scripts
 * @param bool   $unique        Whether to de-duplicate the returned selectors
 *
 * @return string[] Array of selector strings (e.g., ['script#perseus-initial-props'])
 */
function list_embedded_json_selectors(string $html, bool $includeLdJson = true, bool $unique = true): array
{
    $crawler  = new Crawler($html);
    $selector = 'script[type="application/json"]';

    if ($includeLdJson) {
        $selector .= ',script[type="application/ld+json"]';
    }

    $nodes = $crawler->filter($selector);
    $out   = [];

    $nodes->each(function (Crawler $n) use (&$out) {
        $id = trim((string)($n->attr('id') ?? ''));
        // Skip scripts without an ID
        if ($id !== '') {
            $out[] = 'script#' . $id;
        }
    });

    if ($unique) {
        $out = array_values(array_unique($out));
    }

    return $out;
}

/**
 * Extract embedded JSON blocks from HTML.
 * Scans for <script type="application/json"> (and optionally ld+json) and returns an
 * array of blocks with additional metadata: id, type, bytes, attrs. And also the decoded json.
 *
 * @param string   $html          HTML content to scan
 * @param bool     $includeLdJson Include application/ld+json scripts (default true)
 * @param bool     $assoc         Decode JSON as associative arrays (default true)
 * @param int|null $limit         Max number of blocks to return (null = no limit)
 *
 * @return array[] Each item: [id,type,bytes,attrs=>[...],json=>mixed]
 */
function extract_embedded_json_blocks(string $html, bool $includeLdJson = true, bool $assoc = true, ?int $limit = null): array
{
    $crawler  = new Crawler($html);
    $selector = 'script[type="application/json"]';

    if ($includeLdJson) {
        $selector .= ',script[type="application/ld+json"]';
    }

    $nodes = $crawler->filter($selector);
    $out   = [];
    $count = 0;

    $nodes->each(function (Crawler $n) use (&$out, &$count, $limit, $assoc) {
        if ($limit !== null && $count >= $limit) {
            return;
        }

        $text  = trim($n->text(''));
        $out[] = [
            'id'    => $n->attr('id') ?? '',
            'type'  => $n->attr('type') ?? '',
            'bytes' => strlen($text),
            'attrs' => [
                'id'    => $n->attr('id') ?? null,
                'class' => $n->attr('class') ?? null,
            ],
            'json' => json_decode($text, $assoc, 512, JSON_BIGINT_AS_STRING),
        ];

        $count++;
    });

    return $out;
}

/**
 * Filter JSON blocks by selector ID with optional JSON content extraction
 *
 * @param array       $blocks       Array of JSON blocks to filter
 * @param string|null $selectorId   Optional selector ID to filter blocks (e.g., 'perseus-initial-props')
 * @param bool        $pluckJsonKey Whether to extract only the JSON content from blocks (default: false)
 *
 * @return array Filtered blocks or JSON content array
 */
function filter_json_blocks_by_selector(array $blocks, ?string $selectorId = null, bool $pluckJsonKey = false): array
{
    // Filter by selector ID if provided
    if ($selectorId !== null) {
        $blocks = array_filter($blocks, fn ($block) => $block['id'] === $selectorId);
    }

    // Extract only the JSON content if requested
    if ($pluckJsonKey) {
        // Use empty array as fallback if no 'json' key
        $blocks = array_map(fn ($block) => $block['json'] ?? [], $blocks);
    }

    return array_values($blocks);
}

/**
 * Save JSON blocks extracted from HTML to a file with optional filtering by selector ID
 *
 * @param string      $html        The HTML content to extract blocks from
 * @param string      $filename    The filename to save to (relative to storage/app)
 * @param string|null $selectorId  Optional selector ID to filter blocks (e.g., 'perseus-initial-props')
 * @param bool        $prettyPrint Whether to pretty-print JSON (default: true)
 *
 * @throws \RuntimeException If the file cannot be written
 *
 * @return string The relative path to the saved file
 */
function save_json_blocks_to_file(string $html, string $filename, ?string $selectorId = null, bool $prettyPrint = true): string
{
    $blocks = extract_embedded_json_blocks($html);

    // Filter and extract JSON content
    $jsonData = filter_json_blocks_by_selector($blocks, $selectorId, true);

    // Convert to array values to reset keys
    $jsonData = array_values($jsonData);

    // Encode JSON with optional pretty printing
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;
    if ($prettyPrint) {
        $jsonFlags |= JSON_PRETTY_PRINT;
    }

    // Unwrap single item results for better UX
    if (count($jsonData) === 1) {
        $json = json_encode($jsonData[0], $jsonFlags);
    } else {
        $json = json_encode($jsonData, $jsonFlags);
    }

    if ($json === false) {
        throw new \RuntimeException('Failed to encode blocks as JSON');
    }

    // Save to storage
    if (!Storage::disk('local')->put($filename, $json)) {
        throw new \RuntimeException("Failed to write file: $filename");
    }

    return $filename;
}

/**
 * Infer a reasonable Laravel migration column type for a given value.
 *
 * Heuristics:
 * - null => 'string'
 * - non-scalar (arrays/objects) => 'text'
 * - string length > 255 => 'text'
 * - float => 'float'
 * - otherwise => PHP gettype($value)
 *
 * @param mixed $value The value to infer the type from
 *
 * @return string The inferred type keyword (e.g., string, text, float, integer, boolean)
 */
function infer_laravel_type(mixed $value): string
{
    return match (true) {
        is_null($value)                           => 'string',
        !is_scalar($value)                        => 'text',
        is_string($value) && strlen($value) > 255 => 'text',
        is_float($value)                          => 'float',
        // Use bigInteger for integers outside 32-bit signed range
        is_int($value) && ($value < -2147483648 || $value > 2147483647) => 'bigInteger',
        default                                                         => gettype($value),
    };
}

/**
 * Inspect a nested JSON-like array and produce a flat map of "path => type".
 *
 * - Traverses associative arrays (objects) and recurses into them
 * - Stops at list arrays; records type as 'array' or inferred type if $infer is true
 * - Scalars use PHP gettype() or infer_laravel_type() when $infer is true
 *
 * @param array                $data      Decoded JSON as associative array
 * @param string               $delimiter Path delimiter used to join keys (default ".")
 * @param bool                 $infer     If true, use infer_laravel_type() heuristics rather than gettype()
 * @param string               $prefix    Path prefix to start from (used internally)
 * @param array<string,string> $out       Accumulator map of path => type (by reference, used internally)
 *
 * @return array<string,string> Final path => type map
 */
function inspect_json_types(array $data, string $delimiter = '.', bool $infer = false, string $prefix = '', array &$out = []): array
{
    foreach ($data as $key => $value) {
        if ($prefix === '') {
            $path = (string) $key;
        } else {
            $path = $prefix . $delimiter . (string) $key;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                // Stop at arrays
                if ($infer) {
                    $out[$path] = infer_laravel_type($value);
                } else {
                    $out[$path] = 'array';
                }
            } else {
                // Recurse into objects
                inspect_json_types($value, $delimiter, $infer, $path, $out);
            }
        } else {
            if ($infer) {
                $out[$path] = infer_laravel_type($value);
            } else {
                $out[$path] = gettype($value);
            }
        }
    }

    return $out;
}

/**
 * Convert a map of path => type into plain Laravel migration column lines.
 *
 * Example input: ['user.name' => 'string', 'user.profile' => 'text']
 * Output lines:
 *   string('user.name')
 *   text('user.profile')
 *
 * @param array<string,string> $types Map of path => Laravel type
 *
 * @return string Multiline string of column definitions, one per line
 */
function types_to_columns(array $types): string
{
    $output = '';
    foreach ($types as $key => $type) {
        $output .= "{$type}('{$key}')" . PHP_EOL;
    }

    return $output;
}

/**
 * Get a nested value from a decoded JSON array using a path and delimiter.
 *
 * Path is split by the delimiter (default ".") and traversed step by step.
 * Returns null if any segment is missing.
 *
 * @param array<string,mixed> $data      Decoded JSON as associative array
 * @param string              $path      Delimited key path (e.g., "user.address.city")
 * @param string              $delimiter Delimiter separating path segments (default ".")
 *
 * @return mixed|null The value if found, otherwise null
 */
function get_json_value_by_path(array $data, string $path, string $delimiter = '.'): mixed
{
    $keys    = explode($delimiter, $path);
    $current = $data;

    foreach ($keys as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return null; // Path not found
        }
        $current = $current[$key];
    }

    return $current;
}

/**
 * Extract multiple values by paths from a decoded JSON array.
 *
 * For each path in $paths, returns the value resolved by get_json_value_by_path(),
 * keyed by the original path string.
 *
 * @param array<string,mixed> $data      Decoded JSON as associative array
 * @param string[]            $paths     List of delimited key paths
 * @param string              $delimiter Delimiter separating path segments (default ".")
 *
 * @return array<string,mixed> Map of path => resolved value (or null if not found)
 */
function extract_values_by_paths(array $data, array $paths, string $delimiter = '.'): array
{
    $results = [];
    foreach ($paths as $path) {
        $results[$path] = get_json_value_by_path($data, $path, $delimiter);
    }

    return $results;
}

/**
 * Ensure a database table exists; auto-create using a migration file if missing.
 *
 * @param string $tableName         The name of the table to check/create
 * @param string $migrationFilename Full path to the migration file
 *
 * @throws \RuntimeException If migration file is missing
 *
 * @return void
 */
function ensure_table_exists(string $tableName, string $migrationFilename): void
{
    // Only run once per script execution
    static $executed = [];
    $key             = md5(serialize([$tableName, $migrationFilename]));

    if (isset($executed[$key])) {
        return;
    }

    if (Schema::hasTable($tableName)) {
        Log::debug('Table already exists', ['table' => $tableName]);
        $executed[$key] = true;

        return;
    }

    if (!file_exists($migrationFilename)) {
        throw new \RuntimeException("Migration file not found: {$migrationFilename}");
    }

    $migration = require $migrationFilename;
    $migration->up();
    Log::debug('Created missing table', ['table' => $tableName, 'migration_filename' => $migrationFilename]);

    $executed[$key] = true;
}

/**
 * Determine KDP trim size category ("regular" or "large") from dimension string.
 *
 * String must contain "inch" and at least two numeric dimensions.
 *
 * Examples:
 *   "8.5 x 0.24 x 11 inches"   → "large"
 *   "6 x 0.45 x 9 inches"      → "regular"
 *   "11.4 x 8.3 x 0.21 inches" → "large"
 *   "9.84 x 9.84 x 0.2 inches" → "large"
 *
 * @return string|null "large", "regular", or null if unparsable
 */
function kdp_trim_size_inches(string $dimensions): ?string
{
    // Verify that the string contains "inch"
    if (!Str::contains($dimensions, 'inch', ignoreCase: true)) {
        return null;
    }

    if (!preg_match_all('/\d+(?:\.\d+)?/u', $dimensions, $m)) {
        return null; // can't parse numbers
    }

    $nums = array_map('floatval', $m[0]);
    if (count($nums) < 2) {
        return null;
    }

    // Take two largest numbers as trim width & height
    // Assume height is the largest dimension
    rsort($nums, SORT_NUMERIC);
    $height = $nums[0];
    $width  = $nums[1];

    // Apply KDP large-trim rule
    $isLarge = $width > 6.12 || $height > 9.0;

    return $isLarge ? 'large' : 'regular';
}

/**
 * Calculate US KDP (Kindle Direct Publishing) print cost for paperback books
 *
 * Note: This uses US marketplace pricing. Other marketplaces (UK, EU, etc.) have different rates.
 *
 * Ranges (US):
 * - Black ink: 24–108 => fixed only; >108–828 => fixed + per-page
 * - Premium color: 24–40 => fixed only; 42–828 => fixed + per-page (41 assumed to be in 42-828)
 * - Standard color: 72–600 => fixed + per-page
 *
 * @param int  $numPages        Number of pages in the book (24-828, or 72-600 for standard color)
 * @param bool $isColor         Whether the book uses color printing (default: false)
 * @param bool $isPremiumInk    Whether premium color ink is used (default: false)
 * @param bool $isTrimSizeLarge Whether the trim size is large (default: false)
 *
 * @throws \InvalidArgumentException If page count is out of valid range
 *
 * @return float Print cost in USD
 *
 * @see https://kdp.amazon.com/en_US/help/topic/G201834340
 */
function kdp_print_cost_us(
    int $numPages,
    bool $isColor = false,
    bool $isPremiumInk = false,
    bool $isTrimSizeLarge = false
): float {
    // To avoid errors, assume minimum 72 pages for color (non-premium), and minimum 24 pages otherwise
    if ($isColor && !$isPremiumInk && $numPages < 72) {
        $numPages = 72;
    } elseif ($numPages < 24) {
        $numPages = 24;
    }

    // Ensure valid page count
    if ($numPages > 828) {
        throw new \InvalidArgumentException("Paperback page count can't be greater than 828");
    }
    if ($isColor && !$isPremiumInk && $numPages > 600) {
        throw new \InvalidArgumentException("Paperback page count for standard color can't be greater than 600");
    }

    // Calculate fixed costs based on specifications
    if ($isColor) {
        // Color ink printing costs
        if ($isPremiumInk) {
            // Premium color
            if ($numPages <= 40) {
                $fixedCost   = !$isTrimSizeLarge ? 3.6 : 4.2;
                $perPageCost = 0;
            } else {
                $fixedCost   = 1;
                $perPageCost = !$isTrimSizeLarge ? 0.065 : 0.08;
            }
        } else {
            // Standard color
            $fixedCost   = 1;
            $perPageCost = !$isTrimSizeLarge ? 0.0255 : 0.0402;
        }
    } else {
        // Black ink printing costs
        if ($numPages <= 108) {
            $fixedCost   = !$isTrimSizeLarge ? 2.3 : 2.84;
            $perPageCost = 0;
        } else {
            $fixedCost   = 1;
            $perPageCost = !$isTrimSizeLarge ? 0.012 : 0.017;
        }
    }

    // Return the total print cost
    return $fixedCost + ($numPages * $perPageCost);
}

/**
 * Calculate US KDP (Kindle Direct Publishing) royalty for paperback books
 *
 * Note: This uses US marketplace pricing. Other marketplaces (UK, EU, etc.) have different rates.
 *
 * @param float $listPrice       List price in USD
 * @param int   $numPages        Number of pages in the book
 * @param bool  $isColor         Whether the book uses color printing (default: false)
 * @param bool  $isPremiumInk    Whether premium color ink is used (default: false)
 * @param bool  $isTrimSizeLarge Whether the trim size is large (default: false)
 *
 * @throws \InvalidArgumentException If page count is out of valid range
 *
 * @return float Royalty amount in USD
 */
function kdp_royalty_us(
    float $listPrice,
    int $numPages,
    bool $isColor = false,
    bool $isPremiumInk = false,
    bool $isTrimSizeLarge = false
): float {
    // 50% if price <= 9.98, else 60% (US thresholds)
    $royaltyRate = $listPrice <= 9.98 ? 0.5 : 0.6;

    $royalty   = $royaltyRate * $listPrice;
    $printCost = kdp_print_cost_us($numPages, $isColor, $isPremiumInk, $isTrimSizeLarge);

    return $royalty - $printCost;
}

/**
 * Convert BSR (Best Sellers Rank) to estimated monthly sales for books
 *
 * Formula based on results from Amazon book sales calculator in the linked URL.
 *
 * Uses power-law regression formulas based on Amazon book sales data:
 * - BSR 1-100: High-volume sellers
 * - BSR 101-100,000: Mid-range sellers
 * - BSR 100,001+: Long-tail sellers
 *
 * @param int|null $bsr Best Sellers Rank (1 = best selling)
 *
 * @return float|null Estimated monthly sales, or null if BSR is null
 *
 * @see https://www.tckpublishing.com/amazon-book-sales-calculator/
 */
function bsr_to_monthly_sales_books(?int $bsr): ?float
{
    if ($bsr === null) {
        return null;
    }

    if ($bsr <= 100) {
        return 84175 * pow($bsr, -0.459);
    } elseif ($bsr <= 100000) {
        return 385351 * pow($bsr, -0.766);
    } else {
        return 3913789 * pow($bsr, -0.982);
    }
}
