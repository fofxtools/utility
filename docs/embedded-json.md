## Parsing embedded JSON from HTML

This page shows how to quickly find and extract useful JSON blobs from an HTML file using:
- list_embedded_json_selectors()
- extract_embedded_json_blocks()

Use this to locate useful JSON (e.g., `script#perseus-initial-props`) and then inspect keys for fields that matter
in terms of business logic.

### Workflow

- Read the HTML into a string
- List suggested selectors for embedded JSON
- Extract JSON blocks and pick the block you want (e.g. `perseus-initial-props` for Fiverr)
- Inspect keys (e.g., with `Arr::dot`) to discover useful fields

### Example

See `examples/embedded_json_test.php`.

```php
require_once __DIR__ . '/../vendor/autoload.php';

use function FOfX\Utility\list_embedded_json_selectors;
use function FOfX\Utility\extract_embedded_json_blocks;
use Illuminate\Support\Arr;

$html = file_get_contents(__DIR__ . '/../resources/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.html');

// See what JSON <script> tags exist
$selectors = list_embedded_json_selectors($html, includeLdJson: true);
print_r($selectors); // e.g., ["script#layout-routes", "script#perseus-initial-props", ...]

// Extract blocks (decoded as associative arrays by default)
$blocks = extract_embedded_json_blocks($html, includeLdJson: true, assoc: true);

// Pick a block (ID match if present)
$index = 0;
foreach ($blocks as $i => $b) {
    if (($b['id'] ?? '') === 'perseus-initial-props') { $index = $i; break; }
}

$data = $blocks[$index]['json'] ?? [];

// Inspect keys to discover structure. Use array_keys() to remove values.
$paths = array_keys(Arr::dot($data));
print_r($paths);
```

Tip: To focus on a subtree, reassign $data before listing keys, e.g.:
```php
// For a Fiverr category listings page:
$data = $data['listings'][0]['gigs'] ?? [];
// Or use rawListingData section, which has less information:
//$data = $data['rawListingData']['gigs'] ?? [];

$paths = array_keys(Arr::dot($data));
print_r($paths);
```

### What to look for (useful fields)

Below are example paths we identified from **Fiverr** pages. Presence can vary by page or capture.

- Listings page (category/search)
  - listings.0.gigs     (gig listing information)
  - rawListingData.gigs (also contains gig information)
  - v2.report.search_total_results (result count)
  - appData.pagination  (pagination info)

- Gig page
  - seller               (seller information)
  - reviews              (review data)
  - reviews.reviews      (individual review information)

- Seller profile page
  - seller               (seller information)
  - reviews              (reviews)
  - gigsData             (seller's gig inventory)
  - reviewsData          (seller-level reviews data)

### Tips

- Use `Arr::dot($data)` → `array_keys()` to get a quick "schema-like" list of paths
- When investigating arrays, you may index into the first element (e.g., `gigs[0]`) to see item fields