<?php

declare(strict_types=1);

namespace FOfX\Utility\PageviewTracking;

require_once __DIR__ . '/track_common.php';

try {
    $config = get_tracking_config();
} catch (\Throwable $e) {
    conditional_error_log('track_bots.php get_tracking_config() error: ' . $e->getMessage());

    // Use return instead of exit as this file is included via PHP and is not an HTTP entry point
    // Using exit would kill the HTTP request lifecycle
    return;
}

// Exit gracefully if tracking is disabled
if (!$config['tracking_enabled']) {
    return;
}

/* ─────────────────────────────
   Exclude Check
   ───────────────────────────── */

if (is_excluded($config['ip'] ?? '', $config['user_agent'] ?? '')) {
    return;
}

/* ─────────────────────────────
   Required
   ───────────────────────────── */

if ($config['url'] === '') {
    return;
}

/* ─────────────────────────────
   Process
   ───────────────────────────── */

try {
    upsert_daily_bot_counters($config['pdo'], [
        ':pageview_date' => $config['pageview_date'],
        ':domain'        => $config['domain'],
        ':is_internal'   => $config['is_internal'],
        ':category'      => $config['category'],
        ':ip'            => $config['ip'],
        ':user_agent'    => $config['user_agent'],
    ]);
} catch (\Throwable $e) {
    conditional_error_log('track_bots.php upsert_daily_bot_counters() error: ' . $e->getMessage());

    return;
}
