<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Service Account Credentials
    |--------------------------------------------------------------------------
    |
    | Path to the Google service account JSON file with Sheets API access.
    |
    */
    'credentials_path' => env('TRANSLATION_CREDENTIALS_PATH', storage_path('app/laravel-translation-credentials.json')),

    /*
    |--------------------------------------------------------------------------
    | Google Spreadsheet ID
    |--------------------------------------------------------------------------
    |
    | Found in the spreadsheet URL:
    | https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit
    |
    */
    'spreadsheet_id' => env('TRANSLATION_SPREADSHEET_ID'),

    /*
    |--------------------------------------------------------------------------
    | Google API Scopes
    |--------------------------------------------------------------------------
    |
    | A literal URL rather than Google\Service\Sheets::SPREADSHEETS, so a
    | published config doesn't fatal when the package is a dev dependency
    | and production installs with --no-dev.
    |
    */
    'scopes' => [
        'https://www.googleapis.com/auth/spreadsheets',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sheet Tab
    |--------------------------------------------------------------------------
    |
    | The tab push writes and pull reads. It holds every locale: key, group,
    | default, then one column per locale. Other columns you add (status,
    | notes, ...) are kept with their row on every push.
    |
    */
    'sheet' => env('TRANSLATION_SHEET', 'Translations'),

    /*
    |--------------------------------------------------------------------------
    | Source Locale
    |--------------------------------------------------------------------------
    |
    | The locale every other locale is translated from. Its lang directory
    | must exist for push, and translations:check compares the others to it.
    |
    */
    'source_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Format the Sheet
    |--------------------------------------------------------------------------
    |
    | Apply the package's formatting after each push: frozen header, filter,
    | column widths, shaded read-only columns, red empty translations and a
    | warning on edits to key, group and default.
    |
    */
    'format' => env('TRANSLATION_FORMAT_SHEET', true),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | A JSON snapshot of the tab is written before each push. Set
    | TRANSLATION_BACKUP_PATH to false or null to disable backups.
    |
    */
    'backup' => [
        // Relative paths resolve via storage_path(). Absolute paths are used verbatim.
        'path' => env('TRANSLATION_BACKUP_PATH', 'app/translation-backups'),

        // Number of backups to keep.
        'keep' => env('TRANSLATION_BACKUP_KEEP', 5),

        // Delete older backups after writing a new one.
        'auto_prune' => env('TRANSLATION_BACKUP_AUTO_PRUNE', true),
    ],
];
