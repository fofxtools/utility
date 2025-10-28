<?php

/**
 * Fill tracking_pageviews_daily from tracking_pageviews
 *
 * This script:
 * - Truncates tracking_pageviews_daily
 * - For each distinct (date, domain) pair in tracking_pageviews:
 *   - Fetches all rows for that date+domain
 *   - Groups by is_internal using is_internal_page() in PHP
 *   - For each group (is_internal=0 and is_internal=1):
 *     - Calculates pageviews, cnt_pageviews_with_metrics, and bot counters
 *     - Upserts tracking_pageviews_daily with the calculated values
 *
 * Result: Creates rows grouped by (date, domain, is_internal, category)
 *
 * For testing, first run scripts/generate_dummy_pageviews.php to generate the test data. Then
 * run this script. Then run scripts/calculate_daily_stats.php.
 */

declare(strict_types=1);

// Set memory limit to unlimited just in case
ini_set('memory_limit', '-1');

require_once __DIR__ . '/track_common.php';

$startTime = microtime(true);

/* ─────────────────────────────
   Database Connection
   ───────────────────────────── */

try {
    // Set up minimal $_SERVER variables for get_tracking_config()
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = '/';
    $_SERVER['HTTP_HOST']       = 'localhost';
    $_SERVER['HTTP_USER_AGENT'] = 'FillDailyTable/1.0';

    $config   = get_tracking_config();
    $pdo      = $config['pdo'];
    $category = $config['category'];
} catch (Exception $e) {
    die('Error: Could not connect to database: ' . $e->getMessage() . "\n");
}

/* ─────────────────────────────
   Main Logic
   ───────────────────────────── */

echo "Filling tracking_pageviews_daily from tracking_pageviews...\n";
echo "Category: {$category}\n\n";

// Step 1: Truncate the daily table (clean slate)
echo "Truncating tracking_pageviews_daily...\n";
$pdo->exec('TRUNCATE TABLE tracking_pageviews_daily');
echo "Done.\n\n";

// Step 2: Get distinct date+domain pairs
echo "Fetching distinct date+domain pairs...\n";
$stmt = $pdo->query('
    SELECT DISTINCT pageview_date, domain
    FROM tracking_pageviews
    ORDER BY pageview_date ASC, domain ASC
');

$pairs      = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPairs = count($pairs);
echo "Found {$totalPairs} date+domain pairs to process.\n\n";

// Prepare the UPSERT statement
$upsertStmt = $pdo->prepare('
    INSERT INTO tracking_pageviews_daily (
        pageview_date, domain, is_internal, category,
        pageviews, cnt_pageviews_with_metrics,
        googlebot_ua_pageviews, bingbot_ua_pageviews,
        googlebot_ip_pageviews, google_ip_pageviews,
        bingbot_ip_pageviews, microsoft_ip_pageviews,
        created_at, updated_at
    ) VALUES (
        :pageview_date, :domain, :is_internal, :category,
        :pageviews, :cnt_pageviews_with_metrics,
        :googlebot_ua_pageviews, :bingbot_ua_pageviews,
        0, 0, 0, 0,
        NOW(), NOW()
    )
    ON DUPLICATE KEY UPDATE
        pageviews = VALUES(pageviews),
        cnt_pageviews_with_metrics = VALUES(cnt_pageviews_with_metrics),
        googlebot_ua_pageviews = VALUES(googlebot_ua_pageviews),
        bingbot_ua_pageviews = VALUES(bingbot_ua_pageviews),
        updated_at = NOW()
');

// Step 3: Process each date+domain pair
$processedCount = 0;
foreach ($pairs as $pair) {
    $date   = $pair['pageview_date'];
    $domain = $pair['domain'];

    // Fetch all rows for this date+domain
    $fetchStmt = $pdo->prepare('
        SELECT url, ts_metrics_ms, user_agent
        FROM tracking_pageviews
        WHERE pageview_date = :pageview_date
          AND domain = :domain
    ');

    $fetchStmt->execute([
        ':pageview_date' => $date,
        ':domain'        => $domain,
    ]);

    // Initialize counters for both is_internal groups
    $counters = [
        0 => [ // is_internal = 0 (external/homepage)
            'pageviews'                  => 0,
            'cnt_pageviews_with_metrics' => 0,
            'googlebot_ua_pageviews'     => 0,
            'bingbot_ua_pageviews'       => 0,
        ],
        1 => [ // is_internal = 1 (internal pages)
            'pageviews'                  => 0,
            'cnt_pageviews_with_metrics' => 0,
            'googlebot_ua_pageviews'     => 0,
            'bingbot_ua_pageviews'       => 0,
        ],
    ];

    // Loop through all rows and count
    while ($row = $fetchStmt->fetch(PDO::FETCH_ASSOC)) {
        // Calculate is_internal using the PHP function
        $isInternal = is_internal_page($row['url']);

        // Increment pageviews counter
        $counters[$isInternal]['pageviews']++;

        // If metrics exist, increment counter
        if ($row['ts_metrics_ms'] !== null) {
            $counters[$isInternal]['cnt_pageviews_with_metrics']++;
        }

        // Check user agent for bots using track_common.php helpers
        $userAgent = $row['user_agent'] ?? '';
        if (is_googlebot_ua($userAgent)) {
            $counters[$isInternal]['googlebot_ua_pageviews']++;
        }
        if (is_bingbot_ua($userAgent)) {
            $counters[$isInternal]['bingbot_ua_pageviews']++;
        }
    }

    // Insert both groups (internal and external) if they have pageviews
    $totalPageviews = 0;
    foreach ([0, 1] as $isInternal) {
        if ($counters[$isInternal]['pageviews'] > 0) {
            $upsertStmt->execute([
                ':pageview_date'              => $date,
                ':domain'                     => $domain,
                ':is_internal'                => $isInternal,
                ':category'                   => $category,
                ':pageviews'                  => $counters[$isInternal]['pageviews'],
                ':cnt_pageviews_with_metrics' => $counters[$isInternal]['cnt_pageviews_with_metrics'],
                ':googlebot_ua_pageviews'     => $counters[$isInternal]['googlebot_ua_pageviews'],
                ':bingbot_ua_pageviews'       => $counters[$isInternal]['bingbot_ua_pageviews'],
            ]);

            $totalPageviews += $counters[$isInternal]['pageviews'];
        }
    }

    // Show progress
    $processedCount++;
    echo sprintf(
        "Processed %s %s: %d pageviews (%d internal, %d external) [%d/%d]\n",
        $date,
        $domain,
        $totalPageviews,
        $counters[1]['pageviews'],
        $counters[0]['pageviews'],
        $processedCount,
        $totalPairs
    );
}

$endTime  = microtime(true);
$duration = round($endTime - $startTime, 2);

echo "\nDone! Processed {$processedCount} date+domain pairs in {$duration} seconds.\n";
