<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

final readonly class ReconcileResult
{
    /**
     * @param  list<string>  $headers
     * @param  list<SheetRow>  $rows  sorted by group, then key
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public ReconcileReport $report,
    ) {}
}
