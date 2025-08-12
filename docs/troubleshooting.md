# Troubleshooting Guide

This document tracks various issues encountered during development and their solutions. It serves as a reference for both current and future developers working with this codebase.

## Development Issues

### August 2025

#### PHPUnit 12.3.4 Handler Signature Change (8-12-2025)
- **Issue**: After running `composer update`, tests failed with: `Too few arguments to function PHPUnit\Runner\ErrorHandler::enable(), 0 passed ...` and many tests marked as "risky".
- **Problem**: PHPUnit 12.3.4 introduced a change in the error handler API that conflicted with the current Laravel/Testbench stack (HandleExceptions calling `ErrorHandler::enable()` without args).
- **Note**: This is a dependency compatibility issue, not an application code bug.
- **Solution**: Pinned PHPUnit to 12.3.3 in `composer.json` section `require-dev`, then ran `composer update phpunit/phpunit`. Tests returned to green.