<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'translations:export {lang=en}';
    protected $description = 'Export Laravel translations to a connected Google Sheet.';

    public function handle(): int
    {
        $lang = $this->argument('lang');

        return self::SUCCESS;
    }
}