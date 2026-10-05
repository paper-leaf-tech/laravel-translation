<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Support\Placeholders;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;

/**
 * Compares each locale with the source locale and fails on drift: a key one
 * has and the other lacks, or a line whose :placeholders differ. A missing
 * key falls back to the source language on the page, and a dropped
 * placeholder silently loses a name or number, so both are easy to ship.
 *
 * Reads PHP files (subdirectories included), JSON lines, and package lines
 * for packages with an override published for the locale.
 */
class CheckCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:check {lang? : Locale to check (omit to check every locale found under lang/)}
        {--allow-missing : Report missing and extra keys without failing; placeholder mismatches still fail}';

    protected $description = 'Check that each locale has the same keys and :placeholders as the source locale.';

    public function __construct(protected TranslationCatalogue $catalogue)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = TranslationConventions::SOURCE_LOCALE;
        $lang = $this->argument('lang');

        $locales = $lang
            ? [$lang]
            : array_values(array_filter($this->localesWithLines(), fn (string $locale): bool => ! TranslationConventions::isSourceLocale($locale)));

        if (empty($locales)) {
            $this->warn('No locales other than '.$source.' found under '.lang_path().'. Nothing to check.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($locales as $locale) {
            if (! $this->checkLocale($locale)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every locale with lines of its own: a lang/{locale} directory, a
     * lang/{locale}.json file, or a package override under lang/vendor.
     *
     * @return list<string>
     */
    protected function localesWithLines(): array
    {
        $locales = $this->discoverLocales();

        foreach (File::glob(lang_path('*.json')) as $json) {
            $locales[] = basename($json, '.json');
        }

        foreach (File::glob(lang_path('vendor/*/*'), GLOB_ONLYDIR) as $directory) {
            $locales[] = basename($directory);
        }

        $locales = array_values(array_unique($locales));
        sort($locales);

        return $locales;
    }

    protected function checkLocale(string $locale): bool
    {
        $sourceLocale = TranslationConventions::SOURCE_LOCALE;
        $namespaces = $this->catalogue->vendorNamespaces($locale);

        $source = $this->catalogue->lines($sourceLocale, $namespaces);
        $target = $this->catalogue->lines($locale, $namespaces);

        $missing = [];
        $placeholders = [];

        foreach (array_keys($source + $target) as $group) {
            $sourceLines = $source[$group] ?? [];
            $targetLines = $target[$group] ?? [];

            foreach (array_keys(array_diff_key($sourceLines, $targetLines)) as $key) {
                $missing[] = [$group, $key, "Missing from {$locale}"];
            }

            // A package's extra lines are never looked up, and the app cannot remove them.
            if (! str_contains($group, '::')) {
                foreach (array_keys(array_diff_key($targetLines, $sourceLines)) as $key) {
                    $missing[] = [$group, $key, "Not in {$sourceLocale}"];
                }
            }

            foreach (array_intersect_key($sourceLines, $targetLines) as $key => $line) {
                if (! Placeholders::match($line, $targetLines[$key])) {
                    $placeholders[] = [$group, $key, sprintf(
                        'Placeholders differ: %s has [%s], %s has [%s]',
                        $sourceLocale,
                        implode(', ', Placeholders::in($line)),
                        $locale,
                        implode(', ', Placeholders::in($targetLines[$key])),
                    )];
                }
            }
        }

        $problems = [...$missing, ...$placeholders];

        if (empty($problems)) {
            $this->info(sprintf(
                '%s matches %s: %d keys in %d groups.',
                $locale,
                $sourceLocale,
                array_sum(array_map('count', $source)),
                count($source),
            ));

            return true;
        }

        $this->line('');
        $this->line("=== {$locale} ===");
        $this->table(['Group', 'Key', 'Problem'], $problems);

        $failed = ! empty($placeholders) || ! $this->option('allow-missing');
        $summary = count($problems).' translation problem(s) found.';

        $failed ? $this->error($summary) : $this->warn($summary.' Missing keys are allowed.');

        return ! $failed;
    }
}
