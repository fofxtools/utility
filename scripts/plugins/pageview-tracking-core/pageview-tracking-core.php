<?php

/**
 * Plugin Name: Pageview Tracking Core
 * Plugin URI: https://github.com/fofxtools/utility
 * Description: Core shared functionality for pageview tracking plugins. Required by Pageview Tracking and Pageview Tracking Daily plugins.
 * Version: 1.0.0
 * Author: FOfX
 * Author URI: https://github.com/fofxtools/utility
 * License: MIT
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PVT_CORE_VERSION', '1.0.0');
define('PVT_CORE_PATH', plugin_dir_path(__FILE__));
define('PVT_CORE_URL', plugin_dir_url(__FILE__));

/**
 * Check if core plugin is active and loaded
 * Other plugins can use this to verify the core is available
 */
function pvt_core_is_loaded()
{
    return true;
}

/**
 * Get the path to a core plugin file
 *
 * @param string $file Filename relative to plugin directory
 *
 * @return string Full path to file
 */
function pvt_core_get_path($file = '')
{
    return PVT_CORE_PATH . ltrim($file, '/');
}

/**
 * Get the URL to a core plugin file
 *
 * @param string $file Filename relative to plugin directory
 *
 * @return string Full URL to file
 */
function pvt_core_get_url($file = '')
{
    return PVT_CORE_URL . ltrim($file, '/');
}
