<?php

/*
 * DAILY TRACKING - SIMPLIFIED
 * ════════════════════════════
 *
 * Single-beacon approach: one request per pageview that only updates daily aggregates.
 *
 * DATA MODEL:
 *   - tracking_pageviews_daily: Aggregated data, one row per date/domain/is_internal/category
 *     (unique key: pageview_date, domain, is_internal, category)
 *
 * NO EVENT-LEVEL TRACKING:
 *   - No tracking_pageviews table writes
 *   - No view_id generation or tracking
 *   - No race conditions or backfill logic
 *   - No performance metrics
 *
 * BEHAVIOR:
 *   - Client sends: url, referrer, language, timezone, viewport_width, viewport_height
 *   - Server extracts: domain, is_internal, pageview_date
 *   - Server upserts: tracking_pageviews_daily (atomic increment)
 */

declare(strict_types=1);

namespace FOfX\Utility\PageviewTracking;

require_once __DIR__ . '/../pageview-tracking-core/track_common.php';

try {
    $config = get_tracking_config();
} catch (\Throwable $e) {
    conditional_error_log('track_daily.php get_tracking_config() error: ' . $e->getMessage());
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

if ($config['url'] === '') {
    http_response_code(400);
    exit;
}

/* ─────────────────────────────
   Process
   ───────────────────────────── */

try {
    upsert_daily_pageviews($config['pdo'], [
        ':pageview_date' => $config['pageview_date'],
        ':domain'        => $config['domain'],
        ':is_internal'   => $config['is_internal'],
        ':category'      => $config['category'],
    ]);
} catch (\Throwable $e) {
    conditional_error_log('track_daily.php error: ' . $e->getMessage());
}

http_response_code(204);
exit;
