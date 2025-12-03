<?php

/**
 * WordPress PHP SDK Test Script
 *
 * Demonstrates creating users, posts, tags, categories, and media uploads
 * using the MadeITBelgium WordPress SDK.
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use MadeITBelgium\WordPress\WordPress;
use Faker\Factory as Faker;

/**
 * Get or upload media file to WordPress.
 * Searches for existing media by filename before uploading.
 *
 * @param WordPress $wp       WordPress instance
 * @param string    $filename Filename (e.g., 'hello-world.jpg')
 * @param string    $filepath Full path to the file
 *
 * @return object Media object
 */
function get_or_upload_media($wp, $filename, $filepath)
{
    // Extract filename without extension for search
    $searchTerm = pathinfo($filename, PATHINFO_FILENAME);

    // Search for existing media
    $existingMedia = $wp->getCall("/wp/v2/media?search={$searchTerm}");

    // Filter for exact match on slug (filename without extension)
    foreach ($existingMedia as $media) {
        if ($media->slug === $searchTerm) {
            return $media;
        }
    }

    // Upload new media
    $media = $wp->postCall('/wp/v2/media', [
        'multipart' => [
            [
                'name'     => 'file',
                'contents' => file_get_contents($filepath),
                'filename' => $filename,
            ],
        ],
    ]);

    return $media;
}

/**
 * Get or create a tag by name.
 *
 * @param WordPress $wp   WordPress instance
 * @param string    $name Tag name
 *
 * @return object Tag object
 */
function get_or_create_tag($wp, $name)
{
    $existing = $wp->tag()->list(['search' => $name]);
    // Filter for exact match
    foreach ($existing as $tag) {
        if ($tag->name === $name) {
            return $tag;
        }
    }

    return $wp->tag()->create(['name' => $name]);
}

/**
 * Get or create a category by name.
 *
 * @param WordPress $wp   WordPress instance
 * @param string    $name Category name
 *
 * @return object Category object
 */
function get_or_create_category($wp, $name)
{
    $existing = $wp->getCall("/wp/v2/categories?search={$name}");
    // Filter for exact match
    foreach ($existing as $category) {
        if ($category->name === $name) {
            return $category;
        }
    }

    return $wp->postCall('/wp/v2/categories', ['name' => $name]);
}

$start = microtime(true);

// Initialize
$wp = new WordPress(config('utility.wordpress.site_url'));
$wp->setUsername(config('utility.wordpress.username'))
    ->setApplicationPassword(config('utility.wordpress.app_password'));

$faker = Faker::create();

// Create a user
echo 'Creating user... ';
$userData = [
    'username' => $faker->userName,
    'email'    => $faker->email,
    // Strong password
    'password' => substr(str_shuffle('Aa1!' . bin2hex(random_bytes(8))), 0, 16),
];
$user = $wp->user()->create($userData);
echo "(ID: {$user->id}, Username: {$user->username})\n";

// Get or create a tag
echo 'Getting or creating tag... ';
$tag = get_or_create_tag($wp, $faker->word);
echo "(ID: {$tag->id}, Name: {$tag->name})\n";

// Get or create a category
echo 'Getting or creating category... ';
$category = get_or_create_category($wp, $faker->word);
echo "(ID: {$category->id}, Name: {$category->name})\n";

// Create a post
echo 'Creating post... ';
$postData = [
    'title'      => $faker->sentence,
    'content'    => $faker->paragraphs(3, true),
    'status'     => 'publish',
    'author'     => $user->id,
    'tags'       => [$tag->id],
    'categories' => [$category->id],
];
$post = $wp->post()->create($postData);
echo "(ID: {$post->id})\n";

// Update the post
echo 'Updating post... ';
$updateData = [
    'content' => $post->content->raw . "\n\n" . $faker->paragraph,
];
$updatedPost = $wp->post()->update($post->id, $updateData);
echo "\n";

// Get or upload featured image
echo 'Getting or uploading featured image... ';
$imagePath = __DIR__ . '/../images/hello-world.jpg';
if (file_exists($imagePath)) {
    $media = get_or_upload_media($wp, 'hello-world.jpg', $imagePath);
    echo "(Media ID: {$media->id}, URL: {$media->source_url})\n";

    // Set as featured image
    echo 'Setting featured image... ';
    $wp->post()->update($post->id, ['featured_media' => $media->id]);
    echo "\n";
} else {
    echo "(Image not found: {$imagePath})\n";
}

echo "\nSummary:\n";
echo "- User: {$user->username} (ID: {$user->id})\n";
echo "- Tag: {$tag->name} (ID: {$tag->id})\n";
echo "- Category: {$category->name} (ID: {$category->id})\n";
echo "- Post: {$post->title->rendered} (ID: {$post->id})\n";
echo "- Post URL: {$post->link}\n";

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
