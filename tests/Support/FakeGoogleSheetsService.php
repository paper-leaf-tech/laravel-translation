<?php

namespace PaperleafTech\LaravelTranslation\Tests\Support;

use Google\Service\Sheets\Sheet;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use RuntimeException;

/**
 * An in-memory spreadsheet. Cells are stored sparsely by zero-based row and
 * column; reads come back the way the Sheets API returns them, with trailing
 * empty cells and rows trimmed. Each tab has a grid size, like a real tab, and
 * ranges past it are rejected the way the Sheets API rejects them.
 */
class FakeGoogleSheetsService extends GoogleSheetsService
{
    /** @var array<string, array<int, array<int, string>>> */
    public array $tabs = [];

    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    /** A tab added through the API starts with this many rows and columns. */
    public const DEFAULT_GRID = [1000, 26];

    /** @var array<string, array{0: int, 1: int}> tab => [rows, columns] */
    public array $grids = [];

    /** @var array<string, array<string, mixed>> tab => extra Sheet fields (conditionalFormats, protectedRanges, basicFilter) */
    public array $metadata = [];

    /**
     * @param  list<list<string>>  $rows
     */
    public function seed(string $tab, array $rows): void
    {
        $this->tabs[$tab] = array_map(fn (array $cells): array => array_map('strval', array_values($cells)), array_values($rows));

        [$gridRows, $gridColumns] = $this->grids[$tab] ?? self::DEFAULT_GRID;
        $this->grids[$tab] = [
            max($gridRows, count($rows)),
            max($gridColumns, ...array_map('count', [[], ...array_values($rows)])),
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function rows(string $tab): array
    {
        return $this->trimmed($this->tabs[$tab] ?? []);
    }

    public function getSheetId(string $sheetName): ?int
    {
        $index = array_search($sheetName, array_keys($this->tabs), true);

        return $index === false ? null : 1000 + $index;
    }

    public function createSheetIfMissing(string $sheetName): bool
    {
        if (array_key_exists($sheetName, $this->tabs)) {
            return false;
        }

        $this->tabs[$sheetName] = [];
        $this->grids[$sheetName] = self::DEFAULT_GRID;

        return true;
    }

    public function getSheetValues(string $sheetName): array
    {
        return $this->rows($sheetName);
    }

    public function getSheetData(string $sheetName, string $range): array
    {
        [$column, $row, $endColumn, $endRow] = $this->bounds($sheetName, $range);
        $window = [];

        foreach ($this->tabs[$sheetName] ?? [] as $r => $cells) {
            foreach ($cells as $c => $value) {
                if ($r >= $row && $r <= $endRow && $c >= $column && $c <= $endColumn) {
                    $window[$r - $row][$c - $column] = $value;
                }
            }
        }

        return $this->trimmed($window);
    }

    public function updateSheetData(string $sheetName, string $range, array $values): bool
    {
        [$column, $row] = $this->bounds($sheetName, $range);
        [$gridRows, $gridColumns] = $this->grid($sheetName);
        $width = max(0, ...array_map(fn (array $cells): int => count($cells), [[], ...array_values($values)]));

        if ($row + count($values) > $gridRows || $column + $width > $gridColumns) {
            throw $this->exceeds($sheetName, $range);
        }

        foreach (array_values($values) as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $this->tabs[$sheetName][$row + $r][$column + $c] = (string) $value;
            }
        }

        return true;
    }

    public function clearSheetData(string $sheetName, string $range): bool
    {
        [$column, $row, $endColumn, $endRow] = $this->bounds($sheetName, $range);

        foreach ($this->tabs[$sheetName] ?? [] as $r => $cells) {
            foreach (array_keys($cells) as $c) {
                if ($r >= $row && $r <= $endRow && $c >= $column && $c <= $endColumn) {
                    $this->tabs[$sheetName][$r][$c] = '';
                }
            }
        }

        return true;
    }

    public function getSpreadsheetUrl(?string $sheetName = null): string
    {
        return 'https://docs.google.com/spreadsheets/d/test-spreadsheet-id/edit';
    }

    public function getSheet(string $sheetName): ?Sheet
    {
        $sheetId = $this->getSheetId($sheetName);

        if ($sheetId === null) {
            return null;
        }

        [$rowCount, $columnCount] = $this->grid($sheetName);

        return new Sheet([
            'properties' => [
                'sheetId' => $sheetId,
                'title' => $sheetName,
                'gridProperties' => ['rowCount' => $rowCount, 'columnCount' => $columnCount],
            ],
        ] + ($this->metadata[$sheetName] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     */
    public function batchUpdate(array $requests): void
    {
        $this->batches[] = $requests;

        foreach ($requests as $request) {
            $this->apply($request);
        }
    }

    /**
     * Apply appendDimension the way the API does: rows or columns are added
     * to the end of the tab's grid. Other requests are only recorded.
     */
    private function apply(array $request): void
    {
        if (! isset($request['appendDimension'])) {
            return;
        }

        $append = $request['appendDimension'];
        $tab = array_keys($this->tabs)[$append['sheetId'] - 1000] ?? null;

        if ($tab === null) {
            throw new RuntimeException("No grid with id: {$append['sheetId']}");
        }

        [$rows, $columns] = $this->grid($tab);

        $this->grids[$tab] = $append['dimension'] === 'ROWS'
            ? [$rows + $append['length'], $columns]
            : [$rows, $columns + $append['length']];
    }

    /**
     * @return array{0: int, 1: int} [rows, columns]
     */
    private function grid(string $tab): array
    {
        return $this->grids[$tab] ?? self::DEFAULT_GRID;
    }

    /**
     * Zero-based, inclusive [column, row, endColumn, endRow] of an A1 range
     * such as "B3", "A6:C" or "C1:Z1000". An open end runs to the edge of the
     * grid; a range reaching past the grid is rejected like the API does.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function bounds(string $tab, string $range): array
    {
        if (! preg_match('/^([A-Z]+)(\d+)(?::([A-Z]*)(\d*))?$/', $range, $matches)) {
            throw new RuntimeException("Unable to parse range: {$range}");
        }

        [$gridRows, $gridColumns] = $this->grid($tab);
        $column = $this->columnIndex($matches[1]);
        $row = (int) $matches[2] - 1;
        $hasEnd = isset($matches[3]) || isset($matches[4]);

        $endColumn = ($matches[3] ?? '') !== '' ? $this->columnIndex($matches[3]) : ($hasEnd ? $gridColumns - 1 : $column);
        $endRow = ($matches[4] ?? '') !== '' ? (int) $matches[4] - 1 : ($hasEnd ? $gridRows - 1 : $row);

        if ($column >= $gridColumns || $row >= $gridRows || $endColumn >= $gridColumns || $endRow >= $gridRows) {
            throw $this->exceeds($tab, $range);
        }

        return [$column, $row, $endColumn, $endRow];
    }

    private function columnIndex(string $letters): int
    {
        $column = 0;
        foreach (str_split($letters) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return $column - 1;
    }

    private function exceeds(string $tab, string $range): RuntimeException
    {
        [$rows, $columns] = $this->grid($tab);

        return new RuntimeException("Range ('{$tab}'!{$range}) exceeds grid limits. Max rows: {$rows}, max columns: {$columns}");
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return list<list<string>>
     */
    private function trimmed(array $grid): array
    {
        $rows = [];
        $lastRow = $grid === [] ? -1 : max(array_keys($grid));

        for ($r = 0; $r <= $lastRow; $r++) {
            $cells = $grid[$r] ?? [];
            $lastColumn = $cells === [] ? -1 : max(array_keys($cells));
            $row = [];

            for ($c = 0; $c <= $lastColumn; $c++) {
                $row[] = $cells[$c] ?? '';
            }

            while ($row !== [] && end($row) === '') {
                array_pop($row);
            }

            $rows[] = $row;
        }

        while ($rows !== [] && end($rows) === []) {
            array_pop($rows);
        }

        return $rows;
    }
}
