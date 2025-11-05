<?php

declare(strict_types=1);

/**
 * Tracking Configuration
 *
 * Database configuration and exclude IP and user agent settings.
 */

return [
    /**
     * Database configuration file path.
     *
     * Options:
     * - 'auto' (default): Auto-detect WordPress wp-config.php, then fallback to .env
     * - Relative path to wp-config.php: '../../../wp-config.php' (WordPress plugin context)
     * - Relative path to .env file: '../../../.env'
     *
     * Paths are resolved relative to this config file's directory.
     */
    'db_config_file' => 'auto',

    /**
     * Individual IP addresses to exclude (IPv4 or IPv6).
     *
     * e.g. admin IP, banned IPs, etc.
     *
     * Example: ['127.0.0.1', '192.168.1.100', '2001:db8::1']
     */
    'exclude_ips' => [],

    /**
     * CIDR ranges to exclude (IPv4 or IPv6).
     *
     * Example: ['192.168.1.0/24', '2001:db8::/32']
     */
    'exclude_ips_cidr' => [],

    /**
     * User agent strings to exclude (exact match, case-insensitive).
     *
     * Example: ['BadBot/1.0', 'Scraper/2.0']
     */
    'exclude_user_agents_exact' => [],

    /**
     * User agent substrings to exclude (substring match, case-insensitive).
     *
     * Example: ['badbot', 'scraper']
     */
    'exclude_user_agents_substring' => [],
];
