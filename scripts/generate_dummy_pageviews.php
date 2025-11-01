<?php

/**
 * Generate dummy pageview data for testing and development.
 *
 * This script generates dummy pageview data for the tracking_pageviews table. This data
 * can then be used to calculate statistics for the tracking_pageviews_daily table.
 */

declare(strict_types=1);

require_once __DIR__ . '/track_common.php';

use function FOfX\Utility\PageviewTracking\get_tracking_config;
use function FOfX\Utility\PageviewTracking\load_env;

/* ─────────────────────────────
   Helper Functions
   ───────────────────────────── */

/**
 * Generate a random IP address (simple faker, not real bot IPs).
 */
function random_ip(): string
{
    return mt_rand(1, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255);
}

/**
 * Generate a random UUID v4.
 */
function generate_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Generate random performance metric with realistic distribution.
 *
 * Uses weighted random to favor middle of range. Value will now cluster around middle of range.
 */
function random_metric(int $min, int $max): float
{
    // Generate 3 random values and take average for more realistic distribution
    $sum = 0;
    for ($i = 0; $i < 3; $i++) {
        $sum += mt_rand($min, $max);
    }

    return round($sum / 3, 2);
}

/**
 * Generate realistic performance metrics with sequential relationship.
 * TTFB < DOM Content Loaded < Load Event End
 *
 * @return array{ttfb: float, dcl: float, load: float}
 */
function generate_performance_metrics(): array
{
    // TTFB: 50-350ms (most 100-200ms)
    $ttfb = random_metric(50, 350);

    // DCL: TTFB + 150-1650ms (most TTFB + 400-900ms)
    $dclOffset = random_metric(150, 1650);
    $dcl       = round($ttfb + $dclOffset, 2);

    // Load: DCL + 300-3000ms (most DCL + 500-1500ms)
    $loadOffset = random_metric(300, 3000);
    $load       = round($dcl + $loadOffset, 2);

    return [
        'ttfb' => $ttfb,
        'dcl'  => $dcl,
        'load' => $load,
    ];
}

/**
 * Generate random timestamp within a day (in milliseconds).
 */
function random_timestamp_in_day(DateTime $date): int
{
    $dayStart = (clone $date)->setTime(0, 0, 0);
    $dayEnd   = (clone $date)->setTime(23, 59, 59);

    $startMs = (int)($dayStart->getTimestamp() * 1000);
    $endMs   = (int)($dayEnd->getTimestamp() * 1000);

    return mt_rand($startMs, $endMs);
}

/* ─────────────────────────────
   Batch Insert Function
   ───────────────────────────── */

/**
 * Insert a batch of pageviews into the database.
 * Uses named parameters to avoid fragile array ordering.
 */
function insert_batch(PDO $pdo, array $batch): void
{
    if (empty($batch)) {
        return;
    }

    $sql = 'INSERT INTO tracking_pageviews
            (view_id, pageview_date, url, domain, referrer, ip, user_agent,
             language, timezone, viewport_width, viewport_height, ts_pageview_ms,
             ts_metrics_ms, ttfb_ms, dom_content_loaded_ms, load_event_end_ms,
             created_at, updated_at)
            VALUES ';

    $values   = [];
    $params   = [];
    $rowIndex = 0;

    foreach ($batch as $row) {
        // Use named parameters with row index to avoid conflicts
        $placeholders = [
            ":view_id_{$rowIndex}",
            ":pageview_date_{$rowIndex}",
            ":url_{$rowIndex}",
            ":domain_{$rowIndex}",
            ":referrer_{$rowIndex}",
            ":ip_{$rowIndex}",
            ":user_agent_{$rowIndex}",
            ":language_{$rowIndex}",
            ":timezone_{$rowIndex}",
            ":viewport_width_{$rowIndex}",
            ":viewport_height_{$rowIndex}",
            ":ts_pageview_ms_{$rowIndex}",
            ":ts_metrics_ms_{$rowIndex}",
            ":ttfb_ms_{$rowIndex}",
            ":dom_content_loaded_ms_{$rowIndex}",
            ":load_event_end_ms_{$rowIndex}",
            'NOW()',
            'NOW()',
        ];

        // Map row data to named parameters
        $params[":view_id_{$rowIndex}"]               = $row['view_id'];
        $params[":pageview_date_{$rowIndex}"]         = $row['pageview_date'];
        $params[":url_{$rowIndex}"]                   = $row['url'];
        $params[":domain_{$rowIndex}"]                = $row['domain'];
        $params[":referrer_{$rowIndex}"]              = $row['referrer'];
        $params[":ip_{$rowIndex}"]                    = $row['ip'];
        $params[":user_agent_{$rowIndex}"]            = $row['user_agent'];
        $params[":language_{$rowIndex}"]              = $row['language'];
        $params[":timezone_{$rowIndex}"]              = $row['timezone'];
        $params[":viewport_width_{$rowIndex}"]        = $row['viewport_width'];
        $params[":viewport_height_{$rowIndex}"]       = $row['viewport_height'];
        $params[":ts_pageview_ms_{$rowIndex}"]        = $row['ts_pageview_ms'];
        $params[":ts_metrics_ms_{$rowIndex}"]         = $row['ts_metrics_ms'];
        $params[":ttfb_ms_{$rowIndex}"]               = $row['ttfb_ms'];
        $params[":dom_content_loaded_ms_{$rowIndex}"] = $row['dom_content_loaded_ms'];
        $params[":load_event_end_ms_{$rowIndex}"]     = $row['load_event_end_ms'];

        $values[] = '(' . implode(', ', $placeholders) . ')';
        $rowIndex++;
    }

    $sql .= implode(', ', $values);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/* ─────────────────────────────
   Configuration
   ───────────────────────────── */

$startTime = microtime(true);

// Date range: Last 1 year
$endDate   = new DateTime();
$startDate = (clone $endDate)->modify('-1 year');

// Test domains
$domains = ['example.com', 'test.com', 'demo.org', 'sample.net', 'mysite.io'];

// Pageviews per day per domain (random)
$minPageviewsPerDay = 1;
$maxPageviewsPerDay = 10;

// Metrics coverage: 80% have metrics, 20% missing
$metricsChance = 0.8;

// Batch size for inserts
$batchSize = 100;

/* ─────────────────────────────
   Data Pools
   ───────────────────────────── */

$paths = [
    '/',
    '/about',
    '/products',
    '/contact',
    '/blog/post-1',
    '/blog/post-2',
    '/blog/post-3',
    '/services',
    '/pricing',
    '/faq',
    '/terms',
    '/privacy',
    '/support',
    '/docs',
    '/api',
];

$referrers = [
    null,
    'https://google.com/search',
    'https://www.google.com/search',
    'https://facebook.com',
    'https://twitter.com',
    'https://linkedin.com',
    'https://reddit.com',
    'direct',
];

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
];

$viewports = [
    ['width' => 1920, 'height' => 1080],
    ['width' => 1366, 'height' => 768],
    ['width' => 1536, 'height' => 864],
    ['width' => 1440, 'height' => 900],
    ['width' => 390, 'height' => 844],   // iPhone 13/14
    ['width' => 414, 'height' => 896],   // iPhone 11/XR
    ['width' => 393, 'height' => 852],   // iPhone 14 Pro
    ['width' => 360, 'height' => 800],   // Android
    ['width' => 412, 'height' => 915],   // Android
];

$languages = ['en-US', 'en-GB', 'es-ES', 'de-DE', 'fr-FR', 'it-IT', 'pt-BR', 'ja-JP'];

$timezones = [
    'America/New_York',
    'America/Los_Angeles',
    'America/Chicago',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Asia/Tokyo',
    'Asia/Shanghai',
    'Australia/Sydney',
];

/* ─────────────────────────────
   Database Connection
   ───────────────────────────── */

// Load environment and get database connection using track_common.php helper
if (file_exists(__DIR__ . '/../.env')) {
    load_env(__DIR__ . '/../.env');
}

try {
    // Use get_tracking_config() to get PDO connection
    // We need to set up minimal $_SERVER variables for get_tracking_config()
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = '/';
    $_SERVER['HTTP_HOST']       = 'localhost';
    $_SERVER['HTTP_USER_AGENT'] = 'DummyDataGenerator/1.0';

    $config = get_tracking_config();
    $pdo    = $config['pdo'];
} catch (Exception $e) {
    die('Error: Could not connect to database: ' . $e->getMessage() . "\n");
}

/* ─────────────────────────────
   Main Generation Logic
   ───────────────────────────── */

echo "Generating dummy pageview data...\n";
echo 'Date range: ' . $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d') . "\n";
echo 'Domains: ' . implode(', ', $domains) . "\n";
echo "Pageviews per day per domain: {$minPageviewsPerDay}-{$maxPageviewsPerDay}\n";
echo 'Metrics coverage: ' . ($metricsChance * 100) . "%\n\n";

$totalPageviews = 0;
$batch          = [];

// Iterate through each date
$currentDate = clone $startDate;
while ($currentDate <= $endDate) {
    $dateStr = $currentDate->format('Y-m-d');

    // Generate pageviews for each domain
    foreach ($domains as $domain) {
        $numPageviews = mt_rand($minPageviewsPerDay, $maxPageviewsPerDay);

        for ($i = 0; $i < $numPageviews; $i++) {
            // Generate random pageview data
            $viewId       = generate_uuid();
            $path         = $paths[array_rand($paths)];
            $url          = "https://{$domain}{$path}";
            $referrer     = $referrers[array_rand($referrers)];
            $ip           = random_ip();
            $userAgent    = $userAgents[array_rand($userAgents)];
            $viewport     = $viewports[array_rand($viewports)];
            $language     = $languages[array_rand($languages)];
            $timezone     = $timezones[array_rand($timezones)];
            $tsPageviewMs = random_timestamp_in_day($currentDate);

            // 80% chance: add performance metrics
            $hasMetrics  = (mt_rand(1, 100) / 100) <= $metricsChance;
            $tsMetricsMs = null;
            $ttfbMs      = null;
            $dclMs       = null;
            $loadMs      = null;

            if ($hasMetrics) {
                $tsMetricsMs = $tsPageviewMs + mt_rand(100, 5000);
                $metrics     = generate_performance_metrics();
                $ttfbMs      = $metrics['ttfb'];
                $dclMs       = $metrics['dcl'];
                $loadMs      = $metrics['load'];
            }

            // Add to batch
            $batch[] = [
                'view_id'               => $viewId,
                'pageview_date'         => $dateStr,
                'url'                   => $url,
                'domain'                => $domain,
                'referrer'              => $referrer,
                'ip'                    => $ip,
                'user_agent'            => $userAgent,
                'language'              => $language,
                'timezone'              => $timezone,
                'viewport_width'        => $viewport['width'],
                'viewport_height'       => $viewport['height'],
                'ts_pageview_ms'        => $tsPageviewMs,
                'ts_metrics_ms'         => $tsMetricsMs,
                'ttfb_ms'               => $ttfbMs,
                'dom_content_loaded_ms' => $dclMs,
                'load_event_end_ms'     => $loadMs,
            ];

            $totalPageviews++;

            // Insert batch when it reaches batch size
            if (count($batch) >= $batchSize) {
                insert_batch($pdo, $batch);
                $batch = [];
            }
        }

        echo "Generated {$numPageviews} pageviews for {$dateStr} ({$domain})\n";
    }

    $currentDate->modify('+1 day');
}

// Insert remaining batch
if (!empty($batch)) {
    insert_batch($pdo, $batch);
}

echo "\nDone! Generated {$totalPageviews} total pageviews.\n";

$endTime  = microtime(true);
$duration = $endTime - $startTime;
echo "Total time: {$duration} seconds\n";
