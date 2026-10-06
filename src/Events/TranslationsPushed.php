<?php

namespace PaperleafTech\LaravelTranslation\Events;

use PaperleafTech\LaravelTranslation\Sheet\SheetRow;

/**
 * Fired after translations:push writes the sheet (never on a dry run), so an
 * app can add its own tabs, validation or formatting. Listeners reach the
 * Google client through app(GoogleSheetsService::class).
 */
final class TranslationsPushed
{
    /**
     * @param  list<string>  $headers  the header row as written
     * @param  list<SheetRow>  $rows  the rows as written, in sheet order
     */
    public function __construct(
        public readonly string $sheetName,
        public readonly int $sheetId,
        public readonly array $headers,
        public readonly array $rows,
    ) {}
}
