# Changelog

All notable changes to `laravel-translation` will be documented in this file.

## [Unreleased]

## [0.1.0] - 2026-01-30

### Added
- Spreadsheet URL output in push and pull commands for easy access to Google Sheets
- Comprehensive test suite with unit and feature tests
- GitHub Actions workflow for automated testing across PHP 8.2, 8.3 and Laravel 10, 11
- Configuration options for backup functionality (`backup.keep` and `backup.auto_prune`)
- Validation for backup pruning parameters

### Improved
- Enhanced backup functionality with better error handling
- Clearer console output for backup operations
- Better exception messages for backup-related errors
- Configuration documentation with backup settings

### Changed
- Backup creation now wrapped in try-catch for better error resilience
- Backup pruning now respects configuration values instead of hardcoded defaults

## [0.0.14] - Previous Release

### Changed
- Renamed commands to push/pull
- Various improvements to console messaging
- Don't create empty backups
- Keep original cell values
- Added sheet backups

[Unreleased]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.0.14...v0.1.0
[0.0.14]: https://github.com/paper-leaf-tech/laravel-translation/releases/tag/v0.0.14
