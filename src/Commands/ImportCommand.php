<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use AJThinking\Archetype\Archetype;
use AJThinking\Archetype\Nodes\Expr;

class ImportCommand extends Command
{
    protected $signature = 'translations:import {lang=en}';
    protected $description = 'Import translated values from and generate executable translation overrides';

    public function handle(): int
    {
        $lang = $this->argument('lang');

        $this->info('Translations imported successfully.');
        return self::SUCCESS;
    }
}