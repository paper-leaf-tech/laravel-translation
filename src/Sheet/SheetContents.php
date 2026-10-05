<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

/**
 * The translation tab as read: which headers are locales and which belong to
 * someone else, its rows, and the raw cells for backups.
 */
final readonly class SheetContents
{
    /**
     * @param  list<string>  $locales  non-source locale headers, in sheet order
     * @param  list<string>  $extras  unrecognised headers, in sheet order
     * @param  list<SheetRow>  $rows  the first row for each (group, key)
     * @param  list<string>  $duplicates  labels of later rows that repeated a (group, key)
     * @param  list<list<string>>  $grid  every cell as read, header row included
     */
    public function __construct(
        public array $locales = [],
        public array $extras = [],
        public array $rows = [],
        public array $duplicates = [],
        public array $grid = [],
    ) {}
}
