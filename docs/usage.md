# Utility — Basic Usage Examples

Basic examples of the functions from this library.

## extract_registrable_domain
Get the registrable domain from a URL or hostname.

```php
use FOfX\Utility;

$domain = Utility\extract_registrable_domain('https://www.example.com/path');
echo $domain . PHP_EOL; // example.com
```

## download_public_suffix_list
Ensure the Public Suffix List exists locally and get its path.

```php
use FOfX\Utility;

$path = Utility\download_public_suffix_list();
echo $path . PHP_EOL; // e.g., /path/to/project/storage/app/public_suffix_list.dat
```

## get_tables
List tables for the current database connection.

```php
use FOfX\Utility;

$tables = Utility\get_tables();
print_r($tables);
```

