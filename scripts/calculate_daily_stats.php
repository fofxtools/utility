<?php

/**
 * Calculate daily statistics for tracking_pageviews_daily table
 *
 * This script:
 * - Gets all rows from tracking_pageviews_daily WHERE cnt_pageviews_with_metrics > 0
 * - For each (date, domain, is_internal, category) row:
 *   - Fetches all rows for that date+domain with metrics
 *   - Filters by is_internal using is_internal_page()
 *   - Builds 3 arrays: $ttfb[], $dcl[], $load[]
 *   - Calculates avg, median, p95 for each metric
 *   - Updates tracking_pageviews_daily with the calculated stats
 */

// Set memory limit to unlimited just in case
ini_set('memory_limit', '-1');

require_once __DIR__ . '/track_common.php';

use function FOfX\Utility\PageviewTracking\get_tracking_config;
use function FOfX\Utility\PageviewTracking\is_internal_page;

/* ─────────────────────────────
   Helper Functions
   ───────────────────────────── */

/**
 * Calculate average of an array of numbers.
 *
 * @param array $array Array of numbers (int/float)
 *
 * @return float|null Average of numbers, or null if array is empty
 */
function calculate_average(array $array): ?float
{
    $count = count($array);

    return $count ? array_sum($array) / $count : null;
}

/**
 * Calculate median of an array of numbers.
 *
 * @param array $array Array of numbers (int/float)
 *
 * @return float|null Median of numbers, or null if array is empty
 */
function calculate_median(array $array): ?float
{
    $count = count($array);

    if ($count === 0) {
        return null;
    }

    sort($array);

    if ($count % 2) { // odd number of elements
        return $array[($count - 1) / 2];
    } else { // even number of elements
        return ($array[$count / 2 - 1] + $array[$count / 2]) / 2;
    }
}

/**
 * Calculate 95th percentile of an array of numbers.
 *
 * @param array $array Array of numbers (int/float)
 *
 * @return float|null 95th percentile of numbers, or null if array is empty
 */
function calculate_p95(array $array): ?float
{
    $count = count($array);

    if ($count === 0) {
        return null;
    }

    sort($array);

    return $array[(int)floor(($count - 1) * 0.95)];
}

/* ─────────────────────────────
   Configuration
   ───────────────────────────── */

$startTime = microtime(true);

// Set up minimal $_SERVER variables for get_tracking_config()
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['HTTP_USER_AGENT'] = 'CalculateDailyStats/1.0';

// Get database connection
$config = get_tracking_config();
$pdo    = $config['pdo'];

/* ─────────────────────────────
   Main Logic
   ───────────────────────────── */

echo "Starting statistics calculation...\n\n";

// Step 1: Get all rows from tracking_pageviews_daily WHERE cnt_pageviews_with_metrics > 0 AND processed_at IS NULL
echo "Fetching rows from tracking_pageviews_daily with metrics that need processing...\n";
$stmt = $pdo->query('
    SELECT pageview_date, domain, is_internal, category, cnt_pageviews_with_metrics
    FROM tracking_pageviews_daily
    WHERE cnt_pageviews_with_metrics > 0
      AND processed_at IS NULL
    ORDER BY pageview_date ASC, domain ASC, is_internal ASC
');

$rows      = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalRows = count($rows);
echo "Found {$totalRows} rows to process.\n\n";

// Prepare the fetch statement (reused for each date+domain)
$fetchStmt = $pdo->prepare('
    SELECT url, ttfb_ms, dom_content_loaded_ms, load_event_end_ms
    FROM tracking_pageviews
    WHERE pageview_date = :pageview_date
      AND domain = :domain
      AND ts_metrics_ms IS NOT NULL
');

// Prepare the update statement
$updateStmt = $pdo->prepare('
    UPDATE tracking_pageviews_daily
    SET avg_ttfb_ms = :avg_ttfb_ms,
        median_ttfb_ms = :median_ttfb_ms,
        p95_ttfb_ms = :p95_ttfb_ms,
        avg_dom_content_loaded_ms = :avg_dom_content_loaded_ms,
        median_dom_content_loaded_ms = :median_dom_content_loaded_ms,
        p95_dom_content_loaded_ms = :p95_dom_content_loaded_ms,
        avg_load_event_end_ms = :avg_load_event_end_ms,
        median_load_event_end_ms = :median_load_event_end_ms,
        p95_load_event_end_ms = :p95_load_event_end_ms,
        processed_at = NOW(),
        processed_status = :processed_status,
        updated_at = NOW()
    WHERE pageview_date = :pageview_date
      AND domain = :domain
      AND is_internal = :is_internal
      AND category = :category
');

// Step 2: Process each row
$counter = 0;
foreach ($rows as $row) {
    $counter++;
    $date          = $row['pageview_date'];
    $domain        = $row['domain'];
    $isInternal    = (int)$row['is_internal'];
    $category      = $row['category'];
    $expectedCount = (int)$row['cnt_pageviews_with_metrics'];

    // Fetch all rows for this date+domain with metrics
    $fetchStmt->execute([
        ':pageview_date' => $date,
        ':domain'        => $domain,
    ]);

    // Initialize arrays for the three metrics
    $ttfb        = [];
    $dcl         = [];
    $load        = [];
    $actualCount = 0;

    // Loop through all rows and filter by is_internal
    while ($pageview = $fetchStmt->fetch(PDO::FETCH_ASSOC)) {
        // Calculate is_internal using the PHP function
        $pageviewIsInternal = is_internal_page($pageview['url']);

        // Only include rows that match the current group's is_internal value
        if ($pageviewIsInternal === $isInternal) {
            if ($pageview['ttfb_ms'] !== null) {
                $ttfb[] = (float)$pageview['ttfb_ms'];
            }
            if ($pageview['dom_content_loaded_ms'] !== null) {
                $dcl[] = (float)$pageview['dom_content_loaded_ms'];
            }
            if ($pageview['load_event_end_ms'] !== null) {
                $load[] = (float)$pageview['load_event_end_ms'];
            }

            // We selected rows by ts_metrics_ms is not null, so always increment actual count
            $actualCount++;
        }
    }

    // Verify we got the expected count
    if ($actualCount !== $expectedCount) {
        echo "WARNING: Expected {$expectedCount} rows but got {$actualCount} for {$date} {$domain} is_internal={$isInternal}\n";
    }

    // Calculate statistics for each metric
    $avgTtfb    = calculate_average($ttfb);
    $medianTtfb = calculate_median($ttfb);
    $p95Ttfb    = calculate_p95($ttfb);

    $avgDcl    = calculate_average($dcl);
    $medianDcl = calculate_median($dcl);
    $p95Dcl    = calculate_p95($dcl);

    $avgLoad    = calculate_average($load);
    $medianLoad = calculate_median($load);
    $p95Load    = calculate_p95($load);

    // Update the daily row with stats and processing status
    $processedStatus = json_encode([
        'status'        => 'Success',
        'metrics_count' => $actualCount,
    ]);

    $updateStmt->execute([
        ':avg_ttfb_ms'                  => $avgTtfb,
        ':median_ttfb_ms'               => $medianTtfb,
        ':p95_ttfb_ms'                  => $p95Ttfb,
        ':avg_dom_content_loaded_ms'    => $avgDcl,
        ':median_dom_content_loaded_ms' => $medianDcl,
        ':p95_dom_content_loaded_ms'    => $p95Dcl,
        ':avg_load_event_end_ms'        => $avgLoad,
        ':median_load_event_end_ms'     => $medianLoad,
        ':p95_load_event_end_ms'        => $p95Load,
        ':processed_status'             => $processedStatus,
        ':pageview_date'                => $date,
        ':domain'                       => $domain,
        ':is_internal'                  => $isInternal,
        ':category'                     => $category,
    ]);

    // Show progress
    $isInternalLabel = $isInternal ? 'internal' : 'external';
    echo "Processed {$date} {$domain} ({$isInternalLabel}): {$actualCount} metrics [{$counter}/{$totalRows}]\n";
}

echo "\nDone! Processed {$totalRows} rows.\n";

$endTime  = microtime(true);
$duration = $endTime - $startTime;
echo "Total time: {$duration} seconds\n";
