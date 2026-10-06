# Laravel Translation Manager

Manage Laravel translation strings using Google Sheets. This package syncs your Laravel translation files with a Google Spreadsheet, making it easy for non-technical team members to manage translations.

## Upgrading

### From 0.4.x to 0.5.0

- One `Translations` tab replaces the `Translations - {locale}` tabs. Before upgrading, run `php artisan translations:pull` on 0.4 so code holds the latest translations. After upgrading, run `php artisan translations:push` to build the new tab, then delete the old tabs.
- `translations:push` no longer takes a locale; it always writes every locale. New options: `--dry-run` and `--fresh` (replaces `--clear` and `--force-initial`).
- `translations:pull {locale?}` reads the locale's column from the single tab.
- Config: `key_column`, `original_value_column`, `updated_value_column` and `header_row` are gone; `sheet`, `source_locale` and `format` are new. Update a published config to match `config/laravel-translation.php`.

### From 0.2.x to 0.3.0

- `translations:pull` now updates existing translation files in place rather than regenerating them. Comments, blank lines, indentation, and quote styles are preserved.
- Pull will **not** create files or directories. New keys in the sheet are surfaced as warnings; add them to the appropriate file in code first, then re-pull to populate their values. To bootstrap a new locale, create starter files in `lang/{locale}/` before pulling.
- `nikic/php-parser ^5.0` is now a runtime dependency.

### From 0.1.x to 0.2.x

- All env vars renamed: `GOOGLE_SHEETS_*` → `TRANSLATION_*`.
- `GOOGLE_SHEETS_SHEET_NAME` removed; each locale now uses a sheet tab named `Translations - {locale}` (auto-created).
- Default credentials filename: `laravel-translation-credentials.json` (was `laravel-translations-account.json`). Either rename your file or set `TRANSLATION_CREDENTIALS_PATH`.
- Backups are now local JSON files under `storage/app/translation-backups/`, not duplicate sheets in your spreadsheet.
- For non-English sheets, Column B is now the **English source string** and Column C is the translation.

## Features

- 🔄 **Bi-directional sync** — Push Laravel translations to Google Sheets and pull updates back
- ✏️ **Surgical pull** — Updates land in place via AST manipulation; comments, blank lines, indentation, and quote styles are preserved
- 🌍 **One sheet, every locale** — `translations:push` writes every locale under `lang/` to a single `Translations` tab, one column per locale
- 🔤 **Editor-friendly sheets** — English is editable in the sheet, conflicts with code are listed rather than overwritten, and your own columns (`status`, `notes`, …) are kept
- 🔐 **Service Account authentication** — Simple, secure auth using Google service accounts
- 📝 **Nested translations** — Automatically handles nested translation arrays using dot notation
- 🗂️ **Local JSON backups** — Sheet snapshots saved to a gitignored folder before every push
- 🔍 **Dry-run mode** — `--dry-run` previews what push or pull would change without writing anything
- ✅ **Drift check** — `translations:check` fails CI when a locale's keys or `:placeholders` drift from the source

## Installation

Add the repo to your `composer.json`:

```json
"repositories": [
    {
        "type": "github",
        "url": "git@github.com:paper-leaf-tech/laravel-translation.git"
    }
]
```

Install via Composer:

```bash
composer require paper-leaf-tech/laravel-translation --dev
```

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=laravel-translation-config
```

## Setup

### 1. Download Service Account Credentials

1. A service account with credentials has been created under the tech@paper-leaf.com account.
2. Check 1Password for "Laravel Translations Service Account" and save the note's content to `storage/app/laravel-translation-credentials.json`.

> **Important:** Make sure this file is gitignored:
>
> ```
> # .gitignore
> storage/app/laravel-translation-credentials.json
> ```

### 2. Create and Share Your Google Sheet

1. Create a new Google Spreadsheet or use an existing one.
2. Share the sheet with `laravel-translation-manager@laravel-translations-sheets.iam.gserviceaccount.com`, granting edit access.

### 3. Get Your Spreadsheet ID

The spreadsheet ID is in the URL:

```
https://docs.google.com/spreadsheets/d/SPREADSHEET_ID_HERE/edit
```

## Configuration

Add the following to your `.env` file:

```env
TRANSLATION_SPREADSHEET_ID="your-spreadsheet-id-here"

# Optional: override the credentials path (default: storage_path('app/laravel-translation-credentials.json'))
# TRANSLATION_CREDENTIALS_PATH="storage/app/laravel-translation-credentials.json"

# Optional: backup behaviour. Set TRANSLATION_BACKUP_PATH to false (or null) to disable backups entirely.
# TRANSLATION_BACKUP_PATH="app/translation-backups"
# TRANSLATION_BACKUP_KEEP=5
# TRANSLATION_BACKUP_AUTO_PRUNE=true
```

You can customize the tab name, source locale, sheet formatting and backups by publishing `config/laravel-translation.php`. The matching env vars are `TRANSLATION_SHEET` (default `Translations`) and `TRANSLATION_FORMAT_SHEET` (default `true`); the source locale is the `source_locale` config key (default `en`).

## Sheet layout

Every locale lives in one tab, `Translations` (configurable via `TRANSLATION_SHEET`):

| key | group | default | en | fr | status | notes |
|---|---|---|---|---|---|---|
| `failed` | `auth` | These credentials do not match our records. | These credentials do not match our records. | Identifiants invalides. | reviewed | |

- **key / group** — the line's key and its file (`auth`, `resources/schools`). Pull writes to `lang/{locale}/{group}.php`.
- **default** — the English in code as of the last push. Push compares it with code and the `en` column to tell a developer's change from an editor's. It is protected (with a warning) and can be hidden.
- **en** — editable English. Edit the wording here and pull it into code.
- **one column per locale** — columns are found by header, and a header counts as a locale when `lang/` has a directory with that name. Push adds a column when a new locale appears.
- **anything else** (`status`, `notes`, …) — kept with its row on every push.

When code and the sheet both change a line's English, push keeps the sheet's text and lists the key, so an editor's work is never overwritten silently. A blank cell never erases anything on pull.

Push re-sorts and rewrites every row, so Google cell comments, cell notes and manual cell colours do not stay with their translation. Keep review state in the `status` and `notes` columns instead; those move with their row.

## Usage

### Push translations to Google Sheets

```bash
# Push every locale under lang/ to the Translations tab
php artisan translations:push

# See what would change without writing
php artisan translations:push --dry-run

# Rebuild the tab from code, ignoring what is in it (a backup is still taken)
php artisan translations:push --fresh

# Skip the backup for this run
php artisan translations:push --no-backup
```

Push reports new and removed keys, English changed in code (and which translations need review), conflicts, and empty cells it filled from code. Rows whose key is gone from code are dropped; their notes survive in the backup.

### Pull translations from Google Sheets

```bash
# Pull every locale column
php artisan translations:pull

# Pull one locale
php artisan translations:pull fr

# Preview without writing files
php artisan translations:pull --dry-run
```

Pull rejects a value whose `:placeholders` differ from the English line in code, lists it, and exits with a failure after writing the rest. Rows for JSON (`*`) and package (`package::file`) groups are ignored; edit those files directly.

#### Surgical updates

Pull modifies your translation files in place using PHP AST manipulation. This means:

- **Preserved**: comments (line, block, doc), blank lines, indentation style, original quote styles (single, double, heredoc, nowdoc).
- **Touched**: only the string values for keys that already exist in your file.

Pull will **not**:

- Create new files. If the sheet has `messages.greeting` and there's no `lang/{locale}/messages.php`, the row is skipped with a warning telling you to add the file in code first.
- Append new keys. If the sheet has `auth.captcha.invalid` and your `lang/{locale}/auth.php` doesn't have a `captcha.invalid` entry, the row is skipped with a warning. Add the key to the file in your editor (with whatever default value you want), then re-pull to populate it.
- Touch keys whose current value isn't a simple string literal (e.g., function calls, concatenation, integers). Those are skipped with a warning.
- Remove keys. Anything in your file that isn't on the sheet is left alone.
- Apply a value whose `:placeholders` differ from the source-locale line in code. A translation that drops or renames `:name` would silently lose that value on the page, so the row is skipped with a warning and pull exits with a failure code once the rest are written; fix it in the sheet and re-pull. Placeholders are compared case-insensitively, since Laravel treats `:name`, `:Name` and `:NAME` as the same replacement.

### Check translations for drift

```bash
# Check every locale against the source locale (en)
php artisan translations:check

# Check a single locale
php artisan translations:check fr

# Report missing keys without failing (placeholder mismatches still fail)
php artisan translations:check --allow-missing
```

For each locale, the check reports keys missing from it, keys it has that the source lacks, and lines whose `:placeholders` differ from the source. Extra keys are not reported for package lines, since packages sometimes ship stale keys the app cannot remove. It reads `lang/{locale}/**/*.php` (subdirectories included) and `lang/{locale}.json`. For any package with an override published under `lang/vendor/{package}/{locale}`, it also checks that package's lines, with the override merged over the package's own files the way Laravel loads them.

It exits non-zero on any problem, so it can gate CI:

```yaml
- php artisan translations:check
```

The package is usually a dev dependency, so run the check in a CI step that installs dev dependencies.

## Customizing the sheet

Push formats the tab after writing it (turn this off with `TRANSLATION_FORMAT_SHEET=false`) and then fires `PaperleafTech\LaravelTranslation\Events\TranslationsPushed`. Listen for it to add your own tabs, dropdowns or colours:

```php
use Illuminate\Support\Facades\Event;
use PaperleafTech\LaravelTranslation\Events\TranslationsPushed;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

Event::listen(function (TranslationsPushed $event) {
    app(GoogleSheetsService::class)->batchUpdate([
        ['setDataValidation' => [
            'range' => ['sheetId' => $event->sheetId, 'startRowIndex' => 1, 'startColumnIndex' => array_search('status', $event->headers), 'endColumnIndex' => array_search('status', $event->headers) + 1],
            'rule' => ['condition' => ['type' => 'ONE_OF_LIST', 'values' => [['userEnteredValue' => 'draft'], ['userEnteredValue' => 'approved']]], 'showCustomUi' => true],
        ]],
    ]);
});
```

The event carries `sheetName`, `sheetId`, `headers` and the written `rows`. The package's own conditional rules contain `N("laravel-translation")=0` in their formula and are replaced on every push; rules you add are left alone.

## Backups

Before each push, the tab's cells are saved as JSON:

```
storage/app/translation-backups/
├── .gitignore           ← written on first run ("*\n!.gitignore")
├── 2026-10-05_143012.json
└── 2026-10-05_152244.json
```

`TRANSLATION_BACKUP_KEEP` controls how many are kept (default 5). `TRANSLATION_BACKUP_AUTO_PRUNE` toggles pruning. Set `TRANSLATION_BACKUP_PATH=false` to disable backups; `--no-backup` skips one run.

## Workflow

1. **Push** — `php artisan translations:push` builds or updates the `Translations` tab from every locale under `lang/`.
2. **Review in the sheet** — editors adjust English in the `en` column; translators fill the locale columns.
3. **Pull** — `php artisan translations:pull` writes non-blank cells back into existing `lang/{locale}/*.php` keys.
4. **Check** — `php artisan translations:check` in CI catches keys or placeholders that drifted.
5. **Re-push** after adding keys in code. Sheet edits and translations are kept.

### Adding a new key (translator-driven)

If a translator notices a missing key and adds it to the sheet, pull will skip it with a warning until the developer adds it to code:

```
⚠ Skipped 1 new key(s) in lang/en/auth.php (add to code first, then re-pull):
    - auth.captcha.invalid
```

Workflow: developer adds `'captcha' => ['invalid' => '']` (or any default) to `lang/en/auth.php`, re-runs `translations:pull`, and the value from the sheet lands in place.

### Bootstrapping a new locale

Pull will not create the locale directory. To add a new locale:

1. `mkdir lang/fr` and add starter files (e.g., `lang/fr/auth.php` returning `[]` or a copy of the English file).
2. `php artisan translations:push` — adds an `fr` column to the `Translations` tab, filled from code.
3. Translator fills the `fr` column in the sheet.
4. `php artisan translations:pull fr` — surgically applies translations to your starter files.

## Troubleshooting

### Permission Denied

**Error:** `Permission denied accessing Google Sheet`
**Solution:** Share the spreadsheet with the service account email (`client_email` in your credentials JSON).

### Credentials File Not Found

**Error:** `Google Sheets credentials file not found`
**Solution:** Confirm the path in `TRANSLATION_CREDENTIALS_PATH` (or the default `storage/app/laravel-translation-credentials.json`) and that the file is readable.

### Invalid Credentials JSON

**Error:** `Google Sheets credentials file contains invalid JSON`
**Solution:** Re-download the service account JSON from Google Cloud Console / 1Password. The file must contain `"type": "service_account"`.

### Sheet Not Found

**Error:** `Google Sheet not found`
**Solution:** Verify `TRANSLATION_SPREADSHEET_ID` and that the service account has editor access.

## Security

- **Never commit your service account JSON file** to version control.
- Store credentials under `storage/app/` and ensure your `.gitignore` excludes them.

## Requirements

- PHP 8.2+
- Laravel 11.x+

## License

MIT — see [LICENSE.md](LICENSE.md).

## Credits

- [Brendan Angerman](https://github.com/bAngerman)
- Built with [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- Uses [Google API PHP Client](https://github.com/googleapis/google-api-php-client)
