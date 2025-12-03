# WordPress Examples

Simple example scripts for working with WordPress using the MadeITBelgium WordPress SDK.

## Prerequisites

Some scripts require **WP-CLI** to be installed:

- General Installation: https://wp-cli.org/#installing
- Windows Installation: https://make.wordpress.org/cli/handbook/guides/installing/#installing-on-windows

## Example Scripts

- [`examples/wordpress_reset_site.php`](../examples/wordpress_reset_site.php) - Resets WordPress site content using WP-CLI (requires WP-CLI)
- [`examples/wordpress_php_sdk_test.php`](../examples/wordpress_php_sdk_test.php) - Creates users, posts, tags, categories, and uploads media via REST API
- [`examples/wordpress_php_sdk_read_test.php`](../examples/wordpress_php_sdk_read_test.php) - Fetches and displays posts from WordPress via REST API
- [`examples/wordpress_php_sdk_delete_all_posts.php`](../examples/wordpress_php_sdk_delete_all_posts.php) - Deletes all posts from WordPress site via REST API