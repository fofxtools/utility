<?php

/*
 * CLIENT-SIDE TRACKING FLOW
 * ═════════════════════════
 *
 * 1. Page Load → Generate unique viewId
 * 2. Page Visible → Send pageview beacon with viewId
 * 3. Window Load → Collect performance metrics → Send metrics beacon with same viewId
 * 4. Safety Net → If page closes before visible, send pageview anyway
 *
 * Visual Flow:
 *   Page Load → Generate viewId
 *                     ↓
 *             ┌───────────────┐
 *             │ Page Visible? │
 *             └───────────────┘
 *         YES ↓           ↓ NO
 *     Send Pageview   Wait → Send Pageview
 *             ↓           ↓
 *       Window Load   Window Load
 *             ↓           ↓
 *      Send Metrics  Send Metrics
 *
 * Result: Two beacons (pageview + metrics) with the same viewId.
 */

/*
 * DAILY AGGREGATION LOGIC - SYSTEM OVERVIEW
 * ══════════════════════════════════════════
 *
 * This tracking system uses a dual-beacon approach:
 *   1. Pageview beacon  → sent when page loads
 *   2. Metrics beacon   → sent after page load completes
 *
 * DATA MODEL:
 *   - tracking_pageviews: Raw data, one row per view (key: view_id)
 *   - tracking_pageviews_daily: Aggregated data, one row per date/domain/category
 *     (unique key: pageview_date, domain, is_internal, category)
 *
 * KEY INSIGHTS:
 *   1. The daily row aggregates many individual views. Whether the daily row exists
 *      tells us nothing about whether a specific view's pageview beacon arrived.
 *      Example: Daily row may exist from View A, but View B's pageview beacon was lost.
 *
 *   2. RACE CONDITION: Due to concurrent PHP processes and database transaction timing,
 *      the metrics beacon can commit BEFORE the pageview beacon, even though the pageview
 *      beacon arrives at the server first (typically by 2-20ms).
 *
 *   3. OPTIMISTIC CONCURRENCY: We handle this with backfill - when pageview beacon
 *      arrives and finds the row already exists (from metrics-first), it updates the
 *      row with pageview data (language, timezone, viewport, ts_pageview_ms).
 *
 * CASE-BY-CASE BEHAVIOR MAP:
 * ═════════════════════════════════════════════════════════════════════════════
 *
 * PAGEVIEW HANDLER (type='pageview'):
 * ┌──────┬─────────────────┬────────────┬───────────────────────────────────────┐
 * │ Case │ View Row Exists?│ Action     │ Daily Counter Action                  │
 * ├──────┼─────────────────┼────────────┼───────────────────────────────────────┤
 * │  1   │ No              │ INSERT     │ pageviews + 1                         │
 * │  2   │ Yes (race)      │ BACKFILL*  │ No increment (row exists = already    │
 * │      │                 │            │ counted by metrics handler)           │
 * └──────┴─────────────────┴────────────┴───────────────────────────────────────┘
 * *BACKFILL: UPDATE language, timezone, viewport_width, viewport_height, ts_pageview_ms
 *            (only if ts_pageview_ms IS NULL to prevent overwriting)
 *
 * METRICS HANDLER (type='metrics'):
 * ┌──────┬───────────────────┬──────────────────────┬────────────┬──────────────────────────────────────┐
 * │ Case │ Daily Row Exists? │ Pageview Record      │ UPSERT Path│ Action                               │
 * │      │                   │ Exists? (this view)  │            │                                      │
 * ├──────┼───────────────────┼──────────────────────┼────────────┼──────────────────────────────────────┤
 * │  3   │ No                │ No (metrics-first)   │ INSERT     │ pageviews = 1, cnt = 1               │
 * │  4   │ Yes               │ No (metrics-first)   │ UPDATE     │ pageviews+1, cnt+1 (EDGE CASE)       │
 * │  5   │ Yes               │ Yes (normal)         │ UPDATE     │ cnt+1 only (pageview already counted)│
 * └──────┴───────────────────┴──────────────────────┴────────────┴──────────────────────────────────────┘
 *
 * CRITICAL EDGE CASE (Case 4):
 *   - Metrics beacon arrives FIRST for View B (pageview beacon lost or delayed)
 *   - Daily row ALREADY EXISTS from View A earlier that day
 *   - View B's pageview was NEVER counted in daily aggregate
 *   - MUST increment BOTH pageviews and cnt (not just cnt)
 *   - Signal: $isPageviewMissing from update_pageview_metrics() returning 0 rows
 *
 * WHY $isPageviewMissing IS THE CORRECT SIGNAL:
 *   - It tells us whether THIS specific view's pageview beacon arrived
 *   - NOT whether the daily row exists (which could be from other views)
 *   - If pageview missing → view never counted → must increment pageviews
 *   - If pageview found → view already counted → only increment cnt
 *
 * TRANSACTION SAFETY:
 *   - Both handlers run inside PDO transactions
 *   - INSERT IGNORE prevents duplicate-key errors
 *   - Backfill UPDATE uses WHERE ts_pageview_ms IS NULL (idempotent, write-once)
 *   - Race conditions are handled gracefully without locking
 */

declare(strict_types=1);

namespace FOfX\Utility\PageviewTracking;

require_once __DIR__ . '/../pageview-tracking-core/track_common.php';

try {
    $config = get_tracking_config();
} catch (\Throwable $e) {
    conditional_error_log('track_pageview.php get_tracking_config() error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

/* ─────────────────────────────
   Exclude Check
   ───────────────────────────── */

if (is_excluded($config['ip'] ?? '', $config['user_agent'] ?? '')) {
    http_response_code(204);
    exit;
}

/* ─────────────────────────────
   Required
   ───────────────────────────── */

if ($config['type'] === '' || $config['view_id'] === '' || $config['url'] === '') {
    http_response_code(400);
    exit;
}
if ($config['type'] !== 'pageview' && $config['type'] !== 'metrics') {
    http_response_code(400);
    exit;
}

/* ─────────────────────────────
   Process
   ───────────────────────────── */

if ($config['type'] === 'pageview') {
    $config['pdo']->beginTransaction();

    try {
        // Insert pageview record
        $rowsInserted = insert_pageview($config['pdo'], [
            ':view_id'         => $config['view_id'],
            ':pageview_date'   => $config['pageview_date'],
            ':url'             => $config['url'],
            ':domain'          => $config['domain'],
            ':referrer'        => $config['referrer'],
            ':ip'              => $config['ip'],
            ':user_agent'      => $config['user_agent'],
            ':language'        => $config['language'],
            ':timezone'        => $config['timezone'],
            ':viewport_width'  => $config['viewport_width'],
            ':viewport_height' => $config['viewport_height'],
            ':ts_pageview_ms'  => $config['ts_pageview_ms'],
        ]);

        // Only increment daily pageview counter if insert succeeded
        if ($rowsInserted > 0) {
            upsert_daily_pageviews($config['pdo'], [
                ':pageview_date' => $config['pageview_date'],
                ':domain'        => $config['domain'],
                ':is_internal'   => $config['is_internal'],
                ':category'      => $config['category'],
            ]);
        }

        $config['pdo']->commit();
    } catch (\Throwable $e) {
        $config['pdo']->rollBack();
        conditional_error_log('track_pageview.php pageview error: ' . $e->getMessage());
    }

    http_response_code(204);
    exit;
} elseif ($config['type'] === 'metrics') {
    $config['pdo']->beginTransaction();

    try {
        // Try to update existing pageview record with metrics
        $rowsUpdated = update_pageview_metrics($config['pdo'], $config['view_id'], [
            'ts_metrics_ms' => $config['ts_metrics_ms'],
            'ttfb_ms'       => $config['ttfb_ms'],
            'dcl_ms'        => $config['dcl_ms'],
            'load_ms'       => $config['load_ms'],
        ]);

        // Determine if pageview record was missing (metrics-first case)
        $isPageviewMissing = ($rowsUpdated === 0);

        // If no row was updated, pageview beacon never arrived - insert stub
        if ($isPageviewMissing) {
            insert_pageview_with_metrics($config['pdo'], [
                ':view_id'         => $config['view_id'],
                ':pageview_date'   => $config['pageview_date'],
                ':url'             => $config['url'],
                ':domain'          => $config['domain'],
                ':referrer'        => $config['referrer'],
                ':ip'              => $config['ip'],
                ':user_agent'      => $config['user_agent'],
                ':language'        => $config['language'],
                ':timezone'        => $config['timezone'],
                ':viewport_width'  => $config['viewport_width'],
                ':viewport_height' => $config['viewport_height'],
                ':ts_pageview_ms'  => $config['ts_pageview_ms'],
                ':ts_metrics_ms'   => $config['ts_metrics_ms'],
                ':ttfb_ms'         => $config['ttfb_ms'],
                ':dcl_ms'          => $config['dcl_ms'],
                ':load_ms'         => $config['load_ms'],
            ]);
        }

        // Increment daily metrics counter (every time a metrics beacon arrives, so outside $isPageviewMissing)
        upsert_daily_metrics($config['pdo'], [
            ':pageview_date' => $config['pageview_date'],
            ':domain'        => $config['domain'],
            ':is_internal'   => $config['is_internal'],
            ':category'      => $config['category'],
        ], $isPageviewMissing);

        $config['pdo']->commit();
    } catch (\Throwable $e) {
        $config['pdo']->rollBack();
        conditional_error_log('track_pageview.php metrics error: ' . $e->getMessage());
    }

    http_response_code(204);
    exit;
}
