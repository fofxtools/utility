# Troubleshooting Guide

This document tracks various issues encountered during development and their solutions. It serves as a reference for both current and future developers working with this codebase.

## Development Issues

### August 2025

#### PHPUnit 12.3.4 Handler Signature Change (8-12-2025)
- **Issue**: After running `composer update`, tests failed with: `Too few arguments to function PHPUnit\Runner\ErrorHandler::enable(), 0 passed ...` and many tests marked as "risky".
- **Problem**: PHPUnit 12.3.4 introduced a change in the error handler API that conflicted with the current Laravel/Testbench stack (HandleExceptions calling `ErrorHandler::enable()` without args).
- **Note**: This is a dependency compatibility issue, not an application code bug.
- **Solution**: Pinned PHPUnit to 12.3.3 in `composer.json` section `require-dev`, then ran `composer update phpunit/phpunit`. Tests returned to green.

#### PSL File Download in Tests (8-13-2025)
- **Issue**: PHPUnit was downloading the Public Suffix List (PSL) file on every test run since it uses temporary storage, causing slow tests and network dependency.
- **Problem**: `Http::fake()` didn't work well because tests needed actual PSL data to validate domains properly. Additionally, committing the PSL file to version control was not desired.
- **Solution**: Stored the PSL file in `local/resources/public_suffix_list.dat` and configured tests to use this local copy. Tests skip if the file is not found, avoiding network calls during testing.
- **Location**: See implementation in `tests/Unit/FunctionsTest.php`.