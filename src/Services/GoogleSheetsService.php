<?php

namespace PaperleafTech\LaravelTranslation\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\File;

class GoogleSheetsService
{
    protected ?Client $client = null;

    protected ?Sheets $service = null;

    protected ?string $spreadsheetId = null;

    protected ?string $sheetName = null;

    protected bool $initialized = false;

    /**
     * Ensure the service is initialized before use
     */
    protected function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->validateConfiguration();
        $this->initializeClient();
        $this->spreadsheetId = config('laravel-translation.spreadsheet_id');
        $this->sheetName = config('laravel-translation.sheet_name');
        $this->initialized = true;
    }

    /**
     * Validate that required configuration is present
     */
    protected function validateConfiguration(): void
    {
        $credentialsPath = config('laravel-translation.credentials_path');

        if (empty($credentialsPath)) {
            throw new \RuntimeException(
                'Google Sheets credentials path is not configured. '.
                'Please set GOOGLE_SHEETS_CREDENTIALS_PATH in your .env file.'
            );
        }

        if (! File::exists($credentialsPath)) {
            throw new \RuntimeException(
                "Google Sheets credentials file not found at: {$credentialsPath}\n".
                'Please ensure you have downloaded your service account JSON file and placed it in the correct location.'
            );
        }

        if (empty(config('laravel-translation.spreadsheet_id'))) {
            throw new \RuntimeException(
                'Google Sheets spreadsheet ID is not configured. '.
                'Please set GOOGLE_SHEETS_SPREADSHEET_ID in your .env file.'
            );
        }

        // Validate JSON format
        $credentials = File::get($credentialsPath);
        $decoded = json_decode($credentials, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Google Sheets credentials file contains invalid JSON: '.json_last_error_msg()
            );
        }

        if (! isset($decoded['type']) || $decoded['type'] !== 'service_account') {
            throw new \RuntimeException(
                'Google Sheets credentials file is not a valid service account JSON file. '.
                'Please ensure you downloaded the correct credentials from Google Cloud Console.'
            );
        }
    }

    /**
     * Initialize the Google Client with service account credentials
     */
    protected function initializeClient(): void
    {
        $this->client = new Client();
        $this->client->setApplicationName('Laravel Translation Manager');
        $this->client->setScopes(config('laravel-translation.scopes'));
        $this->client->setAuthConfig(config('laravel-translation.credentials_path'));

        $this->service = new Sheets($this->client);
    }

    /**
     * Get the authenticated Google Client instance
     */
    public function getClient(): Client
    {
        $this->ensureInitialized();

        return $this->client;
    }

    /**
     * Get the Google Sheets service instance
     */
    public function getService(): Sheets
    {
        $this->ensureInitialized();

        return $this->service;
    }

    /**
     * Read data from a specific range in the Google Sheet
     *
     * @param  string  $range  A1 notation range (e.g., 'A1:B100' or 'Sheet1!A1:B100')
     * @return array The values from the sheet
     */
    public function getSheetData(string $range): array
    {
        $this->ensureInitialized();

        try {
            // If sheet name is configured and range doesn't include sheet name, prepend it
            if ($this->sheetName && ! str_contains($range, '!')) {
                $range = "{$this->sheetName}!{$range}";
            }

            $response = $this->service->spreadsheets_values->get(
                $this->spreadsheetId,
                $range
            );

            return $response->getValues() ?? [];
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Write data to a specific range in the Google Sheet
     *
     * @param  string  $range  A1 notation range (e.g., 'A1:B100' or 'Sheet1!A1:B100')
     * @param  array  $values  2D array of values to write
     * @return bool Success status
     */
    public function updateSheetData(string $range, array $values): bool
    {
        $this->ensureInitialized();

        try {
            // If sheet name is configured and range doesn't include sheet name, prepend it
            if ($this->sheetName && ! str_contains($range, '!')) {
                $range = "{$this->sheetName}!{$range}";
            }

            $body = new ValueRange([
                'values' => $values,
            ]);

            $params = [
                'valueInputOption' => 'RAW',
            ];

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );

            return true;
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Clear data from a specific range in the Google Sheet
     *
     * @param  string  $range  A1 notation range
     * @return bool Success status
     */
    public function clearSheetData(string $range): bool
    {
        $this->ensureInitialized();

        try {
            // If sheet name is configured and range doesn't include sheet name, prepend it
            if ($this->sheetName && ! str_contains($range, '!')) {
                $range = "{$this->sheetName}!{$range}";
            }

            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                $range,
                new \Google\Service\Sheets\ClearValuesRequest()
            );

            return true;
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Append data to the end of a sheet
     *
     * @param  string  $range  A1 notation range (typically just the columns, e.g., 'A:B')
     * @param  array  $values  2D array of values to append
     * @return bool Success status
     */
    public function appendSheetData(string $range, array $values): bool
    {
        $this->ensureInitialized();

        try {
            // If sheet name is configured and range doesn't include sheet name, prepend it
            if ($this->sheetName && ! str_contains($range, '!')) {
                $range = "{$this->sheetName}!{$range}";
            }

            $body = new ValueRange([
                'values' => $values,
            ]);

            $params = [
                'valueInputOption' => 'RAW',
            ];

            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );

            return true;
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Handle Google API exceptions with helpful error messages
     */
    protected function handleGoogleException(\Google\Service\Exception $e): void
    {
        $errors = $e->getErrors();
        $message = $e->getMessage();

        // Check for common error scenarios
        if ($e->getCode() === 403) {
            throw new \RuntimeException(
                "Permission denied accessing Google Sheet.\n".
                "Please ensure you have shared the spreadsheet with the service account email address.\n".
                "You can find the service account email in your credentials JSON file.\n".
                "Original error: {$message}"
            );
        }

        if ($e->getCode() === 404) {
            throw new \RuntimeException(
                "Google Sheet not found.\n".
                "Please verify the spreadsheet ID in your configuration is correct.\n".
                "Current ID: {$this->spreadsheetId}\n".
                "Original error: {$message}"
            );
        }

        if ($e->getCode() === 400 && str_contains($message, 'Unable to parse range')) {
            throw new \RuntimeException(
                "Invalid range format.\n".
                "Please use A1 notation (e.g., 'A1:B100').\n".
                "Original error: {$message}"
            );
        }

        // Generic error
        throw new \RuntimeException(
            "Google Sheets API error: {$message}\n".
            'Error details: '.json_encode($errors, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Get the service account email from credentials
     */
    public function getServiceAccountEmail(): ?string
    {
        $credentialsPath = config('laravel-translation.credentials_path');

        if (! File::exists($credentialsPath)) {
            return null;
        }

        $credentials = json_decode(File::get($credentialsPath), true);

        return $credentials['client_email'] ?? null;
    }

    /**
     * Get the full URL to the spreadsheet
     */
    public function getSpreadsheetUrl(): string
    {
        $this->ensureInitialized();

        return "https://docs.google.com/spreadsheets/d/{$this->spreadsheetId}/edit";
    }

    /**
     * Get the sheet ID (integer) for a given sheet name
     */
    public function getSheetId(?string $sheetName = null): ?int
    {
        $this->ensureInitialized();

        $sheetName = $sheetName ?? $this->sheetName;

        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);

        foreach ($spreadsheet->getSheets() as $sheet) {
            $properties = $sheet->getProperties();
            if ($sheetName === null) {
                // If no sheet name configured, return the first sheet's ID
                return $properties->getSheetId();
            }

            if ($properties->getTitle() === $sheetName) {
                return $properties->getSheetId();
            }
        }

        return null;
    }

    /**
     * Create a backup of the current sheet
     */
    public function createBackup(): string
    {
        $this->ensureInitialized();

        $sheetId = $this->getSheetId();

        if ($sheetId === null) {
            throw new \RuntimeException(
                "Could not find sheet to backup. ".
                "Please verify your sheet configuration."
            );
        }

        $timestamp = date('Y-m-d H:i:s');
        $backupName = "Backup {$timestamp}";

        try {
            $request = new \Google\Service\Sheets\Request([
                'duplicateSheet' => [
                    'sourceSheetId' => $sheetId,
                    'insertSheetIndex' => 1000, // Insert at the end
                    'newSheetName' => $backupName,
                ],
            ]);

            $batchRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => [$request],
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchRequest);

            return $backupName;
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "Failed to create backup sheet: ".$e->getMessage()
            );
        }
    }

    /**
     * Prune old backups, keeping only the specified number of recent ones
     */
    public function pruneBackups(int $keep = 5): int
    {
        $this->ensureInitialized();

        if ($keep < 0) {
            throw new \InvalidArgumentException('Keep count must be a positive integer');
        }

        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $sheets = $spreadsheet->getSheets();

            $backups = [];
            foreach ($sheets as $sheet) {
                $title = $sheet->getProperties()->getTitle();
                if (str_starts_with($title, 'Backup ')) {
                    $backups[] = [
                        'id' => $sheet->getProperties()->getSheetId(),
                        'title' => $title,
                        // Parse timestamp for sorting (Backup YYYY-MM-DD HH:mm:ss)
                        'time' => strtotime(substr($title, 7)),
                    ];
                }
            }

            // Sort by time descending (newest first)
            usort($backups, fn ($a, $b) => $b['time'] <=> $a['time']);

            // Slice to find ones to delete
            $toDelete = array_slice($backups, $keep);

            if (empty($toDelete)) {
                return 0;
            }

            // Create delete requests
            $requests = [];
            foreach ($toDelete as $backup) {
                $requests[] = [
                    'deleteSheet' => [
                        'sheetId' => $backup['id'],
                    ],
                ];
            }

            // Execute batch update
            $batchRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests,
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchRequest);

            return count($toDelete);
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "Failed to prune backup sheets: ".$e->getMessage()
            );
        }
    }
}
