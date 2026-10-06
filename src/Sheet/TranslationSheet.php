<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use RuntimeException;

/**
 * The single tab push and pull share. Columns are found by their header, so
 * reviewers can reorder them or add their own; only key, group, default and
 * the source locale are required.
 */
class TranslationSheet
{
    public const KEY = 'key';

    public const GROUP = 'group';

    public const DEFAULT = 'default';

    public function __construct(protected GoogleSheetsService $sheets) {}

    public function name(): string
    {
        return (string) config('laravel-translation.sheet', 'Translations');
    }

    public function exists(): bool
    {
        return $this->sheets->getSheetId($this->name()) !== null;
    }

    public function url(): string
    {
        return $this->sheets->getSpreadsheetUrl($this->name());
    }

    /**
     * @param  list<string>  $locales  locales with a lang/ directory, source included
     * @return SheetContents|null null when the tab does not exist
     *
     * @throws RuntimeException when the header row is unusable
     */
    public function read(string $source, array $locales): ?SheetContents
    {
        if (! $this->exists()) {
            return null;
        }

        $grid = $this->sheets->getSheetValues($this->name());

        if ($grid === []) {
            return new SheetContents;
        }

        $headers = array_map(fn (mixed $cell): string => trim((string) $cell), $grid[0]);
        $body = array_slice($grid, 1);
        $columns = $this->columnsByHeader($headers, $body, $source);

        $managed = [self::KEY, self::GROUP, self::DEFAULT];
        $localeHeaders = [];
        $extraHeaders = [];

        foreach (array_keys($columns) as $header) {
            $header = (string) $header;

            if (in_array($header, $managed, true) || $header === $source) {
                continue;
            }

            if (in_array($header, $locales, true)) {
                $localeHeaders[] = $header;
            } else {
                $extraHeaders[] = $header;
            }
        }

        $rows = [];
        $seen = [];
        $duplicates = [];

        foreach ($body as $cells) {
            if (implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            $cell = fn (string $header): string => (string) ($cells[$columns[$header]] ?? '');

            $values = [];
            foreach ([$source, ...$localeHeaders] as $locale) {
                $values[$locale] = $cell($locale);
            }

            $extras = [];
            foreach ($extraHeaders as $header) {
                $extras[$header] = $cell($header);
            }

            $row = new SheetRow(trim($cell(self::GROUP)), trim($cell(self::KEY)), $cell(self::DEFAULT), $values, $extras);

            if (isset($seen[$row->id()])) {
                $duplicates[] = $row->label();

                continue;
            }

            $seen[$row->id()] = true;
            $rows[] = $row;
        }

        return new SheetContents($localeHeaders, $extraHeaders, $rows, $duplicates, $grid);
    }

    /**
     * Replace the tab with this header and these rows, creating it if needed.
     *
     * @param  list<string>  $headers
     * @param  list<SheetRow>  $rows
     */
    public function write(array $headers, array $rows): void
    {
        $name = $this->name();
        $grid = [$headers];

        foreach ($rows as $row) {
            $grid[] = array_map(fn (string $header): string => $this->cell($row, $header), $headers);
        }

        $this->sheets->createSheetIfMissing($name);

        [$sheetId, $rowCount, $columnCount] = $this->grid($name);

        // The API rejects ranges past the tab's grid (a new tab is 1000 x 26),
        // so grow it first when the block is larger.
        $growth = [];
        foreach (['ROWS' => count($grid) - $rowCount, 'COLUMNS' => count($headers) - $columnCount] as $dimension => $missing) {
            if ($missing > 0) {
                $growth[] = ['appendDimension' => ['sheetId' => $sheetId, 'dimension' => $dimension, 'length' => $missing]];
            }
        }

        if ($growth !== []) {
            $this->sheets->batchUpdate($growth);
            $rowCount = max($rowCount, count($grid));
            $columnCount = max($columnCount, count($headers));
        }

        $this->sheets->updateSheetData($name, 'A1', $grid);

        // Updating a range only overwrites the cells written, so rows and
        // columns left over from a larger previous push must be cleared.
        $lastColumn = self::column($columnCount);

        if (count($grid) < $rowCount) {
            $this->sheets->clearSheetData($name, 'A'.(count($grid) + 1).':'.$lastColumn.$rowCount);
        }

        if (count($headers) < $columnCount) {
            $this->sheets->clearSheetData($name, self::column(count($headers) + 1).'1:'.$lastColumn.$rowCount);
        }
    }

    /**
     * A1 column letters for a 1-based column number: 1 => A, 27 => AA.
     */
    public static function column(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [sheetId, rowCount, columnCount]
     */
    protected function grid(string $name): array
    {
        $tab = $this->sheets->getSheet($name);

        if ($tab === null) {
            throw new RuntimeException("The \"{$name}\" tab could not be found after creating it.");
        }

        $properties = $tab->getProperties();
        $grid = $properties->getGridProperties();

        return [(int) $properties->getSheetId(), (int) $grid?->getRowCount(), (int) $grid?->getColumnCount()];
    }

    protected function cell(SheetRow $row, string $header): string
    {
        return match ($header) {
            self::KEY => $row->key,
            self::GROUP => $row->group,
            self::DEFAULT => $row->default,
            default => $row->values[$header] ?? $row->extras[$header] ?? '',
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $body
     * @return array<string, int> header => zero-based column
     */
    protected function columnsByHeader(array $headers, array $body, string $source): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                foreach ($body as $cells) {
                    if ((string) ($cells[$index] ?? '') !== '') {
                        $this->throwMissingHeaderError($index);
                    }
                }

                continue;
            }

            if (isset($columns[$header])) {
                throw new RuntimeException("The \"{$this->name()}\" tab has two \"{$header}\" columns. Rename or remove one.");
            }

            $columns[$header] = $index;
        }

        // Check for values in columns beyond the header row
        foreach ($body as $cells) {
            foreach ($cells as $index => $cell) {
                if ($index >= count($headers) && (string) $cell !== '') {
                    $this->throwMissingHeaderError($index);
                }
            }
        }

        $missing = array_values(array_diff([self::KEY, self::GROUP, self::DEFAULT, $source], array_keys($columns)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The "%s" tab is missing the column(s): %s. Restore them, or delete the tab and run translations:push.',
                $this->name(),
                implode(', ', $missing),
            ));
        }

        return $columns;
    }

    /**
     * Throw an exception for a column with values but no header.
     */
    private function throwMissingHeaderError(int $columnIndex): void
    {
        throw new RuntimeException(sprintf(
            'Column %s has values but no header in the "%s" tab. Clear the column or add a header.',
            self::column($columnIndex + 1),
            $this->name(),
        ));
    }
}
