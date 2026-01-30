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
    | Default: storage_path('app/laravel-translations-account.json')
    |
    */
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH', storage_path('app/laravel-translations-account.json')),

    /*
    |--------------------------------------------------------------------------
    | Google Spreadsheet ID
    |--------------------------------------------------------------------------
    |
    | The ID of your Google Spreadsheet. You can find this in the URL:
    | https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit
    |
    */
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),

    /*
    |--------------------------------------------------------------------------
    | Sheet Name
    |--------------------------------------------------------------------------
    |
    | The name of the sheet tab within your spreadsheet.
    | Leave null to use the first sheet.
    |
    */
    'sheet_name' => env('GOOGLE_SHEETS_SHEET_NAME', null),

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
    | This column preserves the original content for reference.
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
    | Configure backup behavior when pushing translations.
    |
    */
    'backup' => [
        // Number of backups to keep (older backups will be automatically deleted)
        'keep' => env('GOOGLE_SHEETS_BACKUP_KEEP', 5),

        // Automatically prune old backups after creating a new one
        'auto_prune' => env('GOOGLE_SHEETS_BACKUP_AUTO_PRUNE', true),
    ],
];
