<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

class ExportCommand extends Command
{
    protected $signature = 'translations:export {lang=en} 
        {--clear : Clear existing sheet data before export}
        {--force-initial : Treat as initial export, leaving Updated Value empty}
        {--no-backup : Skip creating a backup of the sheet before exporting}';

    protected $description = 'Export Laravel translations to a connected Google Sheet.';

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
            $this->info("Exporting translations for language: {$lang}");

            // Collect all translations from Laravel files
            $translations = $this->collectTranslations($langPath);

            if (empty($translations)) {
                $this->warn('No translations found to export.');

                return self::SUCCESS;
            }

            $this->info('Found '.count($translations).' translation keys.');

            // Create backup by default (unless --no-backup)
            if (! $this->option('no-backup')) {
                $this->info('Creating backup sheet...');
                $backupName = $this->sheetsService->createBackup();
                $this->info("Backup created: {$backupName}");

                // Prune old backups
                $deleted = $this->sheetsService->pruneBackups(5);
                if ($deleted > 0) {
                    $this->info("Pruned {$deleted} old backup(s).");
                }
            }

            // Clear existing data if requested
            if ($this->option('clear')) {
                $this->info('Clearing existing sheet data...');
                $keyColumn = config('laravel-translation.key_column', 'A');
                $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
                $this->sheetsService->clearSheetData("{$keyColumn}:{$updatedValueColumn}");
            }

            // Determine export mode: initial or diff
            $forceInitial = $this->option('force-initial') || $this->option('clear');
            $isInitialExport = $forceInitial || $this->isSheetEmpty();

            if ($isInitialExport) {
                $this->info('Performing initial export (Updated Value column will be empty)...');
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

            $this->info('✓ Translations exported successfully!');
            $this->line('');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Export failed: '.$e->getMessage());

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
     * Prepare data for initial export (Updated Value column empty)
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
     * Prepare data with diff logic for subsequent exports
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
        foreach ($translations as $key => $newOriginal) {
            if (isset($existingData[$key])) {
                // Key exists in sheet
                $existingOriginal = $existingData[$key]['original'];
                $existingUpdated = $existingData[$key]['updated'];

                if ($newOriginal === $existingOriginal) {
                    // Original unchanged in code - preserve existing Updated Value
                    $data[] = [$key, $existingOriginal, $existingUpdated];
                    $stats['unchanged']++;
                } else {
                    // Original changed in code - keep the sheet's Original Value (baseline)
                    // but update the Updated Value to reflect the new code state
                    $data[] = [$key, $existingOriginal, $newOriginal];
                    $stats['changed']++;
                }
            } else {
                // New key - leave Updated Value empty
                $data[] = [$key, $newOriginal, ''];
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
            $this->line("  - {$stats['changed']} original value(s) changed (Updated Value auto-updated)");
        }
        if ($stats['unchanged'] > 0) {
            $this->line("  - {$stats['unchanged']} key(s) unchanged (Updated Values preserved)");
        }

        return $data;
    }
}