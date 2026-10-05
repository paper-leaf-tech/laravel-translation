# Changelog

All notable changes to `laravel-translation` will be documented in this file.

## [Unreleased]

## [0.5.0] - 2026-10-05

### Breaking Changes
- One `Translations` tab replaces the `Translations - {locale}` tabs. Columns are `key`, `group`, `default`, the source locale, one column per locale, then any columns you add, found by header name.
- `translations:push` takes no locale and always writes every locale. `--clear` and `--force-initial` are replaced by `--fresh`.
- Config: removed `key_column`, `original_value_column`, `updated_value_column` and `header_row`; added `sheet`, `source_locale` and `format`.
- Backups are one JSON file per push in the backup directory instead of one per locale.

### Added
- The sheet's English is editable. `default` holds the English from code as of the last push; when code and the sheet both change a line, push keeps the sheet's text and lists the conflict.
- Columns you add to the tab (`status`, `notes`, …) are kept with their row on every push.
- `translations:push --dry-run`.
- Sheet formatting after each push (frozen header, filter, widths, red empty translations, protected key/group/default), switchable with `TRANSLATION_FORMAT_SHEET`.
- `TranslationsPushed` event for app-specific tabs and formatting.
- `source_locale` config, used by push, pull and `translations:check`.
- `translations:check` treats each JSON key as its own English when there is no `lang/{source}.json`, the way Laravel renders it.
- Laravel 12 and 13 support in the test suite.

### Changed
- `translations:pull` exits with a failure when it rejects a value for mismatched `:placeholders` (the other values are still written).
- A malformed `lang/{locale}.json` now fails with the file's path instead of being read as empty.

### Upgrading
1. On 0.4.0, run `php artisan translations:pull` so code holds the latest translations.
2. Upgrade to 0.5.0 and update a published config to the new keys.
3. Run `php artisan translations:push` to build the `Translations` tab.
4. Delete the old `Translations - {locale}` tabs.

## [0.4.0] - 2026-10-05

### Added
- `translations:check` compares each locale with the source locale and fails on missing keys, extra keys or mismatched `:placeholders`. It covers PHP files (including subdirectories), JSON lines and published package overrides. Pass `--allow-missing` to fail only on placeholders.

### Changed
- `translations:pull` skips rows whose `:placeholders` differ from the source-locale line in code, with a warning listing each one. Previously such a row was written to the file and silently dropped the replacement at runtime.

## [0.3.1] - 2026-10-05

### Fixed
- `translations:pull` now writes into translation files in subdirectories. Push flattens `lang/{locale}/resources/schools.php` into keys like `resources.schools.title`; pull previously looked for `resources.php` and skipped every row. Pull now picks the deepest existing file that matches the key.
- `translations:push` now clears leftover rows below the data it writes. Previously, removing keys from code left the old trailing rows in the sheet as duplicates, and pull could apply those stale values over the live ones.
- The published config no longer references `Google\Service\Sheets::SPREADSHEETS`. Apps that installed the package with `--dev` and published the config crashed on boot in production (`--no-dev`) because the class was missing. If you already published the config, replace that line in your `config/laravel-translation.php` with `'https://www.googleapis.com/auth/spreadsheets'`.

## [0.3.0] - 2026-05-08

### Breaking Changes
- `translations:pull` no longer regenerates translation files. It now updates existing keys in place using AST manipulation, preserving comments, blank lines, indentation, and quote styles.
- Pull will not create files or directories. Running pull against a locale that has no `lang/{locale}/` directory yields a "skipped" warning instead of generated files. To bootstrap a new locale, create starter files manually first.
- Keys present in the sheet but missing from the local file are skipped with a per-key warning instead of being added. Add the key to the appropriate file in code, then re-pull to populate its value.
- Removed the `__misc.php` fallback for keys without a dot — those keys are now skipped with a warning.

### Added
- Surgical pull: comments, blank lines, indentation, and original quote styles in `lang/*.php` files are preserved across pulls.
- New service `TranslationFileWriter` (AST-based via `nikic/php-parser`).
- Per-file pull stats: keys updated, keys skipped (new), keys skipped (non-string values).

### Changed
- `nikic/php-parser ^5.0` is now a runtime dependency.

### Removed
- `PullCommand::generatePhpArray()` and the file-regeneration code path.
- `PullCommand::writeTranslations()` (replaced by `applyUpdates()` delegating to `TranslationFileWriter`).

## [0.2.0] - 2026-05-08

### Breaking Changes
- All env vars renamed: `GOOGLE_SHEETS_*` → `TRANSLATION_*` (`TRANSLATION_SPREADSHEET_ID`, `TRANSLATION_CREDENTIALS_PATH`, `TRANSLATION_BACKUP_KEEP`, `TRANSLATION_BACKUP_AUTO_PRUNE`).
- `GOOGLE_SHEETS_SHEET_NAME` removed. Each locale uses its own sheet tab named `Translations - {locale}` (auto-created on push).
- Default credentials filename changed from `laravel-translations-account.json` to `laravel-translation-credentials.json`. Either rename your file or set `TRANSLATION_CREDENTIALS_PATH`.
- For non-English sheets, **Column B is now the English source string** (was: the locale's own value). Column C is the translation. Untranslated rows (empty Column C) are skipped on pull instead of falling back to English.
- `GoogleSheetsService` public methods now take `string $sheetName` as the first parameter. Removed: `appendSheetData`, `createBackup`, `pruneBackups`. The `sheet_name` config key has been deleted.
- Existing in-spreadsheet "Backup YYYY-MM-DD" duplicate sheets are no longer created or pruned. Export anything you want to keep before upgrading.

### Added
- Multi-locale push/pull. Running `php artisan translations:push` or `:pull` with no argument iterates every locale under `lang/`.
- English-driven Column B for non-source locales — translators see the source string they're translating from.
- Auto-creation of locale sheet tabs when missing.
- Local JSON backups under `storage/app/translation-backups/{locale}/`. `.gitignore` written automatically on first run.
- `TRANSLATION_BACKUP_PATH` env var; setting it to `false` or `null` disables backups entirely.
- New services: `TranslationBackupManager`, `TranslationReconciler`. New constants class: `TranslationConventions`. New trait: `Concerns\DiscoversLocales`.
- Spreadsheet URLs printed after push/pull include `#gid=` to deep-link to the locale tab.
- Push reports stale-translation count, untranslated count, and target-only-key count for non-source locales.

### Removed
- In-spreadsheet duplicate-sheet backups.
- `GoogleSheetsService::appendSheetData()` (was unused).

### Considered, deferred
- Switching `addslashes()` → `var_export()` in PHP file generation — current behavior is correct for single-quoted output and a swap risks breaking snapshot-style output without enough benefit at this stage.

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

[Unreleased]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.0.14...v0.1.0
[0.0.14]: https://github.com/paper-leaf-tech/laravel-translation/releases/tag/v0.0.14
