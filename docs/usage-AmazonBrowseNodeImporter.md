## AmazonBrowseNodeImporter - Basic Usage

A helper class that imports Amazon browse nodes from a CSV file into the `amazon_browse_nodes` table.

See: [`../examples/amazon_browse_node_importer_test.php`](../examples/amazon_browse_node_importer_test.php)

### Data Source

The CSV file is from [letsnarrowdown.com](https://letsnarrowdown.com/) and should be placed in Laravel storage:

```
storage/app/private/amazon/amazon_product_categories.csv
```

The file is accessed via `Storage::disk('local')`.

### Basic Example

```php
use FOfX\Utility\AmazonBrowseNodeImporter;

$importer = new AmazonBrowseNodeImporter();

$stats = $importer->importBrowseNodesFromCsv();
print_r($stats);
// Array
// (
//     [inserted] => 34629
//     [skipped] => 123064
//     [errors] => 0
// )
```

### Main Method

**`importBrowseNodesFromCsv(int $batchSize = 500)`**

Batch inserts are used to improve speed. Individual inserts took about 20 minutes, while batch inserts take less than 1 minute.

In batch inserts, row insert order is not necessarily guaranteed. So we use two passes on the CSV to ensure that rows are inserted in level order. This way, parent browse node IDs will be inserted before child browse node IDs.

Orchestrates the complete import process:
- Collects nodes from CSV (pass 1)
- Inserts nodes by level (pass 2)
- Returns statistics

### Helper Methods

**`collectNodesFromCsv()`**

Parses the CSV file and builds an array of nodes with parent-child relationships. Handles:
- Extracting browse node IDs from parentheses (e.g., "Books (1000)")
- Building hierarchical paths
- Tracking levels (0 = department, 1+ = subcategories)
- Skipping duplicates and empty rows
- Translating string identifiers using `$hardcodedMappings`

**`insertNodesByLevel(array $nodesToInsert, int $batchSize = 500)`**

Inserts nodes level-by-level to ensure parents exist before children:
- Groups nodes by level
- Inserts level 0 (departments) first
- Then level 1, 2, 3, etc.
- Uses `insertOrIgnore()` to skip duplicates gracefully

### Hardcoded Mappings

Some CSV rows use string identifiers instead of numeric browse node IDs. The `$hardcodedMappings` array translates these:

```php
[
    'digital-music-album' => 324381011,
    'digital-music-track' => 324382011,
]
```