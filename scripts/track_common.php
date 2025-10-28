<?php

declare(strict_types=1);

/* ─────────────────────────────
   Helpers
   ───────────────────────────── */

/**
 * Load environment variables from a .env file.
 *
 * @param string $path Path to the .env file
 *
 * @return void
 */
function load_env($path)
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        putenv("$key=$value");
    }
}

function str_clip(?string $v, int $len): string
{
    return substr((string)$v, 0, $len);
}

/**
 * Viewport dimension: accept 1...100000 px, else NULL.
 *
 * @param mixed $val
 *
 * @return int|null
 */
function sane_dimension_or_null($val): ?int
{
    if (!isset($val)) {
        return null;
    }
    $v = (int) $val;

    return ($v > 0 && $v <= 100000) ? $v : null;
}

/**
 * JS Date.now() in ms; keep if within [minMs, maxMs], else NULL.
 *
 * @param mixed $val
 * @param int   $minMs
 * @param int   $maxMs
 *
 * @return int|null
 */
function sane_ms_or_null($val, int $minMs, int $maxMs): ?int
{
    if (!isset($val)) {
        return null;
    }
    $v = (int) $val;

    return ($v >= $minMs && $v <= $maxMs) ? $v : null;
}

/**
 * Get the remote IP address of the client.
 *
 * Order: HTTP_CLIENT_IP → HTTP_X_FORWARDED_FOR → REMOTE_ADDR
 * If multiple IPs in X_FORWARDED_FOR, take the first. If blank, default to 127.0.0.1.
 * If invalid, return null (no exception).
 *
 * @return string|null A valid IP string, or null if invalid; returns '127.0.0.1' if none found.
 */
function get_remote_addr(): ?string
{
    $ipHeaders  = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    $remoteAddr = null;

    foreach ($ipHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $remoteAddr = $_SERVER[$header];
            if (strpos($remoteAddr, ',') !== false) {
                $remoteAddr = trim(explode(',', $remoteAddr)[0]);
            }

            break;
        }
    }

    if ($remoteAddr === null || $remoteAddr === '') {
        $remoteAddr = '127.0.0.1';
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : null;
}

/**
 * Internal page: URL path not empty and not '/'.
 *
 * @param string $url
 *
 * @return int 1 if internal, 0 if not
 */
function is_internal_page(string $url): int
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

    return ($path !== '' && $path !== '/') ? 1 : 0;
}

/**
 * Conditionally log error messages based on configuration.
 *
 * Checks LOG_LEVEL from .env and WP_DEBUG_LOG from wp-config.php.
 * Only logs if LOG_LEVEL is 'error' or lower severity (warning/notice/info/debug),
 * or if WP_DEBUG_LOG is true.
 * Defaults to logging if no configuration is found (fail-safe).
 *
 * Uses Monolog/PSR-3 severity hierarchy (higher number = more severe).
 *
 * @param string $message The error message to log
 *
 * @return void
 */
function conditional_error_log(string $message): void
{
    static $shouldLog = null;

    // Cache the decision (config won't change during request)
    if ($shouldLog === null) {
        // Default to 'debug' (log everything) if no config found
        $shouldLog = true;

        // Define severity hierarchy matching Monolog/PSR-3 (higher number = more severe)
        $levels = [
            'debug'     => 100,
            'info'      => 200,
            'notice'    => 250,
            'warning'   => 300,
            'error'     => 400,  // threshold
            'critical'  => 500,
            'alert'     => 550,
            'emergency' => 600,
        ];

        // Check .env LOG_LEVEL
        $logLevel = getenv('LOG_LEVEL');
        if ($logLevel !== false) {
            $logLevel = strtolower(trim($logLevel));
            // Only log if configured level is 'error' or lower severity (warning/notice/info/debug)
            if (isset($levels[$logLevel])) {
                $shouldLog = ($levels[$logLevel] <= $levels['error']);
            }
        }

        // Check WordPress WP_DEBUG_LOG (overrides if true)
        if (!$shouldLog && defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG') === true) {
            $shouldLog = true;
        }
    }

    if ($shouldLog) {
        error_log($message);
    }
}

/* ─────────────────────────────
   Bot Detection - User Agent
   ───────────────────────────── */

/**
 * Check if user agent string contains "googlebot" (case-insensitive).
 *
 * @param string $userAgent User agent string
 *
 * @return bool True if googlebot detected
 */
function is_googlebot_ua(string $userAgent): bool
{
    return stripos($userAgent, 'googlebot') !== false;
}

/**
 * Check if user agent string contains "bingbot" (case-insensitive).
 *
 * @param string $userAgent User agent string
 *
 * @return bool True if bingbot detected
 */
function is_bingbot_ua(string $userAgent): bool
{
    return stripos($userAgent, 'bingbot') !== false;
}

/* ─────────────────────────────
   Bot Detection - IP Ranges
   ───────────────────────────── */

/**
 * Load IP ranges for a specific bot.
 * Returns empty arrays if file not found.
 *
 * @param string $bot Bot name ('google' or 'bing')
 *
 * @return array Array with 'ipv4' and 'ipv6' keys
 */
function load_bot_ip_ranges(string $bot): array
{
    $file = __DIR__ . "/{$bot}_ip_ranges.php";
    if (!file_exists($file)) {
        return ['ipv4' => [], 'ipv6' => []];
    }

    return include $file;
}

/**
 * Check if IPv4 address is in any of the given ranges.
 * Uses CIDR-based binary comparison (32-bit safe, matches IPv6 logic).
 *
 * @param string $ip     IPv4 address
 * @param array  $ranges Array of IPv4 ranges with 'cidr', 'sources' keys
 * @param array  $filter Optional array of source names to filter by (empty = all sources)
 *
 * @return bool True if IP is in range
 */
function is_ipv4_in_ranges(string $ip, array $ranges, array $filter = []): bool
{
    // Validate IPv4 before converting to binary
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    $ipBin = inet_pton($ip);
    if ($ipBin === false || strlen($ipBin) !== 4) {
        return false;
    }

    foreach ($ranges as $range) {
        // Apply source filter first (before parsing CIDR)
        if (!empty($filter)) {
            $sources       = $range['sources'] ?? [];
            $matchesFilter = false;
            foreach ($filter as $source) {
                if (in_array($source, $sources, true)) {
                    $matchesFilter = true;

                    break;
                }
            }
            if (!$matchesFilter) {
                continue;
            }
        }

        // Use CIDR for comparison (platform-agnostic)
        if (empty($range['cidr']) || strpos($range['cidr'], '/') === false) {
            continue;
        }

        [$subnet, $prefixLen] = explode('/', $range['cidr'], 2);
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue;
        }
        $subnetBin = inet_pton($subnet);
        if ($subnetBin === false || strlen($subnetBin) !== 4) {
            continue;
        }

        $prefixLen = (int)$prefixLen;
        if ($prefixLen < 0 || $prefixLen > 32) {
            continue;
        }

        // Create mask
        $maskBin = str_repeat("\xff", (int)($prefixLen / 8));
        if ($prefixLen % 8 !== 0) {
            $maskBin .= chr(0xff ^ ((1 << (8 - ($prefixLen % 8))) - 1));
        }
        $maskBin = str_pad($maskBin, 4, "\x00");

        // Compare
        if (($ipBin & $maskBin) === ($subnetBin & $maskBin)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if IPv6 address is in any of the given ranges.
 *
 * @param string $ip     IPv6 address
 * @param array  $ranges Array of IPv6 ranges with 'cidr', 'prefix_length', 'sources' keys
 * @param array  $filter Optional array of source names to filter by (empty = all sources)
 *
 * @return bool True if IP is in range
 */
function is_ipv6_in_ranges(string $ip, array $ranges, array $filter = []): bool
{
    // Validate IPv6 before converting to binary
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return false;
    }

    $ipBin = inet_pton($ip);
    if ($ipBin === false || strlen($ipBin) !== 16) {
        return false;
    }

    foreach ($ranges as $range) {
        // Apply source filter first (before parsing CIDR)
        if (!empty($filter)) {
            $sources       = $range['sources'] ?? [];
            $matchesFilter = false;
            foreach ($filter as $source) {
                if (in_array($source, $sources, true)) {
                    $matchesFilter = true;

                    break;
                }
            }
            if (!$matchesFilter) {
                continue;
            }
        }

        // Use CIDR for comparison (platform-agnostic)
        if (empty($range['cidr']) || strpos($range['cidr'], '/') === false) {
            continue;
        }

        // Parse CIDR
        [$subnet, $prefixLen] = explode('/', $range['cidr'], 2);
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            continue;
        }
        $subnetBin = inet_pton($subnet);
        if ($subnetBin === false || strlen($subnetBin) !== 16) {
            continue;
        }

        $prefixLen = (int)$prefixLen;
        if ($prefixLen < 0 || $prefixLen > 128) {
            continue;
        }

        // Create mask
        $maskBin = str_repeat("\xff", (int)($prefixLen / 8));
        if ($prefixLen % 8 !== 0) {
            $maskBin .= chr(0xff ^ ((1 << (8 - ($prefixLen % 8))) - 1));
        }
        $maskBin = str_pad($maskBin, 16, "\x00");

        // Compare
        if (($ipBin & $maskBin) === ($subnetBin & $maskBin)) {
            return true;
        }
    }

    return false;
}

/**
 * Helper function to check if an IP belongs to a bot.
 * Auto-detects IPv4 vs IPv6.
 *
 * @param string $ip      IP address (IPv4 or IPv6)
 * @param string $bot     Bot name ('google' or 'bing')
 * @param array  $sources Optional array of source names to filter by (empty = all sources)
 *
 * @return bool True if IP matches the bot and sources
 */
function check_bot_ip(string $ip, string $bot, array $sources = []): bool
{
    static $cache = [];

    if (!isset($cache[$bot])) {
        $cache[$bot] = load_bot_ip_ranges($bot);
    }

    $ranges = $cache[$bot];

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return is_ipv4_in_ranges($ip, $ranges['ipv4'], $sources);
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return is_ipv6_in_ranges($ip, $ranges['ipv6'], $sources);
    }

    return false;
}

/**
 * Check if IP is a Googlebot IP (only 'goog' source).
 * Auto-detects IPv4 vs IPv6.
 *
 * @param string $ip IP address (IPv4 or IPv6)
 *
 * @return bool True if IP is a Googlebot IP
 */
function is_googlebot_ip(string $ip): bool
{
    return check_bot_ip($ip, 'google', ['goog']);
}

/**
 * Check if IP is any Google IP (all sources).
 * Auto-detects IPv4 vs IPv6.
 *
 * @param string $ip IP address (IPv4 or IPv6)
 *
 * @return bool True if IP is a Google IP
 */
function is_google_ip(string $ip): bool
{
    return check_bot_ip($ip, 'google');
}

/**
 * Check if IP is a Bingbot IP (only 'bingbot' source).
 * Auto-detects IPv4 vs IPv6.
 *
 * @param string $ip IP address (IPv4 or IPv6)
 *
 * @return bool True if IP is a Bingbot IP
 */
function is_bingbot_ip(string $ip): bool
{
    return check_bot_ip($ip, 'bing', ['bingbot']);
}

/**
 * Check if IP is any Microsoft IP (all sources).
 * Auto-detects IPv4 vs IPv6.
 *
 * @param string $ip IP address (IPv4 or IPv6)
 *
 * @return bool True if IP is a Microsoft IP
 */
function is_microsoft_ip(string $ip): bool
{
    return check_bot_ip($ip, 'bing');
}

/* ─────────────────────────────
   Blacklist Checking
   ───────────────────────────── */

/**
 * Check if an IP address is within a CIDR range.
 * Supports both IPv4 and IPv6.
 *
 * @param string $ip   IP address to check
 * @param string $cidr CIDR range (e.g., '192.168.1.0/24' or '2001:db8::/32')
 *
 * @return bool True if IP is in CIDR range
 */
function ip_in_cidr(string $ip, string $cidr): bool
{
    // Validate CIDR format
    if (strpos($cidr, '/') === false) {
        return false;
    }

    [$subnet, $prefixStr] = explode('/', $cidr, 2);
    $subnet               = trim($subnet);
    $prefixStr            = trim($prefixStr);

    // Prefix must be digits only
    if ($prefixStr === '' || !ctype_digit($prefixStr)) {
        return false;
    }
    $prefixLen = (int)$prefixStr;

    // Validate IP and subnet
    $ipBin     = inet_pton($ip);
    $subnetBin = inet_pton($subnet);

    if ($ipBin === false || $subnetBin === false) {
        return false;
    }

    // Must be same IP version
    if (strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $maxPrefixLen = (strlen($ipBin) === 4) ? 32 : 128;

    // Validate prefix length
    if ($prefixLen < 0 || $prefixLen > $maxPrefixLen) {
        return false;
    }

    // Create mask
    $fullBytes = intdiv($prefixLen, 8);
    $remBits   = $prefixLen % 8;

    $mask = ($fullBytes > 0) ? str_repeat("\xff", $fullBytes) : '';
    if ($remBits > 0) {
        $mask .= chr(0xff ^ ((1 << (8 - $remBits)) - 1));
    }
    $mask = str_pad($mask, strlen($ipBin), "\x00");

    // Compare network portions
    return ($ipBin & $mask) === ($subnetBin & $mask);
}

/**
 * Check if an IP address is blacklisted.
 *
 * @param string     $ip         IP address to check
 * @param array|null $ips        Individual IPs to check against (null = use config)
 * @param array|null $cidrBlocks CIDR ranges to check against (null = use config)
 *
 * @return bool True if IP is blacklisted
 */
function is_ip_blacklisted(string $ip, ?array $ips = null, ?array $cidrBlocks = null): bool
{
    // Load config if not provided
    if ($ips === null || $cidrBlocks === null) {
        static $config = null;
        if ($config === null) {
            $configFile = __DIR__ . '/track_config.php';
            $config     = file_exists($configFile) ? include $configFile : [];
        }

        if ($ips === null) {
            $ips = $config['blacklist_ips'] ?? [];
        }
        if ($cidrBlocks === null) {
            $cidrBlocks = $config['blacklist_ips_cidr'] ?? [];
        }
    }

    // Clean config arrays (trim whitespace, remove empty strings, ensure strings)
    $ips        = array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', $ips));
    $cidrBlocks = array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', $cidrBlocks));

    // Validate IP
    $ipBin = inet_pton($ip);
    if ($ipBin === false) {
        return false; // Invalid IPs can not be blacklisted
    }

    // Normalize IPv4-mapped IPv6 to IPv4
    $ipForCidr = $ip; // Default to original
    if (strlen($ipBin) === 16 && substr($ipBin, 0, 12) === str_repeat("\x00", 10) . "\xFF\xFF") {
        $ipv4Tail  = substr($ipBin, 12);
        $ipBin     = $ipv4Tail;                // For exact matches
        $ipForCidr = inet_ntop($ipv4Tail); // For CIDR checks
    }

    // Check individual IPs (binary comparison to handle IPv6 textual variants)
    foreach ($ips as $blacklistedIp) {
        $blacklistedBin = inet_pton($blacklistedIp);
        if ($blacklistedBin === false) {
            continue;
        }

        // Normalize IPv4-mapped IPv6 to IPv4
        if (strlen($blacklistedBin) === 16 && substr($blacklistedBin, 0, 12) === str_repeat("\x00", 10) . "\xFF\xFF") {
            $blacklistedBin = substr($blacklistedBin, 12);
        }

        if (strlen($ipBin) === strlen($blacklistedBin) && $ipBin === $blacklistedBin) {
            return true;
        }
    }

    // Check CIDR ranges (use normalized IP string)
    foreach ($cidrBlocks as $cidr) {
        if (ip_in_cidr($ipForCidr, $cidr)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if a user agent is blacklisted.
 *
 * @param string     $userAgent  User agent string to check
 * @param array|null $exact      Exact match strings (null = use config)
 * @param array|null $substrings Substring match strings (null = use config)
 *
 * @return bool True if user agent is blacklisted
 */
function is_user_agent_blacklisted(string $userAgent, ?array $exact = null, ?array $substrings = null): bool
{
    // Load config if not provided
    if ($exact === null || $substrings === null) {
        static $config = null;
        if ($config === null) {
            $configFile = __DIR__ . '/track_config.php';
            $config     = file_exists($configFile) ? include $configFile : [];
        }

        if ($exact === null) {
            $exact = $config['blacklist_user_agents_exact'] ?? [];
        }
        if ($substrings === null) {
            $substrings = $config['blacklist_user_agents_substring'] ?? [];
        }
    }

    // Check exact matches (case-insensitive)
    foreach ($exact as $blacklistedUA) {
        if (strcasecmp($userAgent, $blacklistedUA) === 0) {
            return true;
        }
    }

    // Check substring matches (case-insensitive)
    foreach ($substrings as $substring) {
        if (stripos($userAgent, $substring) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Check if an IP address or user agent is blacklisted.
 *
 * @param string $ip        IP address to check
 * @param string $userAgent User agent string to check
 *
 * @return bool True if either IP or user agent is blacklisted
 */
function is_blacklisted(string $ip, string $userAgent): bool
{
    return is_ip_blacklisted($ip) || is_user_agent_blacklisted($userAgent);
}

/* ─────────────────────────────
   Database Operations
   ───────────────────────────── */

/**
 * Insert a pageview record into tracking_pageviews table.
 * Uses INSERT IGNORE to avoid duplicate key errors.
 *
 * @param PDO   $pdo  Database connection
 * @param array $data Associative array with named parameters
 *
 * @return int Number of rows inserted (0 or 1)
 */
function insert_pageview(PDO $pdo, array $data): int
{
    $sql = 'INSERT IGNORE INTO tracking_pageviews
            (view_id, pageview_date, url, domain, referrer, ip, user_agent,
             language, timezone, viewport_width, viewport_height, ts_pageview_ms,
             ts_metrics_ms, ttfb_ms, dom_content_loaded_ms, load_event_end_ms,
             created_at, updated_at)
            VALUES
            (:view_id, :pageview_date, :url, :domain, :referrer, :ip, :user_agent,
             :language, :timezone, :viewport_width, :viewport_height, :ts_pageview_ms,
             NULL, NULL, NULL, NULL,
             NOW(), NOW())';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    $rowCount = $stmt->rowCount();

    // If insert failed (row already exists from metrics-first), try to backfill pageview data
    if ($rowCount === 0) {
        $updateSql = 'UPDATE tracking_pageviews
                      SET language = :language,
                          timezone = :timezone,
                          viewport_width = :viewport_width,
                          viewport_height = :viewport_height,
                          ts_pageview_ms = :ts_pageview_ms,
                          updated_at = NOW()
                      WHERE view_id = :view_id
                        AND ts_pageview_ms IS NULL';

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':view_id'         => $data[':view_id'],
            ':language'        => $data[':language'],
            ':timezone'        => $data[':timezone'],
            ':viewport_width'  => $data[':viewport_width'],
            ':viewport_height' => $data[':viewport_height'],
            ':ts_pageview_ms'  => $data[':ts_pageview_ms'],
        ]);
    }

    return $rowCount;
}

/**
 * Insert or update daily pageview aggregates.
 * Increments pageview counter for the given date/domain/category.
 *
 * UPSERT LOGIC:
 * - If row doesn't exist: INSERT with pageviews=1
 * - If row exists: INCREMENT pageviews by 1
 *
 * @param PDO   $pdo  Database connection
 * @param array $data Associative array with named parameters
 *
 * @return void
 */
function upsert_daily_pageviews(PDO $pdo, array $data): void
{
    $sql = 'INSERT INTO tracking_pageviews_daily
            (pageview_date, domain, is_internal, category, pageviews, created_at, updated_at)
            VALUES
            (:pageview_date, :domain, :is_internal, :category, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            pageviews = pageviews + 1,
            updated_at = NOW()';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

/**
 * Update metrics for an existing pageview record.
 * Only updates if ttfb_ms is still NULL (write-once idempotency).
 *
 * @param PDO    $pdo     Database connection
 * @param string $viewId  The unique view identifier
 * @param array  $metrics Associative array with metric values
 *
 * @return int Number of rows updated (0 or 1)
 */
function update_pageview_metrics(PDO $pdo, string $viewId, array $metrics): int
{
    $sql = 'UPDATE tracking_pageviews
            SET ts_metrics_ms = :ts_metrics_ms,
                ttfb_ms = :ttfb_ms,
                dom_content_loaded_ms = :dcl_ms,
                load_event_end_ms = :load_ms,
                updated_at = NOW()
            WHERE view_id = :view_id
              AND ttfb_ms IS NULL';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':view_id'       => $viewId,
        ':ts_metrics_ms' => $metrics['ts_metrics_ms'],
        ':ttfb_ms'       => $metrics['ttfb_ms'],
        ':dcl_ms'        => $metrics['dcl_ms'],
        ':load_ms'       => $metrics['load_ms'],
    ]);

    return $stmt->rowCount();
}

/**
 * Insert a complete pageview record including metrics.
 * Used when metrics beacon arrives but pageview beacon was lost.
 *
 * @param PDO   $pdo  Database connection
 * @param array $data Associative array with named parameters
 *
 * @return void
 */
function insert_pageview_with_metrics(PDO $pdo, array $data): void
{
    $sql = 'INSERT IGNORE INTO tracking_pageviews
            (view_id, pageview_date, url, domain, referrer, ip, user_agent,
             language, timezone, viewport_width, viewport_height,
             ts_pageview_ms, ts_metrics_ms, ttfb_ms, dom_content_loaded_ms, load_event_end_ms,
             created_at, updated_at)
            VALUES
            (:view_id, :pageview_date, :url, :domain, :referrer, :ip, :user_agent,
             :language, :timezone, :viewport_width, :viewport_height,
             :ts_pageview_ms, :ts_metrics_ms, :ttfb_ms, :dcl_ms, :load_ms,
             NOW(), NOW())';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

/**
 * Insert or update daily metrics counter.
 * Increments cnt_pageviews_with_metrics for the given date/domain/category.
 *
 * UPSERT LOGIC:
 * - If row doesn't exist: INSERT with pageviews=1, cnt_pageviews_with_metrics=1
 *   (Edge case: metrics beacon arrived first, pageview beacon lost)
 * - If row exists AND pageview was missing: INCREMENT both pageviews and cnt
 *   (Edge case: metrics-first but daily row exists from other views)
 * - If row exists AND pageview was found: INCREMENT cnt_pageviews_with_metrics ONLY
 *   (Normal case: pageview already counted, just increment metrics counter)
 *
 * @param PDO   $pdo               Database connection
 * @param array $data              Associative array with named parameters
 * @param bool  $isPageviewMissing Whether the pageview record was missing (metrics-first case)
 *
 * @return void
 */
function upsert_daily_metrics(PDO $pdo, array $data, bool $isPageviewMissing): void
{
    if ($isPageviewMissing) {
        // Metrics-first case: increment BOTH pageviews and cnt on conflict
        // (because this pageview was never counted in the daily aggregate)
        $sql = 'INSERT INTO tracking_pageviews_daily
                (pageview_date, domain, is_internal, category,
                 pageviews, cnt_pageviews_with_metrics, created_at, updated_at)
                VALUES
                (:pageview_date, :domain, :is_internal, :category,
                 1, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                pageviews = pageviews + 1,
                cnt_pageviews_with_metrics = cnt_pageviews_with_metrics + 1,
                updated_at = NOW()';
    } else {
        // Normal case: increment ONLY cnt on conflict
        // (pageview already counted when pageview beacon arrived)
        $sql = 'INSERT INTO tracking_pageviews_daily
                (pageview_date, domain, is_internal, category,
                 pageviews, cnt_pageviews_with_metrics, created_at, updated_at)
                VALUES
                (:pageview_date, :domain, :is_internal, :category,
                 1, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                cnt_pageviews_with_metrics = cnt_pageviews_with_metrics + 1,
                updated_at = NOW()';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

/**
 * Insert or update daily bot counters.
 * Conditionally increments bot-specific counters based on UA and IP checks.
 *
 * LOGIC:
 * - Checks all 6 bot conditions using helper functions
 * - Only increments matching bot counters (not all 6 every time)
 * - Uses ON DUPLICATE KEY UPDATE for safe concurrent increments
 *
 * @param PDO   $pdo  Database connection
 * @param array $data Associative array with named parameters
 *                    Required keys: pageview_date, domain, is_internal, category, ip, user_agent
 *
 * @return void
 */
function upsert_daily_bot_counters(PDO $pdo, array $data): void
{
    $ip        = $data[':ip'] ?? null;
    $userAgent = $data[':user_agent'] ?? '';

    // Check all 6 bot conditions
    $isGooglebotUA = is_googlebot_ua($userAgent);
    $isGooglebotIP = $ip ? is_googlebot_ip($ip) : false;
    $isGoogleIP    = $ip ? is_google_ip($ip) : false;
    $isBingbotUA   = is_bingbot_ua($userAgent);
    $isBingbotIP   = $ip ? is_bingbot_ip($ip) : false;
    $isMicrosoftIP = $ip ? is_microsoft_ip($ip) : false;

    // Build dynamic column list for INSERT and UPDATE
    $botColumns = [];
    $botValues  = [];
    $botUpdates = [];

    if ($isGooglebotUA) {
        $botColumns[] = 'googlebot_ua_pageviews';
        $botValues[]  = '1';
        $botUpdates[] = 'googlebot_ua_pageviews = googlebot_ua_pageviews + 1';
    }
    if ($isGooglebotIP) {
        $botColumns[] = 'googlebot_ip_pageviews';
        $botValues[]  = '1';
        $botUpdates[] = 'googlebot_ip_pageviews = googlebot_ip_pageviews + 1';
    }
    if ($isGoogleIP) {
        $botColumns[] = 'google_ip_pageviews';
        $botValues[]  = '1';
        $botUpdates[] = 'google_ip_pageviews = google_ip_pageviews + 1';
    }
    if ($isBingbotUA) {
        $botColumns[] = 'bingbot_ua_pageviews';
        $botValues[]  = '1';
        $botUpdates[] = 'bingbot_ua_pageviews = bingbot_ua_pageviews + 1';
    }
    if ($isBingbotIP) {
        $botColumns[] = 'bingbot_ip_pageviews';
        $botValues[]  = '1';
        $botUpdates[] = 'bingbot_ip_pageviews = bingbot_ip_pageviews + 1';
    }
    if ($isMicrosoftIP) {
        $botColumns[] = 'microsoft_ip_pageviews';
        $botValues[]  = '1';
        $botUpdates[] = 'microsoft_ip_pageviews = microsoft_ip_pageviews + 1';
    }

    // If no bot conditions matched, nothing to do
    if (empty($botColumns)) {
        return;
    }

    // Build SQL with dynamic columns
    $columnsStr = implode(', ', $botColumns);
    $valuesStr  = implode(', ', $botValues);
    $updatesStr = implode(', ', $botUpdates);

    $sql = "INSERT INTO tracking_pageviews_daily
            (pageview_date, domain, is_internal, category, {$columnsStr}, created_at, updated_at)
            VALUES
            (:pageview_date, :domain, :is_internal, :category, {$valuesStr}, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            {$updatesStr},
            updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':pageview_date' => $data[':pageview_date'],
        ':domain'        => $data[':domain'],
        ':is_internal'   => $data[':is_internal'],
        ':category'      => $data[':category'],
    ]);
}

/**
 * Get tracking configuration from request.
 *
 * @throws \RuntimeException If database credentials are not found
 *
 * @return array Tracking configuration
 */
function get_tracking_config(): array
{
    // Try to parse JSON payload (if POST with JSON)
    $raw     = '';
    $payload = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw     = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
    }

    $type   = (string) ($payload['type'] ?? '');
    $viewId = (string) ($payload['view_id'] ?? '');

    $url      = str_clip($payload['url'] ?? $_SERVER['REQUEST_URI'] ?? '', 4096);
    $referrer = str_clip($payload['referrer'] ?? '', 4096);
    $language = str_clip($payload['language'] ?? '', 64);
    $timezone = str_clip($payload['timezone'] ?? '', 64);

    $userAgent = str_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 1024);

    // Extract domain from URL, fallback to HTTP_HOST for relative URLs
    $parsedHost = parse_url($url, PHP_URL_HOST);
    if ($parsedHost === null || $parsedHost === '') {
        // URL is relative (e.g., /scripts/test.php), use HTTP_HOST
        $parsedHost = $_SERVER['HTTP_HOST'] ?? '';
    }
    $domain = strtolower(str_clip((string) $parsedHost, 255));

    $viewportWidth  = sane_dimension_or_null($payload['viewport_width'] ?? null);
    $viewportHeight = sane_dimension_or_null($payload['viewport_height'] ?? null);

    // timestamps (ms) window: 2000-01-01...now + 1y
    $nowMs = (int) round(microtime(true) * 1000);
    $minMs = 946684800000;            // 2000-01-01T00:00:00Z
    $maxMs = $nowMs + 31_536_000_000; // +1 year (365d * 24 * 60 * 60 * 1000)

    $tsPageviewMs = sane_ms_or_null($payload['ts_pageview_ms'] ?? null, $minMs, $maxMs);
    $tsMetricsMs  = sane_ms_or_null($payload['ts_metrics_ms'] ?? null, $minMs, $maxMs);

    // perf metrics (1...1h)
    $ttfbMs = sane_ms_or_null($payload['ttfb_ms'] ?? null, 1, 3_600_000);
    $dclMs  = sane_ms_or_null($payload['dom_content_loaded_ms'] ?? null, 1, 3_600_000);
    $loadMs = sane_ms_or_null($payload['load_event_end_ms'] ?? null, 1, 3_600_000);

    // page bucket (server TZ)
    $pageviewDate = $tsPageviewMs
        ? date('Y-m-d', (int) ($tsPageviewMs / 1000))
        : date('Y-m-d');

    // Get IP
    $ip = get_remote_addr();

    // 1 if path beyond '/', else 0
    $isInternal = is_internal_page($url);

    // Default category
    $category = 'none';

    $dbHost = null;
    $dbName = null;
    $dbUser = null;
    $dbPass = null;

    if (file_exists(__DIR__ . '/../.env')) {
        load_env(__DIR__ . '/../.env');
        $dbHost = getenv('DB_HOST');
        $dbName = getenv('DB_DATABASE');
        $dbUser = getenv('DB_USERNAME');
        $dbPass = getenv('DB_PASSWORD');
    } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/../wp-config.php')) {
        include_once $_SERVER['DOCUMENT_ROOT'] . '/../wp-config.php';
        if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASSWORD')) {
            $dbHost = constant('DB_HOST');
            $dbName = constant('DB_NAME');
            $dbUser = constant('DB_USER');
            $dbPass = constant('DB_PASSWORD');
        }
    }

    if ($dbHost === null || $dbName === null || $dbUser === null || $dbPass === null) {
        throw new \RuntimeException('No database credentials found.');
    }

    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    // Enable exceptions so try/catch blocks work (PDO defaults to silent errors)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return [
        'raw'             => $raw,
        'payload'         => $payload,
        'type'            => $type,
        'view_id'         => $viewId,
        'url'             => $url,
        'referrer'        => $referrer,
        'language'        => $language,
        'timezone'        => $timezone,
        'user_agent'      => $userAgent,
        'domain'          => $domain,
        'viewport_width'  => $viewportWidth,
        'viewport_height' => $viewportHeight,
        'now_ms'          => $nowMs,
        'min_ms'          => $minMs,
        'max_ms'          => $maxMs,
        'ts_pageview_ms'  => $tsPageviewMs,
        'ts_metrics_ms'   => $tsMetricsMs,
        'ttfb_ms'         => $ttfbMs,
        'dcl_ms'          => $dclMs,
        'load_ms'         => $loadMs,
        'pageview_date'   => $pageviewDate,
        'ip'              => $ip,
        'is_internal'     => $isInternal,
        'category'        => $category,
        'pdo'             => $pdo,
    ];
}
