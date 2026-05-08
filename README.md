# Laravel Translation Manager

Manage Laravel translation strings using Google Sheets. This package syncs your Laravel translation files with a Google Spreadsheet, making it easy for non-technical team members to manage translations.

## Upgrading from 0.1.x

0.2.0 contains breaking changes. See [`CHANGELOG.md`](CHANGELOG.md) for full details. Highlights:

- All env vars renamed: `GOOGLE_SHEETS_*` → `TRANSLATION_*`.
- `GOOGLE_SHEETS_SHEET_NAME` removed; each locale now uses a sheet tab named `Translations - {locale}` (auto-created).
- Default credentials filename: `laravel-translation-credentials.json` (was `laravel-translations-account.json`). Either rename your file or set `TRANSLATION_CREDENTIALS_PATH`.
- Backups are now local JSON files under `storage/app/translation-backups/`, not duplicate sheets in your spreadsheet.
- For non-English sheets, Column B is now the **English source string** and Column C is the translation.

## Features

- 🔄 **Bi-directional sync** — Push Laravel translations to Google Sheets and pull updates back
- 🌍 **Multi-language by default** — Run `translations:push` with no argument to sync every locale under `lang/`
- 🔤 **Translator-friendly sheets** — Non-English sheets show the English source alongside each translation
- 🔐 **Service Account authentication** — Simple, secure auth using Google service accounts
- 📝 **Nested translations** — Automatically handles nested translation arrays using dot notation
- 🗂️ **Local JSON backups** — Sheet snapshots saved to a gitignored folder before every push
- 🔍 **Dry-run mode** — Preview pull changes before applying them

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

You can customize column letters, header row, and other settings by publishing `config/laravel-translation.php`.

## Sheet layout

Each locale gets its own sheet tab named `Translations - {locale}`. Tabs are created automatically on first push.

**Source locale (`en`):**

| Column A (Key) | Column B (Original Value) | Column C (Updated Value) |
|---|---|---|
| `auth.failed` | `These credentials do not match our records.` | (editor's revised wording) |

**Other locales (e.g. `fr`):**

| Column A (Key) | Column B (English Source) | Column C (Translation) |
|---|---|---|
| `auth.failed` | `These credentials do not match our records.` | `Identifiants invalides.` |

On pull for a non-source locale, rows with an empty Column C are skipped — they aren't translated yet, so they fall through to Laravel's `fallback_locale` at runtime instead of being written as English into the target file.

## Usage

### Push translations to Google Sheets

```bash
# Push every locale under lang/
php artisan translations:push

# Push a single locale
php artisan translations:push en

# Skip backups for this run
php artisan translations:push --no-backup

# Clear and re-initialize the sheet
php artisan translations:push en --clear
```

When iterating multiple locales, the source locale (`en`) is always pushed first so its strings are available to populate Column B in the other sheets.

### Pull translations from Google Sheets

```bash
# Pull every locale that has a directory under lang/
php artisan translations:pull

# Pull a single locale
php artisan translations:pull fr

# Preview without writing files
php artisan translations:pull --dry-run
```

Pull discovery scans `lang/` directories. Locales without a matching `Translations - {locale}` sheet tab are skipped with a warning — only locales whose tab exists in the spreadsheet get pulled.

## Backups

Before each push, the existing rows on the locale's sheet are snapshotted as a JSON file:

```
storage/app/translation-backups/
├── .gitignore           ← package writes this on first run (contents: "*\n!.gitignore")
├── en/
│   ├── 2026-05-08_143012.json
│   └── 2026-05-08_152244.json
└── fr/
    └── 2026-05-08_143015.json
```

`TRANSLATION_BACKUP_KEEP` controls retention per locale (default 5). `TRANSLATION_BACKUP_AUTO_PRUNE` toggles auto-pruning of older snapshots. Set `TRANSLATION_BACKUP_PATH=false` (or `null`) in `.env` to disable backups entirely; the `--no-backup` flag still works as a one-off override.

## Workflow

1. **Initial push** — `php artisan translations:push` from a project that already has `lang/en/` (and optionally `lang/fr/`, etc.). Each locale gets its own tab.
2. **Translators work in the sheet** — non-source tabs show English in Column B and let translators fill Column C.
3. **Pull** — `php artisan translations:pull` writes Column C back to the matching `lang/{locale}/*.php` files. Untranslated rows are left alone.
4. **Re-push** when you add new keys to your code. Column C is preserved for keys whose English hasn't changed; if English changes, the row is flagged "review needed" in the command output but the existing translation is kept.

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
