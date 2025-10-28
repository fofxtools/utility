<?php

declare(strict_types=1);

/**
 * Tracking Configuration
 *
 * Blacklist settings for IP addresses and user agents.
 */

return [
    /**
     * Individual IP addresses to blacklist (IPv4 or IPv6).
     *
     * Example: ['192.168.1.100', '2001:db8::1']
     */
    'blacklist_ips' => [],

    /**
     * CIDR ranges to blacklist (IPv4 or IPv6).
     *
     * Example: ['192.168.1.0/24', '2001:db8::/32']
     */
    'blacklist_ips_cidr' => [],

    /**
     * User agent strings to blacklist (exact match, case-insensitive).
     *
     * Example: ['BadBot/1.0', 'Scraper/2.0']
     */
    'blacklist_user_agents_exact' => [],

    /**
     * User agent substrings to blacklist (substring match, case-insensitive).
     *
     * Example: ['badbot', 'scraper']
     */
    'blacklist_user_agents_substring' => [],
];
