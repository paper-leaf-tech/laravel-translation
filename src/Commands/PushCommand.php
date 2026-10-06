<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Events\TranslationsPushed;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Sheet\ReconcileReport;
use PaperleafTech\LaravelTranslation\Sheet\SheetContents;
use PaperleafTech\LaravelTranslation\Sheet\SheetFormatter;
use PaperleafTech\LaravelTranslation\Sheet\SheetReconciler;
use PaperleafTech\LaravelTranslation\Sheet\TranslationSheet;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use RuntimeException;

class PushCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:push
        {--dry-run : Show what would change without writing to the sheet}
        {--no-backup : Skip the local backup of the sheet}
        {--fresh : Rebuild the sheet from code, ignoring its current contents}';

    protected $description = 'Push every locale\'s translations to the translation sheet.';

    public function handle(
        TranslationCatalogue $catalogue,
        TranslationSheet $sheet,
        SheetReconciler $reconciler,
        TranslationBackupManager $backups,
        SheetFormatter $formatter,
        GoogleSheetsService $sheets,
    ): int {
        $source = TranslationConventions::sourceLocale();
        $locales = $this->discoverLocales();

        if (! in_array($source, $locales, true)) {
            $this->error('No '.lang_path($source).' directory. Push needs the source locale.');

            return self::FAILURE;
        }

        try {
            $existing = $sheet->read($source, $locales);

            $code = [];
            foreach ($locales as $locale) {
                $code[$locale] = $catalogue->appGroups($locale);
            }

            if ($existing !== null && $existing->rows !== [] && ! $this->option('no-backup') && ! $this->option('dry-run')) {
                $this->backup($backups, $existing);
            }

            $result = $reconciler->reconcile($source, $code, $this->option('fresh') ? null : $existing);
            $this->report($result->report, count($result->rows), $source);

            if ($this->option('dry-run')) {
                $this->info('Dry run: the sheet was not changed.');

                return self::SUCCESS;
            }

            $sheet->write($result->headers, $result->rows);

            if (config('laravel-translation.format', true) && ($tab = $sheets->getSheet($sheet->name())) !== null) {
                $localeHeaders = array_values(array_filter($result->headers, fn (string $header): bool => in_array($header, $locales, true)));
                $sheets->batchUpdate($formatter->requests($tab, $result->headers, $localeHeaders));
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Pushed '.count($result->rows).' row(s) to "'.$sheet->name().'".');
        $this->info('View sheet: '.$sheet->url());

        // Outside the try, so a listener's exception surfaces as itself rather
        // than being reported as a failed push after the sheet was written.
        event(new TranslationsPushed($sheet->name(), (int) $sheets->getSheetId($sheet->name()), $result->headers, $result->rows));

        return self::SUCCESS;
    }

    protected function backup(TranslationBackupManager $backups, SheetContents $existing): void
    {
        if (! $backups->isEnabled()) {
            $this->info('Backups disabled via TRANSLATION_BACKUP_PATH.');

            return;
        }

        try {
            $this->info('✓ Backup saved: '.$backups->backup($existing->grid));

            if (config('laravel-translation.backup.auto_prune', true)) {
                $keep = (int) config('laravel-translation.backup.keep', 5);
                $deleted = $backups->prune($keep);

                if ($deleted > 0) {
                    $this->info("  Pruned {$deleted} old backup(s) (keeping {$keep} most recent)");
                }
            }
        } catch (\Exception $e) {
            $this->warn("Failed to create backup: {$e->getMessage()}");
            $this->warn('Continuing with push...');
        }
    }

    protected function report(ReconcileReport $report, int $rows, string $source): void
    {
        $this->info("{$rows} key(s) in code.");

        if ($report->new !== []) {
            $this->line('  - '.count($report->new).' new key(s) added');
        }

        if ($report->sourceChanged !== []) {
            $this->line('  - '.count($report->sourceChanged)." key(s) had their {$source} text changed in code");
        }

        if ($report->filledFromCode > 0) {
            $this->line("  - {$report->filledFromCode} empty cell(s) filled from code");
        }

        $this->listed('key(s) were removed from code; their rows were dropped (notes survive in the backup)', $report->removed);
        $this->listed("key(s) changed in both code and the sheet; kept the sheet's {$source} text", $report->conflicts);
        $this->listed("key(s) need their translations reviewed: the {$source} text changed", $report->needsReview);
        $this->listed("key(s) exist in other locales but not in {$source}", $report->sourceMissing);
        $this->listed('duplicate row(s) in the sheet were dropped; the first of each was kept', $report->duplicates);
    }

    /**
     * @param  list<string>  $labels
     */
    protected function listed(string $message, array $labels): void
    {
        if ($labels === []) {
            return;
        }

        $this->warn('  ⚠ '.count($labels).' '.$message.':');

        foreach ($labels as $label) {
            $this->line("      - {$label}");
        }
    }
}
