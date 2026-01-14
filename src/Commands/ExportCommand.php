<?php

namespace PaperleafTech\LaravelTranslationCsv\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Helper\ProgressBar;

class ExportCommand extends Command
{
    protected $signature = 'translations:export {lang=en} {--path=}';
    protected $description = 'Export Laravel translations to a CSV file.';

    public function handle(): int
    {
        $lang = $this->argument('lang');
        $langPath = lang_path($lang);

        if (! is_dir($langPath)) {
            $this->error("Language folder does not exist: {$langPath}");
            return self::FAILURE;
        }

        $now = now()->unix();
        $outputPath = $this->option('path')
            ?? storage_path("app/translations-{$lang}-{$now}.csv");

        $files = File::allFiles($langPath);

        if (empty($files)) {
            $this->warn("No translation files found for language [{$lang}].");
            return self::SUCCESS;
        }

        $handle = fopen($outputPath, 'w');
        fputcsv($handle, ['File', 'Key', 'Original', 'New']);

        $progress = new ProgressBar($this->output, count($files));
        $progress->start();

        foreach ($files as $file) {
            $filename = $file->getFilenameWithoutExtension();

            /** @var array $translations */
            $translations = require $file->getPathname();

            if (! is_array($translations)) {
                continue;
            }

            $flattened = $this->flattenTranslations($translations);

            foreach ($flattened as $key => $value) {
                fputcsv($handle, [
                    $filename,
                    $key,
                    $value,
                    '', // New value (intentionally blank)
                ]);
            }

            $progress->advance();
        }

        $progress->finish();
        fclose($handle);

        $this->newLine(2);
        $this->info("Translations exported to:");
        $this->line($outputPath);

        return self::SUCCESS;
    }

    protected function flattenTranslations(array $translations, string $prefix = ''): array
    {
        $result = [];

        foreach ($translations as $key => $value) {
            $fullKey = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $result += $this->flattenTranslations($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}