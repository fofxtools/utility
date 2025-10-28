<?php

declare(strict_types=1);

require_once __DIR__ . '/track_common.php';

try {
    $config = get_tracking_config();
} catch (\Throwable $e) {
    conditional_error_log('track_bots.php get_tracking_config() error: ' . $e->getMessage());

    return;
}

/* ─────────────────────────────
   Blacklist Check
   ───────────────────────────── */

if (is_blacklisted($config['ip'] ?? '', $config['user_agent'] ?? '')) {
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
