# Laravel Translation Manager

Manage Laravel translation strings using Google Sheets. This package allows you to sync your Laravel translation files with a Google Spreadsheet, making it easy for non-technical team members to manage translations.

## Features

- 🔄 **Bi-directional sync** - Push Laravel translations to Google Sheets and pull updates back
- 🔐 **Service Account authentication** - Simple, secure authentication using Google service accounts
- 📝 **Nested translations** - Automatically handles nested translation arrays using dot notation
- 🌍 **Multi-language support** - Manage translations for any language
- 🔍 **Dry-run mode** - Preview changes before applying them
- 📋 **Three-column workflow** - Preserves original values while allowing content editors to manage updates
- ✨ **Easy setup** - Simple configuration and installation process

## Installation

Install the package via Composer:

First, you'll need to make composer able to see this project. Add the following to your composer.json before trying to require it:
```json
"repositories": [
    {
        "type": "github",
        "url": "git@github.com:paper-leaf-tech/laravel-translation.git"
    }
],
```

Install via Composer:
```bash
composer require paper-leaf-tech/laravel-translation --dev
```

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag=laravel-translation-config
```

## Setup

### 1. Download Service Account Credentials

1. A service account with credentials has already been created under the tech@paper-leaf.com account.
2. Check 1pass for "Laravel Translations Service Account" and save the note's content to `storage/app/laravel-translations-account.json`.

> **Important**: By default this file should be git ignored, but you can explicitly ignore this by editting your project's `.gitignore`.

```
# .gitignore
storage/app/laravel-translations-account.json
```

### 2. Create and Share Your Google Sheet

1. Create a new Google Spreadsheet or use an existing one
2. Share the sheet with "laravel-translation-manager@laravel-translations-sheets.iam.gserviceaccount.com", granting edit access.

### 3. Get Your Spreadsheet ID

The spreadsheet ID is in the URL:
```
https://docs.google.com/spreadsheets/d/SPREADSHEET_ID_HERE/edit
```

Copy the `SPREADSHEET_ID_HERE` portion.

## Configuration

Add the following to your `.env` file:

```env
# Your Google Spreadsheet ID (from the URL)
GOOGLE_SHEETS_SPREADSHEET_ID="your-spreadsheet-id-here"

# Optional: Specify a sheet name (defaults to first sheet)
GOOGLE_SHEETS_SHEET_NAME="Translations"
```

### Advanced Configuration

You can customize additional settings in `config/laravel-translation.php`:

## Usage

### Push Translations to Google Sheets

Push your Laravel translation files to Google Sheets:

```bash
# Push English translations (automatically creates a backup)
php artisan translations:push en

# Push without creating a backup
php artisan translations:push en --no-backup

# Push other languages
php artisan translations:push es

# Clear existing sheet data before push
php artisan translations:push en --clear
```

This command will:
- Read all translation files from `lang/en/` (or your specified language)
- Flatten nested arrays using dot notation (e.g., `auth.failed` → `auth.failed`)
- Write the data to your Google Sheet with three columns:
  - **Column A (Key)**: Translation key in dot notation
  - **Column B (Original Value)**: The original translation value (preserved for reference)
  - **Column C (Updated Value)**: Initially same as original, this is where content editors make changes

### Pull Translations from Google Sheets

Pull updated translations from Google Sheets back to Laravel:

```bash
# Pull English translations
php artisan translations:pull en

# Preview changes without writing files
php artisan translations:pull en --dry-run
```

This command will:
- Read data from your Google Sheet (columns A, B, and C)
- Prioritize values from **Column C (Updated Value)**, falling back to **Column B (Original Value)** if Column C is empty
- Parse dot notation back into nested arrays
- Create/update translation files in `lang/en/`

### Translation File Structure

The package handles nested translations automatically. For example:

**Google Sheet:**
```
Key                    | Original Value                              | Updated Value
-----------------------|---------------------------------------------|---------------------------------------------
auth.failed           | These credentials do not match our records. | These credentials do not match our records.
auth.throttle         | Too many login attempts.                    | Too many login attempts. Please wait.
validation.required   | The :attribute field is required.           | The :attribute field is required.
```

> **Note**: Content editors work in the "Updated Value" column (C). The "Original Value" column (B) is preserved for reference.

**Generated Laravel file** (`lang/en/auth.php`):
```php
<?php

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please wait.',  // Uses updated value from column C
];
```

**Generated Laravel file** (`lang/en/validation.php`):
```php
<?php

return [
    'required' => 'The :attribute field is required.',
];
```

## Workflow Example

### Initial Setup
1. Push your existing Laravel translations to Google Sheets:
   ```bash
   php artisan translations:push en
   ```
   This creates three columns:
   - **Column A**: Translation keys (e.g., `auth.failed`)
   - **Column B**: Original values (preserved for reference)
   - **Column C**: Updated values (initially same as Column B)

2. Share the Google Sheet with your team members

### Making Updates
1. Content editors modify translations in **Column C (Updated Value)**
   - Column B remains unchanged for reference
   - If Column C is empty, the pull will use Column B's value

2. Pull the updates:
   ```bash
   # Preview changes first
   php artisan translations:pull en --dry-run
   
   # Apply changes
   php artisan translations:pull en
   ```

3. Commit the updated translation files to your repository

### Best Practices
- **Never edit Column B (Original Value)** - This preserves the baseline translations
- **Make all edits in Column C (Updated Value)** - This is where content editors work
- **Leave Column C empty** to use the original value from Column B
- **Re-push periodically** to sync new translation keys added in code

## Troubleshooting

### Permission Denied Error

**Error:** `Permission denied accessing Google Sheet`

**Solution:** Ensure you've shared the Google Sheet with your service account email address. You can find this email in your credentials JSON file under the `client_email` field.

### Credentials File Not Found

**Error:** `Google Sheets credentials file not found`

**Solution:** 
- Verify the path in your `.env` file is correct
- Ensure the JSON file exists at the specified location
- Check file permissions

### Invalid Credentials

**Error:** `Google Sheets credentials file contains invalid JSON`

**Solution:** 
- Re-download the service account JSON file from 1Password
- Ensure you selected "JSON" format when creating the key
- Verify the file is a valid service account credential (should have `"type": "service_account"`)

### Sheet Not Found

**Error:** `Google Sheet not found`

**Solution:**
- Verify the spreadsheet ID in your configuration
- Ensure the spreadsheet hasn't been deleted
- Check that the service account has editor access to the sheet

## Security Considerations

- **Never commit your service account JSON file** to version control
- Store credentials in `storage/app/` which is typically excluded from version control
- Use environment variables for configuration

## Requirements

- PHP 8.2 or higher
- Laravel 10.x or higher

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Credits

- [Brendan Angerman](https://github.com/bAngerman)
- Built with [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- Uses [Google API PHP Client](https://github.com/googleapis/google-api-php-client)

## Support

If you discover any issues or have questions, please [open an issue on GitHub](https://github.com/paper-leaf-tech/laravel-translation/issues).
