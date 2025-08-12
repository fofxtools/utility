# Utility

A PHP library with a few practical helpers. Uses [jeremykendall/php-domain-parser](https://github.com/jeremykendall/php-domain-parser) for domain parsing.

- get_tables() - List database tables for the current connection
- download_public_suffix_list() - Ensure the Public Suffix List exists locally
- extract_registrable_domain() - Extract a registrable domain from a URL

## Installation

```bash
composer require fofx/utility
```

## Usage

See usage examples in:

- [docs/usage.md](docs/usage.md)

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

## License

MIT

