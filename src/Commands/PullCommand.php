<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

class PullCommand extends Command
{
    protected $signature = 'translations:pull {lang=en} {--dry-run : Preview changes without writing files}';

    protected $description = 'Pull updated translations from Google Sheets into the codebase.';

    public function __construct(protected GoogleSheetsService $sheetsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $lang = $this->argument('lang');
        $langPath = lang_path($lang);

        try {
            $this->info("Pulling translations for language: {$lang}");

            // Read data from Google Sheets
            $this->info('Reading from Google Sheets...');
            $sheetData = $this->readSheetData();

            if (empty($sheetData)) {
                $this->warn('No data found in Google Sheet.');

                return self::SUCCESS;
            }

            $this->info('Found '.count($sheetData).' translation entries.');

            // Parse and organize translations
            $translations = $this->parseTranslations($sheetData);

            if ($this->option('dry-run')) {
                $this->info('DRY RUN - No files will be modified');
                $this->displayPreview($translations);

                return self::SUCCESS;
            }

            // Write translations to files
            $this->writeTranslations($langPath, $translations);

            $this->info('✓ Translations pulled successfully!');
            $this->line('');

            $spreadsheetUrl = $this->sheetsService->getSpreadsheetUrl();
            $this->info("View your spreadsheet: {$spreadsheetUrl}");
            $this->line('');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Pull failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Read data from Google Sheets
     */
    protected function readSheetData(): array
    {
        $keyColumn = config('laravel-translation.key_column', 'A');
        $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
        $headerRow = config('laravel-translation.header_row', 1);

        // Read all data from key column to updated value column (A:C)
        $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
        $data = $this->sheetsService->getSheetData($range);

        // Remove header row if it exists
        if ($headerRow && ! empty($data)) {
            array_shift($data);
        }

        return $data;
    }

    /**
     * Parse sheet data into organized translation arrays
     */
    protected function parseTranslations(array $sheetData): array
    {
        $translations = [];

        foreach ($sheetData as $row) {
            if (empty($row[0])) {
                continue; // Skip empty keys
            }

            $key = $row[0];
            $originalValue = $row[1] ?? '';
            $updatedValue = $row[2] ?? '';

            // Prioritize updated value (column C), fall back to original value (column B)
            $value = ! empty($updatedValue) ? $updatedValue : $originalValue;

            // Parse dot notation into nested arrays
            $this->setNestedValue($translations, $key, $value);
        }

        return $translations;
    }

    /**
     * Set a value in a nested array using dot notation
     */
    protected function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (! isset($current[$k]) || ! is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }

    /**
     * Write translations to PHP files
     */
    protected function writeTranslations(string $langPath, array $translations): void
    {
        // Ensure language directory exists
        if (! File::isDirectory($langPath)) {
            File::makeDirectory($langPath, 0755, true);
            $this->info("Created language directory: {$langPath}");
        }

        foreach ($translations as $filename => $content) {
            $filePath = "{$langPath}/{$filename}.php";

            // Create subdirectories if needed
            $directory = dirname($filePath);
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            // Generate PHP array content
            $phpContent = $this->generatePhpArray($content);

            File::put($filePath, $phpContent);
            $this->line("  ✓ Written: {$filename}.php");
        }
    }

    /**
     * Generate PHP array file content
     */
    protected function generatePhpArray(array $data, int $indent = 0): string
    {
        if ($indent === 0) {
            $output = "<?php\n\nreturn [\n";
        } else {
            $output = "[\n";
        }

        foreach ($data as $key => $value) {
            $spaces = str_repeat('    ', $indent + 1);

            if (is_array($value)) {
                $output .= "{$spaces}'{$key}' => ";
                $output .= $this->generatePhpArray($value, $indent + 1);
                $output .= ",\n";
            } else {
                $escapedValue = addslashes($value);
                $output .= "{$spaces}'{$key}' => '{$escapedValue}',\n";
            }
        }

        $spaces = str_repeat('    ', $indent);
        $output .= "{$spaces}]";

        if ($indent === 0) {
            $output .= ";\n";
        }

        return $output;
    }

    /**
     * Display a preview of what would be pulled
     */
    protected function displayPreview(array $translations): void
    {
        $this->line('');
        $this->line('Preview of files that would be created/updated:');
        $this->line('');

        foreach ($translations as $filename => $content) {
            $this->line("  📄 {$filename}.php");
            $count = $this->countTranslations($content);
            $this->line("     {$count} translation(s)");
        }

        $this->line('');
        $this->info('Run without --dry-run to apply these changes.');
    }

    /**
     * Count total number of translations in a nested array
     */
    protected function countTranslations(array $data): int
    {
        $count = 0;

        foreach ($data as $value) {
            if (is_array($value)) {
                $count += $this->countTranslations($value);
            } else {
                $count++;
            }
        }

        return $count;
    }
}