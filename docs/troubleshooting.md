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