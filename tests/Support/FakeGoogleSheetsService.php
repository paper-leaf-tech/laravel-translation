<?php

namespace PaperleafTech\LaravelTranslation\Tests\Support;

use Google\Service\Sheets\Sheet;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

/**
 * An in-memory spreadsheet. Cells are stored sparsely by zero-based row and
 * column; reads come back the way the Sheets API returns them, with trailing
 * empty cells and rows trimmed.
 */
class FakeGoogleSheetsService extends GoogleSheetsService
{
    /** @var array<string, array<int, array<int, string>>> */
    public array $tabs = [];

    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    /** @var array<string, array<string, mixed>> tab => extra Sheet fields (conditionalFormats, protectedRanges, basicFilter) */
    public array $metadata = [];

    /**
     * @param  list<list<string>>  $rows
     */
    public function seed(string $tab, array $rows): void
    {
        $this->tabs[$tab] = array_map(fn (array $cells): array => array_map('strval', array_values($cells)), array_values($rows));
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

        return true;
    }

    public function getSheetData(string $sheetName, string $range): array
    {
        return $this->rows($sheetName);
    }

    public function updateSheetData(string $sheetName, string $range, array $values): bool
    {
        [$column, $row] = $this->anchor($range);

        foreach (array_values($values) as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $this->tabs[$sheetName][$row + $r][$column + $c] = (string) $value;
            }
        }

        return true;
    }

    public function clearSheetData(string $sheetName, string $range): bool
    {
        [$column, $row] = $this->anchor($range);

        foreach ($this->tabs[$sheetName] ?? [] as $r => $cells) {
            foreach (array_keys($cells) as $c) {
                if ($r >= $row && $c >= $column) {
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

        return new Sheet(['properties' => ['sheetId' => $sheetId, 'title' => $sheetName]] + ($this->metadata[$sheetName] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     */
    public function batchUpdate(array $requests): void
    {
        $this->batches[] = $requests;
    }

    /**
     * Zero-based [column, row] of a range's top-left cell, e.g. "B3:ZZ" => [1, 2].
     *
     * @return array{0: int, 1: int}
     */
    private function anchor(string $range): array
    {
        preg_match('/^([A-Z]+)(\d+)/', $range, $matches);

        $column = 0;
        foreach (str_split($matches[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return [$column - 1, (int) $matches[2] - 1];
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
