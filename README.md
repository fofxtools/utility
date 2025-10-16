# Utility

A PHP library with a few practical helpers. Uses [jeremykendall/php-domain-parser](https://github.com/jeremykendall/php-domain-parser) for domain parsing.

- `get_tables()` - List database tables for the current connection
- `download_public_suffix_list()` - Ensure the Public Suffix List exists locally
- `extract_registrable_domain()` - Extract a registrable domain from a URL
- `is_valid_domain()` - Validate if a domain has a valid registrable domain and suffix
- `extract_canonical_url()` - Extract the canonical URL from HTML content
- `list_embedded_json_selectors()` - List CSS selectors for JSON script tags found in HTML
- `extract_embedded_json_blocks()` - Extract JSON blocks from HTML with additional metadata
- `filter_json_blocks_by_selector()` - Filter JSON blocks by selector ID, with optional 'json' key selection
- `save_json_blocks_to_file()` - Save extracted JSON blocks to a file with optional filtering by selector ID

## Installation

```bash
composer require fofx/utility
```

## Usage

See usage examples in:

- [docs/usage.md](docs/usage.md)
- [docs/usage-json-to-columns.md](docs/usage-json-to-columns.md)
- [docs/usage-FiverrSitemapImporter.md](docs/usage-FiverrSitemapImporter.md)
- [docs/usage-FiverrJsonImporter.md](docs/usage-FiverrJsonImporter.md)
- [docs/usage-AmazonProductPageParser.md](docs/usage-AmazonProductPageParser.md)
- [docs/usage-AmazonBrowseNodeImporter.md](docs/usage-AmazonBrowseNodeImporter.md)

### Extracting and Filtering Embedded JSON from HTML

You can use `filter_json_blocks_by_selector()` with `extract_embedded_json_blocks()` to extract embedded JSON from an HTML file, and filter it.

```php
require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\Utility;
use Illuminate\Support\Arr;

$filename = __DIR__ . '/../resources/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.html';
$html = file_get_contents($filename);
$blocks = Utility\extract_embedded_json_blocks($html);
$filtered = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
// Use Arr::dot() to get a dot notation list of keys, to see the JSON structure
$dot_keys_only = array_keys(Arr::dot($filtered[0] ?? []));
print_r($dot_keys_only);
```

### Importing Fiverr Sitemap Data (Categories and Tags)

See [docs/usage-FiverrSitemapImporter.md](docs/usage-FiverrSitemapImporter.md)

```php
use FOfX\Utility\FiverrSitemapImporter;

$importer = new FiverrSitemapImporter();
$importer->setBatchSize(500); // optional (default 100)

// Categories
$stats = $importer->importCategories();
print_r($stats);

// Tags
$stats = $importer->importTags();
print_r($stats);
```

### JSON to Columns

Helpers for working with JSON data and converting it to database columns.

See [docs/usage-json-to-columns.md](docs/usage-json-to-columns.md)

```php
use FOfX\Utility;

$filename = __DIR__ . '/../resources/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.html';
$html     = file_get_contents($filename);
$blocks   = Utility\extract_embedded_json_blocks($html);
$filtered = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
// Infer Laravel database columns types from the filtered JSON
// At the first item, first listing, first gig
$types   = Utility\inspect_json_types($filtered[0]['listings'][0]['gigs'][0], delimiter: '__', infer: true);
$columns = Utility\types_to_columns($types);
print_r($columns);
```

This gives you hints about the column types. Using `__` delimiter, which is friendly for column names. Partial output:

```
integer('gigId')
integer('pos')
string('type')
string('auction__id')
boolean('is_fiverr_choice')
integer('packages__recommended__id')
boolean('packages__recommended__extra_fast')
integer('packages__recommended__price')
integer('packages__recommended__duration')
integer('packages__recommended__price_tier')
string('packages__recommended__type')
string('sellerId')
...
```

## Testing and Development

To run the PHPUnit test suite through composer:

```bash
composer test
```

To use PHPStan for static analysis:

```bash
composer phpstan
```

To use PHP-CS-Fixer for code style:

```bash
composer cs-fix
```

### Test and the PSL file

Since tests use temporary storage, to avoid network calls during tests. `public_suffix_list.dat` ([download here](https://publicsuffix.org/list/public_suffix_list.dat)) must be saved to to `local/resources/`.

Tests then copy this file into temporary storage. If it is missing, tests are skipped.


## License

MIT

