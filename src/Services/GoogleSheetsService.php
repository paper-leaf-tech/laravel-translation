<?php

namespace PaperleafTech\LaravelTranslation\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\File;

class GoogleSheetsService
{
    protected ?Client $client = null;

    protected ?Sheets $service = null;

    protected ?string $spreadsheetId = null;

    protected ?Spreadsheet $spreadsheetCache = null;

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
                'Please set TRANSLATION_CREDENTIALS_PATH in your .env file.'
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
                'Please set TRANSLATION_SPREADSHEET_ID in your .env file.'
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
     * Wrap a sheet name + range into A1 notation.
     * Sheet name is single-quoted; embedded single quotes are doubled.
     */
    public static function qualifyRange(string $sheetName, string $range): string
    {
        $escaped = str_replace("'", "''", $sheetName);

        return "'{$escaped}'!{$range}";
    }

    /**
     * Read data from a specific range in a sheet tab.
     *
     * @param  string  $sheetName  Name of the sheet tab
     * @param  string  $range  A1 notation range without sheet prefix (e.g., 'A1:C100')
     * @return array The values from the sheet
     */
    public function getSheetData(string $sheetName, string $range): array
    {
        $this->ensureInitialized();

        try {
            $response = $this->service->spreadsheets_values->get(
                $this->spreadsheetId,
                self::qualifyRange($sheetName, $range)
            );

            return $response->getValues() ?? [];
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Write data to a specific range in a sheet tab.
     *
     * @param  string  $sheetName  Name of the sheet tab
     * @param  string  $range  A1 notation range without sheet prefix
     * @param  array  $values  2D array of values to write
     */
    public function updateSheetData(string $sheetName, string $range, array $values): bool
    {
        $this->ensureInitialized();

        try {
            $body = new ValueRange([
                'values' => $values,
            ]);

            $params = [
                'valueInputOption' => 'RAW',
            ];

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                self::qualifyRange($sheetName, $range),
                $body,
                $params
            );

            return true;
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Clear data from a specific range in a sheet tab.
     */
    public function clearSheetData(string $sheetName, string $range): bool
    {
        $this->ensureInitialized();

        try {
            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                self::qualifyRange($sheetName, $range),
                new \Google\Service\Sheets\ClearValuesRequest()
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
     * Get the URL to the spreadsheet. When $sheetName is provided, the URL
     * deep-links to that locale's tab via #gid=.
     */
    public function getSpreadsheetUrl(?string $sheetName = null): string
    {
        $this->ensureInitialized();

        $url = "https://docs.google.com/spreadsheets/d/{$this->spreadsheetId}/edit";

        if ($sheetName === null) {
            return $url;
        }

        $gid = $this->getSheetId($sheetName);

        return $gid === null ? $url : $url."#gid={$gid}";
    }

    /**
     * Resolve the sheet ID (gid) for a sheet tab by name. Returns null if missing.
     */
    public function getSheetId(string $sheetName): ?int
    {
        $this->ensureInitialized();

        foreach ($this->spreadsheet()->getSheets() as $sheet) {
            $properties = $sheet->getProperties();
            if ($properties->getTitle() === $sheetName) {
                return $properties->getSheetId();
            }
        }

        return null;
    }

    /**
     * Create a sheet tab if it doesn't already exist. Returns true if it was created,
     * false if it already existed.
     */
    public function createSheetIfMissing(string $sheetName): bool
    {
        $this->ensureInitialized();

        if ($this->getSheetId($sheetName) !== null) {
            return false;
        }

        try {
            $request = new \Google\Service\Sheets\Request([
                'addSheet' => [
                    'properties' => [
                        'title' => $sheetName,
                    ],
                ],
            ]);

            $batch = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => [$request],
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batch);

            $this->refreshSpreadsheetCache();

            return true;
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }

    /**
     * Invalidate the cached spreadsheet metadata so the next lookup re-fetches.
     */
    public function refreshSpreadsheetCache(): void
    {
        $this->spreadsheetCache = null;
    }

    protected function spreadsheet(): Spreadsheet
    {
        if ($this->spreadsheetCache === null) {
            $this->spreadsheetCache = $this->service->spreadsheets->get($this->spreadsheetId);
        }

        return $this->spreadsheetCache;
    }
}
