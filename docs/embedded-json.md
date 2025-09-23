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