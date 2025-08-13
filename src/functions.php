<?php

declare(strict_types=1);

namespace FOfX\Utility;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pdp\Rules;
use Pdp\Domain;
use FOfX\Helper;

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
    $path = storage_path('app/public_suffix_list.dat');

    if (file_exists($path)) {
        return $path;
    }

    $response = Http::get('https://publicsuffix.org/list/public_suffix_list.dat');

    if (!$response->successful()) {
        throw new \RuntimeException('Failed to download public suffix list');
    }

    if (!file_put_contents($path, $response->body())) {
        throw new \RuntimeException('Failed to save public suffix list');
    }

    return $path;
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
