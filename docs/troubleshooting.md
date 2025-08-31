# Troubleshooting Guide

This document tracks various issues encountered during development and their solutions. It serves as a reference for both current and future developers working with this codebase.

## Development Issues

### August 2025

#### PHPUnit 12.3.4 Handler Signature Change (8-12-2025)
- **Issue**: After running `composer update`, tests failed with: `Too few arguments to function PHPUnit\Runner\ErrorHandler::enable(), 0 passed ...` and many tests marked as "risky".
- **Problem**: PHPUnit 12.3.4 introduced a change in the error handler API that conflicted with the current Laravel/Testbench stack (HandleExceptions calling `ErrorHandler::enable()` without args).
- **Note**: This is a dependency compatibility issue, not an application code bug.
- **Solution**: Pinned PHPUnit to 12.3.3 in `composer.json` section `require-dev`, then ran `composer update phpunit/phpunit`. Tests returned to green.
- **Update (8-17-2025)**: Laravel 12.24 resolved this compatibility issue. PHPUnit 12.3.3 pin was removed.

#### Domain Validation PSL File Setup (8-17-2025)
- **Issue**: PHPUnit was downloading the Public Suffix List (PSL) file on every test run since it uses temporary storage, causing slow tests and network dependency. Additionally, PSL setup code was duplicated across multiple test methods.
- **Problem**: `Http::fake()` didn't work well because tests needed actual PSL data to validate domains properly. Additionally, committing the PSL file to version control was not desired.
- **Solution**:
  1. Ensure PSL file exists at `local/resources/public_suffix_list.dat`
  2. Created `tests/TestCase.php` base class with centralized PSL management
  3. Used `setUpBeforeClass()` to skip test classes gracefully if PSL file is missing
  4. Used `setUp()` with static flag (`private static bool $pslSetupComplete = false`) to copy PSL file only once per test suite instead of once per test method
  5. Added `autoload-dev` section to `composer.json` for proper test class autoloading
- **Efficiency**: Static flag prevents multiple unnecessary file copies across all test methods, improving test performance
- **Location**: See implementation in `tests/TestCase.php` and updated `tests/Unit/FunctionsTest.php`.

#### JSON_BIGINT_AS_STRING Ampersand Escaping Issue (8-31-2025)
- **Issue**: The `save_json_blocks_to_file()` function was escaping ampersand characters (`&`) as `\u0026` in JSON output, even when using `JSON_UNESCAPED_UNICODE` flag.
- **Root Cause**: `JSON_BIGINT_AS_STRING` and `JSON_HEX_AMP` both have the same numeric value (2) in PHP. Using `JSON_BIGINT_AS_STRING` in `json_encode()` accidentally enabled `JSON_HEX_AMP`, causing ampersands to be escaped.
- **Discovery Process**: 
  1. Created debug script (`/tmp/debug_json_flags.php`) to test various flag combinations
  2. Found that any combination including `JSON_BIGINT_AS_STRING` caused `&` → `\u0026` escaping
  3. Research revealed the flag value conflict: both constants have value 2
- **Solution**: Removed `JSON_BIGINT_AS_STRING` from `save_json_blocks_to_file()` JSON encoding flags. This flag is still correctly used in `extract_embedded_json_blocks()` during the JSON decode phase where it belongs.
- **Note**: `JSON_BIGINT_AS_STRING` is for JSON **decoding**, not for **encoding**. The `extract_embedded_json_blocks()` function correctly uses this flag in `json_decode()` to preserve precision of large numbers from HTML.