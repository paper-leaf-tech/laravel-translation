<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Services\TranslationFileWriter;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;

class PullCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:pull {lang? : Locale to pull (omit to pull all locales found under lang/)}
        {--dry-run : Preview changes without writing files}';

    protected $description = 'Pull updated translations from Google Sheets and apply them in-place to existing language files.';

    public function __construct(
        protected GoogleSheetsService $sheetsService,
        protected TranslationFileWriter $writer,
    ) {
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

        $langPath = lang_path($locale);
        if (! File::isDirectory($langPath)) {
            $this->warn("Language directory {$langPath} not found. Bootstrap the locale by adding starter files first, then re-pull.");

            return true;
        }

        $sheetData = $this->readSheetData($sheetName);

        if (empty($sheetData)) {
            $this->warn('No data found in sheet tab.');

            return true;
        }

        $this->info('Found '.count($sheetData).' translation entries.');

        $updates = $this->parseUpdates($sheetData, $locale);

        if (empty($updates)) {
            $this->warn('No translatable rows after filtering. Nothing to write.');

            return true;
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN - No files will be modified');
            $this->displayPreview($updates);

            return true;
        }

        $this->applyUpdates($langPath, $updates);

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
     * Convert sheet rows to a flat key=>value map of updates.
     * Source locale: prefer Column C, fall back to Column B.
     * Non-source: only Column C; rows with empty C are skipped.
     *
     * @return array<string,string> Full dotted keys (e.g. 'auth.failed')
     */
    protected function parseUpdates(array $sheetData, string $locale): array
    {
        $isSource = TranslationConventions::isSourceLocale($locale);
        $updates = [];

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

            $updates[$key] = $value;
        }

        return $updates;
    }

    /**
     * Group full keys by their first dotted segment (the file name) and
     * delegate per-file updates to the file writer. Reports stats afterward.
     *
     * @param  array<string,string>  $updates
     */
    protected function applyUpdates(string $langPath, array $updates): void
    {
        $byFile = [];
        foreach ($updates as $fullKey => $value) {
            if (! str_contains($fullKey, '.')) {
                $this->warn("Skipped '{$fullKey}': key has no file segment (must be of the form '<file>.<key>').");
                continue;
            }
            [$file, $relative] = explode('.', $fullKey, 2);
            $byFile[$file][$relative] = $value;
        }

        foreach ($byFile as $file => $fileUpdates) {
            $filePath = "{$langPath}/{$file}.php";
            $stats = $this->writer->updateFile($filePath, $fileUpdates);

            $relPath = $this->relativePath($filePath);

            if (! empty($stats['updated'])) {
                $this->info('  ✓ Updated '.count($stats['updated'])." key(s) in {$relPath}");
            }

            $isMissing = ! File::exists($filePath);
            if ($isMissing && ! empty($stats['skipped_missing'])) {
                $this->warn("  ⚠ {$relPath} not found; ".count($stats['skipped_missing']).' key(s) skipped (add the file with starter keys, then re-pull).');
            } elseif (! empty($stats['skipped_missing'])) {
                $count = count($stats['skipped_missing']);
                $this->warn("  ⚠ Skipped {$count} new key(s) in {$relPath} (add to code first, then re-pull):");
                foreach ($stats['skipped_missing'] as $relative) {
                    $this->line("      - {$file}.{$relative}");
                }
            }

            if (! empty($stats['skipped_non_string'])) {
                $count = count($stats['skipped_non_string']);
                $this->warn("  ⚠ Skipped {$count} key(s) in {$relPath} with non-string values:");
                foreach ($stats['skipped_non_string'] as $relative) {
                    $this->line("      - {$file}.{$relative}");
                }
            }
        }
    }

    protected function relativePath(string $absolutePath): string
    {
        $base = base_path();
        if (str_starts_with($absolutePath, $base.DIRECTORY_SEPARATOR)) {
            return substr($absolutePath, strlen($base) + 1);
        }

        return $absolutePath;
    }

    /**
     * @param  array<string,string>  $updates
     */
    protected function displayPreview(array $updates): void
    {
        $byFile = [];
        foreach ($updates as $fullKey => $_) {
            if (! str_contains($fullKey, '.')) {
                continue;
            }
            [$file] = explode('.', $fullKey, 2);
            $byFile[$file] = ($byFile[$file] ?? 0) + 1;
        }

        $this->line('');
        $this->line('Preview of files that would be checked for updates:');
        $this->line('');

        foreach ($byFile as $file => $count) {
            $this->line("  📄 {$file}.php — {$count} key(s) candidate for update");
        }

        $this->line('');
        $this->info('Run without --dry-run to apply these changes.');
    }
}
