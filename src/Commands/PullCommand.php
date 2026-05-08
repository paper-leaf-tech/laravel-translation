<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;

class PullCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:pull {lang? : Locale to pull (omit to pull all locales found under lang/)}
        {--dry-run : Preview changes without writing files}';

    protected $description = 'Pull updated translations from Google Sheets into the codebase.';

    public function __construct(protected GoogleSheetsService $sheetsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $lang = $this->argument('lang');
        $locales = $lang ? [$lang] : $this->discoverLocales();

        if (empty($locales)) {
            $this->warn('No locales found under '.lang_path().'. Nothing to pull.');

            return self::SUCCESS;
        }

        $failures = [];

        foreach ($locales as $locale) {
            try {
                $ok = $this->pullLocale($locale);
                if (! $ok) {
                    $failures[] = $locale;
                }
            } catch (\Exception $e) {
                $this->error("[{$locale}] Pull failed: ".$e->getMessage());
                $failures[] = $locale;
            }
        }

        if (! empty($failures)) {
            $this->error('Failed locales: '.implode(', ', $failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function pullLocale(string $locale): bool
    {
        $this->info('');
        $this->info("=== {$locale} ===");

        $sheetName = TranslationConventions::sheetNameFor($locale);

        if ($this->sheetsService->getSheetId($sheetName) === null) {
            $this->warn("Sheet tab '{$sheetName}' not found; skipping.");

            return true;
        }

        $sheetData = $this->readSheetData($sheetName);

        if (empty($sheetData)) {
            $this->warn('No data found in sheet tab.');

            return true;
        }

        $this->info('Found '.count($sheetData).' translation entries.');

        $translations = $this->parseTranslations($sheetData, $locale);

        if (empty($translations)) {
            $this->warn('No translatable rows after filtering. Nothing to write.');

            return true;
        }

        $langPath = lang_path($locale);

        if ($this->option('dry-run')) {
            $this->info('DRY RUN - No files will be modified');
            $this->displayPreview($translations);

            return true;
        }

        $this->writeTranslations($langPath, $translations);

        $this->info("✓ Pulled {$locale}.");
        $this->info('View tab: '.$this->sheetsService->getSpreadsheetUrl($sheetName));

        return true;
    }

    protected function readSheetData(string $sheetName): array
    {
        $keyColumn = config('laravel-translation.key_column', 'A');
        $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
        $headerRow = config('laravel-translation.header_row', 1);

        $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
        $data = $this->sheetsService->getSheetData($sheetName, $range);

        if ($headerRow && ! empty($data)) {
            array_shift($data);
        }

        return $data;
    }

    /**
     * Convert sheet rows to a nested translation array.
     * For source locale, prefer Column C, fall back to Column B.
     * For non-source locales, only Column C is used; rows with empty C are skipped.
     */
    protected function parseTranslations(array $sheetData, string $locale): array
    {
        $isSource = TranslationConventions::isSourceLocale($locale);
        $translations = [];

        foreach ($sheetData as $row) {
            if (empty($row[0])) {
                continue;
            }

            $key = $row[0];
            $originalValue = $row[1] ?? '';
            $updatedValue = $row[2] ?? '';

            if ($isSource) {
                $value = $updatedValue !== '' ? $updatedValue : $originalValue;
            } else {
                if ($updatedValue === '') {
                    continue;
                }
                $value = $updatedValue;
            }

            $this->setNestedValue($translations, $key, $value);
        }

        return $translations;
    }

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

    protected function writeTranslations(string $langPath, array $translations): void
    {
        if (! File::isDirectory($langPath)) {
            File::makeDirectory($langPath, 0755, true);
            $this->info("Created language directory: {$langPath}");
        }

        foreach ($translations as $filename => $content) {
            $filePath = "{$langPath}/{$filename}.php";

            $directory = dirname($filePath);
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            if (! is_array($content)) {
                // Top-level scalar key (no file grouping). Write to a __misc.php bucket.
                $content = [$filename => $content];
                $filePath = "{$langPath}/__misc.php";
            }

            $phpContent = $this->generatePhpArray($content);

            File::put($filePath, $phpContent);
            $this->line('  ✓ Written: '.basename($filePath));
        }
    }

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

    protected function displayPreview(array $translations): void
    {
        $this->line('');
        $this->line('Preview of files that would be created/updated:');
        $this->line('');

        foreach ($translations as $filename => $content) {
            $this->line("  📄 {$filename}.php");
            $count = is_array($content) ? $this->countTranslations($content) : 1;
            $this->line("     {$count} translation(s)");
        }

        $this->line('');
        $this->info('Run without --dry-run to apply these changes.');
    }

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
