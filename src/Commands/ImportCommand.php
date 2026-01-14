<?php

namespace PaperleafTech\LaravelTranslationCsv\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use AJThinking\Archetype\Archetype;
use AJThinking\Archetype\Nodes\Expr;

class ImportCommand extends Command
{
    protected $signature = 'translations:import {lang=en}';
    protected $description = 'Import translated values from CSV and generate executable translation overrides';

    public function handle(): int
    {
        $lang = $this->argument('lang');

        $langPath       = lang_path($lang);
        $csvPath        = storage_path("app/translations-{$lang}-in.csv");
        $generatedPath  = "{$langPath}/generated.php";
        $originalsPath  = "{$langPath}/originals";

        if (! File::exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");
            return self::FAILURE;
        }

        File::ensureDirectoryExists($originalsPath);

        [$headers, $rows] = $this->readCsv($csvPath);

        $generated = [];
        $overridesByFile = [];

        foreach ($rows as $row) {
            if (empty($row['New'])) {
                continue;
            }

            $file = $row['File'];
            $key  = $row['Key'];

            $fullKey = "{$file}.{$key}";

            data_set($generated, $fullKey, $row['New']);
            $overridesByFile[$file][$key] = $fullKey;
        }

        // Write generated.php (pure data file)
        $this->writeGeneratedFile($generatedPath, $generated);

        // Mutate translation files with executable PHP
        foreach ($overridesByFile as $file => $keys) {
            $path = "{$langPath}/{$file}.php";
            $backup = "{$originalsPath}/{$file}.php";

            if (! File::exists($path)) {
                $this->warn("Missing translation file: {$file}.php");
                continue;
            }

            // Backup original only once
            if (! File::exists($backup)) {
                File::copy($path, $backup);
            }

            $this->updateTranslationFile($path, $keys);
        }

        $this->info('Translations imported successfully.');
        return self::SUCCESS;
    }

    /* -----------------------------------------------------------------
     | CSV
     | ----------------------------------------------------------------- */
    protected function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        $headers = fgetcsv($handle);
        $rows = [];

        while ($row = fgetcsv($handle)) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return [$headers, $rows];
    }

    /* -----------------------------------------------------------------
     | Generated file (data-only)
     | ----------------------------------------------------------------- */
    protected function writeGeneratedFile(string $path, array $data): void
    {
        $ast = Archetype::createArrayReturn($data);
        File::put($path, $ast->print());
    }

    /* -----------------------------------------------------------------
     | Translation mutation (AST-based, executable PHP)
     | ----------------------------------------------------------------- */
    protected function updateTranslationFile(string $path, array $keys): void
    {
        $ast = Archetype::parse(File::get($path));
        $array = $ast->getReturn()->getArray();

        foreach ($keys as $key => $generatedKey) {
            $this->setNestedArrayValue(
                $array,
                $key,
                Expr::call('__', [
                    Expr::string("generated.{$generatedKey}")
                ])
            );
        }

        File::put($path, $ast->print());
    }

    protected function setNestedArrayValue($array, string $dotKey, Expr $expr): void
    {
        $segments = explode('.', $dotKey);
        $current = $array;

        foreach ($segments as $segment) {
            if (! $current->has($segment)) {
                $current->set($segment, []);
            }

            $node = $current->get($segment);

            if ($node->isArray()) {
                $current = $node;
            } else {
                $current->set($segment, []);
                $current = $current->get($segment);
            }
        }

        $current->replace($expr);
    }
}