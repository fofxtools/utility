## AmazonProductPageParser - Basic Usage

A helper class that parses Amazon product pages and computes keyword statistics.

Fills these tables:
- `amazon_products` - Product details parsed from HTML pages
- `amazon_keywords_stats` - Computed statistics for keywords

See examples:
- [`../examples/amazon_product_page_parser_test.php`](../examples/amazon_product_page_parser_test.php)
- [`../examples/amazon_keywords_stats_test.php`](../examples/amazon_keywords_stats_test.php)

### External Dependencies

The following tables must already exist and be populated:
- `dataforseo_merchant_amazon_products_listings`
- `dataforseo_merchant_amazon_products_items`

These are external dependencies from the [`fofx/api-cache`](https://github.com/fofxtools/api-cache/) library, which is **not** a dependency of this library.

**Note:** `fofx/utility` is actually a dependency of `fofx/api-cache`, so this library cannot require it. Those tables must exist and be populated first before `fetchListingsRow()`, `fetchItems()`, and `insertAmazonKeywordsStatsRow()` can be used.

### Basic Example - Parse Product Page

```php
use FOfX\Utility\AmazonProductPageParser;

$parser = new AmazonProductPageParser();

// Load HTML file
$html = file_get_contents('resources/amazon_asin_B0FNY2674D.html');

// Parse all fields
$data = $parser->parseAll($html);
print_r($data);

// Insert into database
$stats = $parser->insertProduct($data);
print_r($stats);
// Array
// (
//     [inserted] => 1
//     [skipped] => 0
//     [asin] => B0FNY2674D
//     [reason] =>
// )
```

### Basic Example - Compute Keyword Stats

```php
use FOfX\Utility\AmazonProductPageParser;
use Illuminate\Support\Facades\DB;

$parser = new AmazonProductPageParser();

// Get keywords from external table
$keywords = DB::table('dataforseo_merchant_amazon_products_listings')
    ->select('keyword', 'location_code', 'language_code', 'device')
    ->distinct()
    ->get();

// Process each keyword
foreach ($keywords as $keywordRow) {
    $stats = $parser->insertAmazonKeywordsStatsRow(
        $keywordRow->keyword,
        $keywordRow->location_code,
        $keywordRow->language_code,
        $keywordRow->device
    );
    print_r($stats);
}
```

### Methods

#### Product Parsing

**`parseAll(string $html): array`**

Parses Amazon product page HTML and returns all extracted fields plus computed helper fields (page_count, BSR rank, KDP estimates, etc.).

**`insertProduct(array $data): array`**

Inserts parsed product data into `amazon_products` table using `insertOrIgnore()` to skip duplicates.

#### Statistics Computation

**`computeItemsStats(array $items): array`**

Computes statistics from items table rows (filters by `rank_absolute <= statsItemsLimit`).

**`computeProductsStats(array $products): array`**

Computes statistics from products table rows (JSON arrays, averages, counts for product fields).

**`computeScoresForStatsRow(array $listingsRow, array $items, array $products): array`**

Computes score_1 through score_10 and cv_monthly_sales_estimate for a keyword stats row.

**`computeAmazonKeywordsStatsRow(array $listingsRow, array $items, array $products): array`**

Computes complete stats row combining listings data, items stats, products stats, and scores.

#### External Table Helpers

**`fetchListingsRow(string $keyword, int $locationCode, string $languageCode, string $device): ?object`**

Helper to fetch a single row from `dataforseo_merchant_amazon_products_listings` table.

**`fetchItems(string $keyword, int $locationCode, string $languageCode, string $device): array`**

Helper to fetch matching rows from `dataforseo_merchant_amazon_products_items` table.

#### Main Method

**`insertAmazonKeywordsStatsRow(string $keyword, int $locationCode = 2840, string $languageCode = 'en_US', string $device = 'desktop'): array`**

Orchestrates the complete statistics computation workflow: fetches listings row, fetches items, fetches products, computes stats, and inserts into `amazon_keywords_stats` table.