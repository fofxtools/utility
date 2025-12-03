<?php

/**
 * Reset WordPress site content using WP-CLI.
 *
 * Empties content and deletes non-admin users.
 */

// Check if WP-CLI is available
// Use exec() as we need the exit code
exec('wp --info 2>&1', $output, $exitCode);
if ($exitCode !== 0) {
    die("Error: WP-CLI is not installed or not in PATH. Please install WP-CLI first.\n" .
        "Visit: https://wp-cli.org/#installing\n" .
        "Or for Windows: https://make.wordpress.org/cli/handbook/guides/installing/#installing-on-windows\n");
}

$start = microtime(true);

$wpPath = dirname(__DIR__) . '/../wordpress';

// Empty site content
echo "Emptying site with `wp site empty --uploads`...\n";
shell_exec("cd \"{$wpPath}\" && wp site empty --uploads --yes 2>&1");

// Delete non-admin users
echo "Deleting users with `wp user delete` (except admin user ID 1)...\n";
$userIds = shell_exec("cd \"{$wpPath}\" && wp user list --field=ID 2>&1");
foreach (array_filter(explode("\n", trim($userIds))) as $userId) {
    if (trim($userId) !== '1') { // Don't delete admin user
        shell_exec("cd \"{$wpPath}\" && wp user delete {$userId} --yes 2>&1");
    }
}

echo "Done.\n";

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
