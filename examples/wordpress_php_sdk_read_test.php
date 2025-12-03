<?php

/**
 * WordPress PHP SDK Read Test
 *
 * Tests fetching posts from WordPress using the MadeITBelgium SDK.
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use MadeITBelgium\WordPress\WordPress;

$start = microtime(true);

// Initialize and test
$wp = new WordPress(config('utility.wordpress.site_url'));
$wp->setUsername(config('utility.wordpress.username'))
    ->setApplicationPassword(config('utility.wordpress.app_password'));

// Fetch posts
$posts = $wp->post()->list();

// Output
echo 'Posts found: ' . count($posts) . "\n";
foreach ($posts as $post) {
    echo "- {$post->title->rendered} (ID: {$post->id})\n";
}

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
