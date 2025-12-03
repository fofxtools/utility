<?php

/**
 * WordPress PHP SDK Delete All Posts
 *
 * Deletes all posts from a WordPress site using the MadeITBelgium WordPress SDK.
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use MadeITBelgium\WordPress\WordPress;

$start = microtime(true);

// Initialize
$wp = new WordPress(config('utility.wordpress.site_url'));
$wp->setUsername(config('utility.wordpress.username'))
    ->setApplicationPassword(config('utility.wordpress.app_password'));

// Fetch all posts
$posts = $wp->post()->list();

echo 'Found ' . count($posts) . " posts\n";

// Delete each post
foreach ($posts as $post) {
    echo "Deleting: {$post->title->rendered} (ID: {$post->id})... ";
    $wp->post()->delete($post->id, true); // true = force delete (skip trash)
    echo "✓\n";
}

echo "\nAll posts deleted.\n";

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
