<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

class PushCommand extends Command
{
    protected $signature = 'translations:push {lang=en} 
        {--clear : Clear existing sheet data before push}
        {--force-initial : Treat as initial push, leaving Updated Value empty}
        {--no-backup : Skip creating a backup of the sheet before pushing}';

    protected $description = 'Push codebase translations to a connected Google Sheet.';

    public function __construct(protected GoogleSheetsService $sheetsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $lang = $this->argument('lang');
        $langPath = lang_path($lang);

        if (! File::isDirectory($langPath)) {
            $this->error("Translation directory not found: {$langPath}");

            return self::FAILURE;
        }

        try {
            $this->info("Pushing translations for language: {$lang}");

            // Collect all translations from Laravel files
            $translations = $this->collectTranslations($langPath);

            if (empty($translations)) {
                $this->warn('No translations found to push.');

                return self::SUCCESS;
            }

            $this->info('Found '.count($translations).' translation keys.');

            // Check if sheet is empty
            $isSheetEmpty = $this->isSheetEmpty();

            // Create backup by default (unless --no-backup or sheet is empty)
            if (! $this->option('no-backup') && ! $isSheetEmpty) {
                $this->info('Creating backup sheet...');

                try {
                    $backupName = $this->sheetsService->createBackup();
                    $this->info("✓ Backup created: {$backupName}");

                    // Prune old backups if auto-prune is enabled
                    if (config('laravel-translation.backup.auto_prune', true)) {
                        $keepCount = config('laravel-translation.backup.keep', 5);
                        $deleted = $this->sheetsService->pruneBackups($keepCount);
                        if ($deleted > 0) {
                            $this->info("  Pruned {$deleted} old backup(s) (keeping {$keepCount} most recent)");
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed to create backup: {$e->getMessage()}");
                    $this->warn("Continuing with push...");
                }
            }

            // Clear existing data if requested
            if ($this->option('clear')) {
                $this->info('Clearing existing sheet data...');
                $keyColumn = config('laravel-translation.key_column', 'A');
                $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
                $this->sheetsService->clearSheetData("{$keyColumn}:{$updatedValueColumn}");
            }

            // Determine push mode: initial or diff
            $forceInitial = $this->option('force-initial') || $this->option('clear');
            $isInitialPush = $forceInitial || $this->isSheetEmpty();

            if ($isInitialPush) {
                $this->info('Performing initial push (Updated Value column will be empty)...');
                $sheetData = $this->prepareInitialSheetData($translations);
            } else {
                $this->info('Reading existing sheet data...');
                $existingData = $this->readExistingSheetData();
                $this->info('Comparing with current translations...');
                $sheetData = $this->prepareSheetDataWithDiff($translations, $existingData);
            }

            // Write to Google Sheets
            $this->info('Writing to Google Sheets...');
            $keyColumn = config('laravel-translation.key_column', 'A');
            $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
            $headerRow = config('laravel-translation.header_row', 1);

            $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
            $this->sheetsService->updateSheetData($range, $sheetData);

            $this->info('✓ Translations pushed successfully!');
            $this->line('');

            $spreadsheetUrl = $this->sheetsService->getSpreadsheetUrl();
            $this->info("View your spreadsheet: {$spreadsheetUrl}");
            $this->line('');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Push failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Collect all translations from PHP files in the language directory
     */
    protected function collectTranslations(string $langPath, string $prefix = ''): array
    {
        $translations = [];

        $files = File::files($langPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilenameWithoutExtension();
            $fileTranslations = require $file->getPathname();

            if (! is_array($fileTranslations)) {
                continue;
            }

            foreach ($fileTranslations as $key => $value) {
                $fullKey = $prefix ? "{$prefix}.{$filename}.{$key}" : "{$filename}.{$key}";

                if (is_array($value)) {
                    // Flatten nested arrays
                    $translations = array_merge(
                        $translations,
                        $this->flattenTranslations($value, $fullKey)
                    );
                } else {
                    $translations[$fullKey] = $value;
                }
            }
        }

        // Handle subdirectories
        $directories = File::directories($langPath);
        foreach ($directories as $directory) {
            $dirName = basename($directory);
            $nestedPrefix = $prefix ? "{$prefix}.{$dirName}" : $dirName;
            $translations = array_merge(
                $translations,
                $this->collectTranslations($directory, $nestedPrefix)
            );
        }

        return $translations;
    }

    /**
     * Flatten nested translation arrays
     */
    protected function flattenTranslations(array $translations, string $prefix): array
    {
        $flattened = [];

        foreach ($translations as $key => $value) {
            $fullKey = "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened = array_merge(
                    $flattened,
                    $this->flattenTranslations($value, $fullKey)
                );
            } else {
                $flattened[$fullKey] = $value;
            }
        }

        return $flattened;
    }

    /**
     * Check if the sheet is empty or only has headers
     */
    protected function isSheetEmpty(): bool
    {
        try {
            $keyColumn = config('laravel-translation.key_column', 'A');
            $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
            $headerRow = config('laravel-translation.header_row', 1);

            $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
            $data = $this->sheetsService->getSheetData($range);

            // Empty if no data or only header row
            return empty($data) || count($data) <= 1;
        } catch (\Exception $e) {
            // If we can't read the sheet, treat as empty
            return true;
        }
    }

    /**
     * Read existing sheet data into an associative array
     */
    protected function readExistingSheetData(): array
    {
        $keyColumn = config('laravel-translation.key_column', 'A');
        $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
        $headerRow = config('laravel-translation.header_row', 1);

        $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
        $data = $this->sheetsService->getSheetData($range);

        // Remove header row if it exists
        if ($headerRow && ! empty($data)) {
            array_shift($data);
        }

        // Convert to associative array: key => [original, updated]
        $existingData = [];
        foreach ($data as $row) {
            if (empty($row[0])) {
                continue; // Skip empty keys
            }

            $key = $row[0];
            $original = $row[1] ?? '';
            $updated = $row[2] ?? '';

            $existingData[$key] = [
                'original' => $original,
                'updated' => $updated,
            ];
        }

        return $existingData;
    }

    /**
     * Prepare data for initial push (Updated Value column empty)
     */
    protected function prepareInitialSheetData(array $translations): array
    {
        $data = [];

        // Add header row if configured
        if (config('laravel-translation.header_row')) {
            $data[] = ['Key', 'Original Value', 'Updated Value'];
        }

        // Add translation rows with empty Updated Value
        foreach ($translations as $key => $value) {
            $data[] = [$key, $value, ''];
        }

        return $data;
    }

    /**
     * Prepare data with diff logic for subsequent pushes
     */
    protected function prepareSheetDataWithDiff(array $translations, array $existingData): array
    {
        $data = [];
        $stats = [
            'new' => 0,
            'removed' => 0,
            'changed' => 0,
            'unchanged' => 0,
        ];

        // Add header row if configured
        if (config('laravel-translation.header_row')) {
            $data[] = ['Key', 'Original Value', 'Updated Value'];
        }

        // Process each translation from code
        foreach ($translations as $key => $codeValue) {
            if (isset($existingData[$key])) {
                // Key exists in sheet
                $sheetValue = $existingData[$key]['original'];
                $sheetUpdated = $existingData[$key]['updated'];

                if ($codeValue === $sheetValue) {
                    // Original unchanged in code - preserve existing Updated Value
                    $data[] = [$key, $sheetValue, $sheetUpdated];
                    $stats['unchanged']++;
                } else if ($codeValue === $sheetUpdated) {
                    // Code value is the same as sheet updated (an pull occured)
                    // Mark as unchanged
                    $data[] = [$key, $sheetValue, $sheetUpdated];
                    $stats['unchanged']++;
                } else {
                    // Original changed in code - keep the sheet's Original Value (baseline)
                    // but update the Updated Value to reflect the new code state
                    $data[] = [$key, $sheetValue, $codeValue];
                    $stats['changed']++;
                }
            } else {
                // New key - leave Updated Value empty
                $data[] = [$key, $codeValue, ''];
                $stats['new']++;
            }
        }

        // Count removed keys (in sheet but not in code)
        $codeKeys = array_keys($translations);
        $sheetKeys = array_keys($existingData);
        $removedKeys = array_diff($sheetKeys, $codeKeys);
        $stats['removed'] = count($removedKeys);

        // Display stats
        if ($stats['new'] > 0) {
            $this->line("  - {$stats['new']} new key(s) added");
        }
        if ($stats['removed'] > 0) {
            $this->line("  - {$stats['removed']} key(s) removed");
        }
        if ($stats['changed'] > 0) {
            $this->line("  - {$stats['changed']} key(s) updated. These keys updated because the codebase had a different updated value than the spreadsheet.");
        }
        if ($stats['unchanged'] > 0) {
            $this->line("  - {$stats['unchanged']} key(s) unchanged");
        }

        return $data;
    }
}