<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Service Account Credentials
    |--------------------------------------------------------------------------
    |
    | Path to your Google Service Account JSON credentials file.
    | You can download this from Google Cloud Console after creating
    | a service account with Google Sheets API access.
    |
    | Default: storage_path('app/laravel-translation-credentials.json')
    |
    */
    'credentials_path' => env('TRANSLATION_CREDENTIALS_PATH', storage_path('app/laravel-translation-credentials.json')),

    /*
    |--------------------------------------------------------------------------
    | Google Spreadsheet ID
    |--------------------------------------------------------------------------
    |
    | The ID of your Google Spreadsheet. You can find this in the URL:
    | https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit
    |
    */
    'spreadsheet_id' => env('TRANSLATION_SPREADSHEET_ID'),

    /*
    |--------------------------------------------------------------------------
    | Google API Scopes
    |--------------------------------------------------------------------------
    |
    | The OAuth 2.0 scopes required for Google Sheets API access.
    | Default provides read/write access to Google Sheets.
    |
    */
    'scopes' => [
        Google\Service\Sheets::SPREADSHEETS,
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation Key Column
    |--------------------------------------------------------------------------
    |
    | The column letter or index for translation keys in your sheet.
    | Default: 'A' (first column)
    |
    */
    'key_column' => 'A',

    /*
    |--------------------------------------------------------------------------
    | Original Value Column
    |--------------------------------------------------------------------------
    |
    | The column letter or index for the original translation values.
    | For the source locale this preserves the original baseline.
    | For non-source locales this contains the source-locale (English) string.
    | Default: 'B' (second column)
    |
    */
    'original_value_column' => 'B',

    /*
    |--------------------------------------------------------------------------
    | Updated Value Column
    |--------------------------------------------------------------------------
    |
    | The column letter or index for updated translation values.
    | Content editors manage translations in this column.
    | When pulling, this column takes priority over the original value column.
    | Default: 'C' (third column)
    |
    */
    'updated_value_column' => 'C',

    /*
    |--------------------------------------------------------------------------
    | Header Row
    |--------------------------------------------------------------------------
    |
    | The row number that contains column headers (1-indexed).
    | Set to null if there are no headers.
    |
    */
    'header_row' => 1,

    /*
    |--------------------------------------------------------------------------
    | Backup Settings
    |--------------------------------------------------------------------------
    |
    | Local JSON snapshots of the spreadsheet rows are written before each push.
    | Set TRANSLATION_BACKUP_PATH to false or null in your .env to disable backups
    | entirely.
    |
    */
    'backup' => [
        // Storage path for local JSON backups.
        // Relative paths resolve via storage_path(). Absolute paths are used verbatim.
        // Set to false or null to disable backups entirely.
        'path' => env('TRANSLATION_BACKUP_PATH', 'app/translation-backups'),

        // Number of backups to keep per locale (older backups will be automatically deleted).
        'keep' => env('TRANSLATION_BACKUP_KEEP', 5),

        // Automatically prune old backups after creating a new one.
        'auto_prune' => env('TRANSLATION_BACKUP_AUTO_PRUNE', true),
    ],
];
