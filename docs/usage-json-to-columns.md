# JSON to Columns - Basic Usage

This guide shows how to go from JSON to a list of Laravel-like column definitions. And how to then fetch values by JSON paths.

See: [../examples/json_to_columns.php](../examples/json_to_columns.php)

Notes
- Default path delimiter is a dot (".") for readability.
- If your JSON keys may include dots, pass a custom delimiter like "__".
- The example file uses "__" to keep paths safe for database column names.

## infer_laravel_type()
Infer a reasonable Laravel migration type from a PHP value.

```php
use FOfX\Utility;

echo Utility\infer_laravel_type(null) . PHP_EOL;
echo Utility\infer_laravel_type('hello') . PHP_EOL;
echo Utility\infer_laravel_type(str_repeat('a', 300)) . PHP_EOL;
echo Utility\infer_laravel_type(3.14) . PHP_EOL;
echo Utility\infer_laravel_type(42) . PHP_EOL;
echo Utility\infer_laravel_type(true) . PHP_EOL;
echo Utility\infer_laravel_type(['a' => 1]) . PHP_EOL;

/*
string
string
text
float
integer
boolean
text
*/
```

## inspect_json_types()
Walk an associative (decoded) JSON array and produce a flat map of "path => type".

- When infer=false, scalar types come from PHP's gettype()
- When infer=true, scalar types are adjusted by infer_laravel_type() heuristics
- List arrays (`array_is_list()`) are not recursed; recorded as 'array' when infer=false, and as 'text' when infer=true

```php
use FOfX\Utility;

$data  = json_decode(file_get_contents(__DIR__ . '/../resources/listing-selected-fields.json'), true);
$types = Utility\inspect_json_types($data); // default delimiter is '.'; infer=false
print_r($types);

// With custom delimiter and type inference
$types = Utility\inspect_json_types($data, delimiter: '__', infer: true);

/*
Array
(
    [categoryIds__categoryId] => string
    [categoryIds__subCategoryId] => string
    [categoryIds__nestedSubCategoryId] => string
    [activeFilters] => text
    [currency__name] => string
    [currency__rate] => integer
    [currency__symbol] => string
    ...
)
*/
```

## types_to_columns()
Render the map from inspect_json_types() as simple column definition lines.

```php
$types = Utility\inspect_json_types($data, delimiter: '__', infer: true);
echo Utility\types_to_columns($types);
```

Sample output:

```
string('categoryIds__categoryId')
text('activeFilters')
integer('currency__rate')
```

## get_json_value_by_path()
Get a nested value from an associative array by a delimited path.

```php
// Using default '.' delimiter
echo Utility\get_json_value_by_path($data, 'appData.pagination.total') . PHP_EOL;

// Using '__' delimiter
echo Utility\get_json_value_by_path($data, 'appData__pagination__total', delimiter: '__') . PHP_EOL;

// Output: 215797
```
Returns null if any segment is missing.

## extract_values_by_paths()
Fetch multiple paths at once. Returns an array keyed by the original path strings.

```php
$types  = Utility\inspect_json_types($data, delimiter: '__', infer: true);
$paths  = array_keys($types);
$values = Utility\extract_values_by_paths($data, $paths, delimiter: '__');
print_r($values);

/*
Array
(
    [categoryIds__categoryId] => 3
    [categoryIds__subCategoryId] => 49
    [categoryIds__nestedSubCategoryId] =>
    [activeFilters] => Array
        (
        )

    [currency__name] => USD
    [currency__rate] => 1
    [currency__symbol] => $

    ...

)
*/
```