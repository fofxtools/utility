# Utility — Basic Usage Examples

Basic examples of the functions from this library.

## get_tables
List tables for the current database connection.

```php
use FOfX\Utility;

$tables = Utility\get_tables();
print_r($tables);
```

## download_public_suffix_list
Ensure the Public Suffix List exists locally and get its path.

```php
use FOfX\Utility;

$path = Utility\download_public_suffix_list();
echo $path . PHP_EOL; // e.g., /path/to/project/storage/app/public_suffix_list.dat
```

## extract_registrable_domain
Get the registrable domain from a URL or hostname.

```php
use FOfX\Utility;

$domain = Utility\extract_registrable_domain('https://www.example.com/path');
echo $domain . PHP_EOL; // example.com
```

## is_valid_domain
Validate if a domain has a valid registrable domain and suffix.

```php
use FOfX\Utility;

$isValid = Utility\is_valid_domain('example.com');
var_dump($isValid); // bool(true)

$isValid = Utility\is_valid_domain('tld.invalid');
var_dump($isValid); // bool(false) - Invalid TLD extension

$isValid = Utility\is_valid_domain('192.168.1.1');
var_dump($isValid); // bool(false) - IP addresses are not valid domains
```

## extract_canonical_url
Extract the canonical URL from HTML content using Symfony DomCrawler.

```php
use FOfX\Utility;

$html = '<html><head><link rel="canonical" href="https://example.com/page"></head></html>';
$canonicalUrl = Utility\extract_canonical_url($html);
echo $canonicalUrl . PHP_EOL; // https://example.com/page

// Returns null if no canonical URL found
$html = '<html><head><title>No canonical</title></head></html>';
$canonicalUrl = Utility\extract_canonical_url($html);
var_dump($canonicalUrl); // NULL

// Symfony DomCrawler handles malformed HTML gracefully
$html = '<html><head><link rel="canonical" href="https://example.com"<title>Test</head>';
$canonicalUrl = Utility\extract_canonical_url($html);
echo $canonicalUrl . PHP_EOL; // https://example.com
```

## list_embedded_json_selectors
List CSS selectors for JSON script tags found in HTML.

```php
use FOfX\Utility;

$html = <<<'HTML'
<html>
  <head>
    <script type="application/json" id="perseus-initial-props">{"data": "value"}</script>
    <script type="application/ld+json" id="schema-data">{"@context": "https://schema.org"}</script>
  </head>
</html>
HTML;

$selectors = Utility\list_embedded_json_selectors($html);
print_r($selectors); 
/*
Array
(
    [0] => script#perseus-initial-props
    [1] => script#schema-data
)
*/

// Exclude LD+JSON scripts
$selectors = Utility\list_embedded_json_selectors($html, includeLdJson: false);
print_r($selectors); 
/*
Array
(
    [0] => script#perseus-initial-props
)
*/
```

## extract_embedded_json_blocks
Extract JSON blocks from HTML with metadata.

```php
use FOfX\Utility;

$html = <<<'HTML'
<html>
  <head>
    <script type="application/json" id="perseus-initial-props">{"userId": 123}</script>
    <script type="application/json">{"anonymous": true}</script>
  </head>
</html>
HTML;

$blocks = Utility\extract_embedded_json_blocks($html);
print_r($blocks);
/*
Array
(
    [0] => Array
        (
            [id] => perseus-initial-props
            [type] => application/json
            [bytes] => 15
            [attrs] => Array
                (
                    [id] => perseus-initial-props
                    [class] =>
                )

            [json] => Array
                (
                    [userId] => 123
                )

        )

    [1] => Array
        (
            [id] =>
            [type] => application/json
            [bytes] => 19
            [attrs] => Array
                (
                    [id] =>
                    [class] =>
                )

            [json] => Array
                (
                    [anonymous] => 1
                )

        )

)
*/
```

## save_json_blocks_to_file
Save extracted JSON blocks to a file using Laravel Storage.

```php
use FOfX\Utility;

$html = <<<'HTML'
<html>
  <head>
    <script type="application/json" id="perseus-initial-props">{"userId": 123, "settings": {"theme": "dark"}}</script>
    <script type="application/json" id="other-data">{"otherData": true}</script>
  </head>
</html>
HTML;

// Save specific block by selector ID
$savedFile = Utility\save_json_blocks_to_file($html, 'perseus-data.json', 'perseus-initial-props');
echo $savedFile . PHP_EOL; // perseus-data.json
/*
File contains (unwrapped since only one block matched):
{
    "userId": 123,
    "settings": {
        "theme": "dark"
    }
}
*/

// Save all blocks (no selector filter)
$savedFile = Utility\save_json_blocks_to_file($html, 'all-blocks.json');
/*
File contains array of all JSON blocks (wrapped since multiple blocks):
[
    {
        "userId": 123,
        "settings": {
            "theme": "dark"
        }
    },
    {
        "otherData": true
    }
]
*/

// Save with compact formatting (no pretty print)
$savedFile = Utility\save_json_blocks_to_file($html, 'compact.json', 'perseus-initial-props', false);
// File contains: {"userId":123,"settings":{"theme":"dark"}}

// No matching selector ID - saves empty array
$savedFile = Utility\save_json_blocks_to_file($html, 'empty.json', 'non-existent-id');
// File contains: []
```