<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Services\TranslationReconciler;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;

class PushCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:push {lang? : Locale to push (omit to push all locales found under lang/)}
        {--clear : Clear existing sheet data before push}
        {--force-initial : Treat as initial push, leaving Updated Value empty}
        {--no-backup : Skip creating a local backup of the sheet before pushing}';

    protected $description = 'Push codebase translations to a connected Google Sheet (one tab per locale).';

    /** @var array<string,string>|null Cached source-locale flat translations. */
    protected ?array $sourceTranslations = null;

    /** @var bool Whether the "backups disabled" notice has been shown this run. */
    protected bool $backupNoticeShown = false;

    public function __construct(
        protected GoogleSheetsService $sheetsService,
        protected TranslationBackupManager $backups,
        protected TranslationReconciler $reconciler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lang = $this->argument('lang');
        $locales = $lang ? [$lang] : $this->discoverLocales();

        if (empty($locales)) {
            $this->warn('No locales found under '.lang_path().'. Nothing to push.');

            return self::SUCCESS;
        }

        // Push the source locale first so its translations are cached for non-source pushes.
        usort($locales, fn ($a, $b) => (TranslationConventions::isSourceLocale($a) ? 0 : 1)
            <=> (TranslationConventions::isSourceLocale($b) ? 0 : 1));

        $failures = [];

        foreach ($locales as $locale) {
            try {
                $ok = $this->pushLocale($locale);
                if (! $ok) {
                    $failures[] = $locale;
                }
            } catch (\Exception $e) {
                $this->error("[{$locale}] Push failed: ".$e->getMessage());
                $failures[] = $locale;
            }
        }

        if (! empty($failures)) {
            $this->error('Failed locales: '.implode(', ', $failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function pushLocale(string $locale): bool
    {
        $this->info('');
        $this->info("=== {$locale} ===");

        $langPath = lang_path($locale);
        if (! File::isDirectory($langPath)) {
            $this->error("Translation directory not found: {$langPath}");

            return false;
        }

        $sheetName = TranslationConventions::sheetNameFor($locale);

        $targetTranslations = $this->collectTranslations($langPath);

        if (TranslationConventions::isSourceLocale($locale)) {
            $this->sourceTranslations = $targetTranslations;
        } elseif ($this->sourceTranslations === null) {
            $this->sourceTranslations = $this->loadSourceTranslations();
        }

        if (empty($targetTranslations) && empty($this->sourceTranslations)) {
            $this->warn('No translations found to push.');

            return true;
        }

        if ($this->sheetsService->createSheetIfMissing($sheetName)) {
            $this->info("Created new sheet tab: {$sheetName}");
        }

        $isSheetEmpty = $this->isSheetEmpty($sheetName);

        // Backup
        if (! $this->option('no-backup')) {
            if (! $this->backups->isEnabled()) {
                if (! $this->backupNoticeShown) {
                    $this->info('Backups disabled via TRANSLATION_BACKUP_PATH.');
                    $this->backupNoticeShown = true;
                }
            } elseif (! $isSheetEmpty) {
                $this->writeBackup($locale, $sheetName);
            }
        }

        // Clear if requested
        if ($this->option('clear')) {
            $this->info('Clearing existing sheet data...');
            $keyColumn = config('laravel-translation.key_column', 'A');
            $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
            $this->sheetsService->clearSheetData($sheetName, "{$keyColumn}:{$updatedValueColumn}");
        }

        $forceInitial = $this->option('force-initial') || $this->option('clear');
        $isInitial = $forceInitial || $isSheetEmpty;

        $existingSheetRows = $isInitial ? [] : $this->readExistingSheetData($sheetName);

        $this->info('Found '.count($targetTranslations).' translation key(s) in code.');

        if ($isInitial) {
            $this->info('Performing initial push...');
        } else {
            $this->info('Reconciling against existing sheet data...');
        }

        $result = $this->reconciler->reconcile(
            $locale,
            $this->sourceTranslations ?? [],
            $targetTranslations,
            $existingSheetRows,
        );

        $rows = $result['rows'];
        $stats = $result['stats'];

        $this->reportStats($locale, $stats);

        // Prepend header row if configured
        if (config('laravel-translation.header_row')) {
            array_unshift($rows, TranslationConventions::headersFor($locale));
        }

        $keyColumn = config('laravel-translation.key_column', 'A');
        $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
        $headerRow = config('laravel-translation.header_row', 1);

        $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";

        $this->info('Writing to Google Sheets...');
        $this->sheetsService->updateSheetData($sheetName, $range, $rows);

        $this->info("✓ Pushed {$locale}.");
        $this->info('View tab: '.$this->sheetsService->getSpreadsheetUrl($sheetName));

        return true;
    }

    protected function loadSourceTranslations(): array
    {
        $sourceLangPath = lang_path(TranslationConventions::SOURCE_LOCALE);
        if (! File::isDirectory($sourceLangPath)) {
            return [];
        }

        return $this->collectTranslations($sourceLangPath);
    }

    protected function writeBackup(string $locale, string $sheetName): void
    {
        try {
            $keyColumn = config('laravel-translation.key_column', 'A');
            $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
            $headerRow = config('laravel-translation.header_row', 1);

            $existingRows = $this->sheetsService->getSheetData(
                $sheetName,
                "{$keyColumn}{$headerRow}:{$updatedValueColumn}"
            );

            if (empty($existingRows)) {
                return;
            }

            $path = $this->backups->backup($locale, $existingRows);
            $this->info("✓ Backup saved: {$path}");

            if (config('laravel-translation.backup.auto_prune', true)) {
                $keep = (int) config('laravel-translation.backup.keep', 5);
                $deleted = $this->backups->prune($locale, $keep);
                if ($deleted > 0) {
                    $this->info("  Pruned {$deleted} old backup(s) (keeping {$keep} most recent)");
                }
            }
        } catch (\Exception $e) {
            $this->warn("Failed to create backup: {$e->getMessage()}");
            $this->warn('Continuing with push...');
        }
    }

    protected function reportStats(string $locale, array $stats): void
    {
        if ($stats['new'] > 0) {
            $this->line("  - {$stats['new']} new key(s) added");
        }
        if ($stats['removed'] > 0) {
            $this->line("  - {$stats['removed']} key(s) removed");
        }
        if ($stats['changed'] > 0) {
            $this->line("  - {$stats['changed']} key(s) updated");
        }
        if ($stats['unchanged'] > 0) {
            $this->line("  - {$stats['unchanged']} key(s) unchanged");
        }

        if (! TranslationConventions::isSourceLocale($locale)) {
            if ($stats['stale'] > 0) {
                $this->warn("  - {$stats['stale']} key(s) had their English source change; review translations");
            }
            if ($stats['untranslated'] > 0) {
                $this->line("  - {$stats['untranslated']} new key(s) need translation");
            }
            if ($stats['target_only'] > 0) {
                $this->line("  - {$stats['target_only']} key(s) exist in {$locale} but not in source locale");
            }
        }
    }

    /**
     * Collect translations from PHP files under a language directory.
     * Returns flat key=>value map using dot notation.
     *
     * @return array<string,string>
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
                    $translations = array_merge(
                        $translations,
                        $this->flattenTranslations($value, $fullKey)
                    );
                } else {
                    $translations[$fullKey] = $value;
                }
            }
        }

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
     * Flatten nested translation arrays into dot-notation keys.
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
     * Sheet is empty if it has no data rows beyond a header row.
     * Only catches the "tab not found" / range-not-parseable cases; other
     * exceptions propagate so we don't silently mask permissions errors.
     */
    protected function isSheetEmpty(string $sheetName): bool
    {
        $keyColumn = config('laravel-translation.key_column', 'A');
        $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
        $headerRow = config('laravel-translation.header_row', 1);

        $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
        $data = $this->sheetsService->getSheetData($sheetName, $range);

        return empty($data) || count($data) <= 1;
    }

    /**
     * Read sheet rows into a key=>[original,updated] map. Drops the header row.
     *
     * @return array<string,array{original:string,updated:string}>
     */
    protected function readExistingSheetData(string $sheetName): array
    {
        $keyColumn = config('laravel-translation.key_column', 'A');
        $updatedValueColumn = config('laravel-translation.updated_value_column', 'C');
        $headerRow = config('laravel-translation.header_row', 1);

        $range = "{$keyColumn}{$headerRow}:{$updatedValueColumn}";
        $data = $this->sheetsService->getSheetData($sheetName, $range);

        if ($headerRow && ! empty($data)) {
            array_shift($data);
        }

        $existing = [];
        foreach ($data as $row) {
            if (empty($row[0])) {
                continue;
            }
            $existing[$row[0]] = [
                'original' => $row[1] ?? '',
                'updated' => $row[2] ?? '',
            ];
        }

        return $existing;
    }
}
