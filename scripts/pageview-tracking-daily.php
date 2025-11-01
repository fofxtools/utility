<?php

/**
 * Plugin Name: Pageview Tracking Daily
 * Plugin URI: https://github.com/fofxtools/utility
 * Description: Tracks pageviews (daily only) and bot traffic using JavaScript beacons and server-side PHP tracking.
 * Version: 1.0.0
 * Author: FOfX
 * Author URI: https://github.com/fofxtools/utility
 * License: MIT
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue the JavaScript tracking script
 */
function pvtd_enqueue_tracking_script()
{
    // Only load on frontend
    if (is_admin()) {
        return;
    }

    $script_path = plugin_dir_path(__FILE__) . 'track_daily.js';
    if (!file_exists($script_path)) {
        return;
    }

    wp_enqueue_script(
        'pageview-tracking-daily',
        plugin_dir_url(__FILE__) . 'track_daily.js',
        [],
        filemtime($script_path),
        true // Load in footer
    );

    // Pass the correct track_daily.php URL to JavaScript
    wp_localize_script(
        'pageview-tracking-daily',
        'PVTD',
        [
            'trackUrl' => plugin_dir_url(__FILE__) . 'track_daily.php',
        ]
    );
}
add_action('wp_enqueue_scripts', 'pvtd_enqueue_tracking_script');

/**
 * Track bot pageviews using server-side PHP
 */
function pvtd_track_bot_pageview()
{
    // Only track on frontend
    if (is_admin()) {
        return;
    }

    $bot_tracking_path = plugin_dir_path(__FILE__) . 'track_bots.php';
    if (file_exists($bot_tracking_path)) {
        require_once $bot_tracking_path;
    }
}
add_action('wp', 'pvtd_track_bot_pageview');
