<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Services\TranslationFileWriter;
use PaperleafTech\LaravelTranslation\Sheet\SheetContents;
use PaperleafTech\LaravelTranslation\Sheet\TranslationSheet;
use PaperleafTech\LaravelTranslation\Support\Placeholders;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use RuntimeException;

class PullCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:pull {locale? : Pull only this locale}
        {--dry-run : Show what would change without writing files}';

    protected $description = 'Pull translations from the translation sheet into existing lang files.';

    public function handle(TranslationCatalogue $catalogue, TranslationSheet $sheet, TranslationFileWriter $writer): int
    {
        $source = TranslationConventions::sourceLocale();

        try {
            $contents = $sheet->read($source, $this->discoverLocales());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($contents === null) {
            $this->error('The spreadsheet has no "'.$sheet->name().'" tab. Run translations:push first.');

            return self::FAILURE;
        }

        $locales = [$source, ...$contents->locales];
        $requested = $this->argument('locale');

        if ($requested !== null) {
            if (! in_array($requested, $locales, true)) {
                $this->error("The sheet has no \"{$requested}\" column with a matching ".lang_path($requested).' directory.');

                return self::FAILURE;
            }

            $locales = [$requested];
        }

        if ($contents->duplicates !== []) {
            $this->warn('⚠ '.count($contents->duplicates).' duplicate row(s) in the sheet; used the first of each:');
            foreach ($contents->duplicates as $label) {
                $this->line("    - {$label}");
            }
        }

        [$updates, $rejected] = $this->collect($contents, $locales, $catalogue->appGroups($source));

        if ($rejected !== []) {
            $this->warn('⚠ '.count($rejected)." value(s) rejected: their :placeholders differ from the {$source} line in code. Fix them in the sheet, then pull again.");
            $this->table(['Locale', 'Key', 'Expected', 'Found'], $rejected);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: no files were changed.');

            foreach ($updates as $locale => $groups) {
                foreach ($groups as $group => $values) {
                    $this->line('  '.$this->relativePath(lang_path("{$locale}/{$group}.php")).' — '.count($values).' value(s) to apply');
                }
            }

            return $rejected === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($updates as $locale => $groups) {
            foreach ($groups as $group => $values) {
                $this->apply($writer, $locale, (string) $group, $values);
            }
        }

        $this->info('✓ Pulled from "'.$sheet->name().'".');

        return $rejected === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Non-blank cells to write, by locale and group, and the ones whose
     * :placeholders differ from the source line in code, which is what the
     * app passes replacements to.
     *
     * @param  list<string>  $locales
     * @param  array<string, array<string, string>>  $sourceLines  group => key => line, from code
     * @return array{0: array<string, array<string, array<string, string>>>, 1: list<array{0: string, 1: string, 2: string, 3: string}>}
     */
    protected function collect(SheetContents $contents, array $locales, array $sourceLines): array
    {
        $updates = [];
        $rejected = [];

        foreach ($contents->rows as $row) {
            if ($row->group === '' || $row->key === '' || $row->group === TranslationCatalogue::JSON_GROUP || str_contains($row->group, '::')) {
                continue;
            }

            $reference = $sourceLines[$row->group][$row->key] ?? null;

            foreach ($locales as $locale) {
                $value = $row->value($locale);

                if ($value === '') {
                    continue;
                }

                if ($reference !== null && ! Placeholders::match($reference, $value)) {
                    $rejected[] = [$locale, $row->label(), $this->placeholderList($reference), $this->placeholderList($value)];

                    continue;
                }

                $updates[$locale][$row->group][$row->key] = $value;
            }
        }

        return [$updates, $rejected];
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function apply(TranslationFileWriter $writer, string $locale, string $group, array $values): void
    {
        $filePath = lang_path("{$locale}/{$group}.php");
        $relPath = $this->relativePath($filePath);
        $stats = $writer->updateFile($filePath, $values);

        if ($stats['updated'] !== []) {
            $this->info('  ✓ Updated '.count($stats['updated'])." key(s) in {$relPath}");
        }

        if ($stats['skipped_missing'] !== [] && ! File::exists($filePath)) {
            $this->warn("  ⚠ {$relPath} not found; ".count($stats['skipped_missing']).' key(s) skipped (add the file with starter keys, then pull again).');
        } elseif ($stats['skipped_missing'] !== []) {
            $this->warn('  ⚠ Skipped '.count($stats['skipped_missing'])." key(s) not in {$relPath} (add them in code first, then pull again):");
            foreach ($stats['skipped_missing'] as $key) {
                $this->line("      - {$group}.{$key}");
            }
        }

        if ($stats['skipped_non_string'] !== []) {
            $this->warn('  ⚠ Skipped '.count($stats['skipped_non_string'])." key(s) in {$relPath} whose value in code is not a plain string:");
            foreach ($stats['skipped_non_string'] as $key) {
                $this->line("      - {$group}.{$key}");
            }
        }
    }

    protected function placeholderList(string $line): string
    {
        return '['.implode(', ', Placeholders::in($line)).']';
    }

    protected function relativePath(string $absolutePath): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $base) ? substr($absolutePath, strlen($base)) : $absolutePath;
    }
}
