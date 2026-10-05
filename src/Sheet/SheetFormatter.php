<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

use Google\Model;
use Google\Service\Sheets\Sheet;

/**
 * The formatting push applies to the translation tab. Rows are re-sorted on
 * every push, so everything is set per column or decided by a cell's
 * contents. Rules and the protected range this class owns are found by a
 * marker and replaced, so re-running gives the same result and leaves rules
 * added by people or an app's TranslationsPushed listener alone.
 */
class SheetFormatter
{
    /** Always true; marks a conditional rule as this package's own. */
    public const MARKER = 'N("laravel-translation")=0';

    public const PROTECTION_DESCRIPTION = 'Managed by laravel-translation: key, group and default come from code.';

    private const HEADER_BACKGROUND = '#1F4E5F';

    private const HEADER_TEXT = '#FFFFFF';

    private const READ_ONLY = '#F2F4F6';

    private const MISSING = '#FBE3E1';

    /** Pixel widths of the columns that come from code, which are also the first three. */
    private const WIDTHS = [TranslationSheet::KEY => 260, TranslationSheet::GROUP => 180, TranslationSheet::DEFAULT => 320];

    private const LOCALE_WIDTH = 320;

    /**
     * @param  list<string>  $headers  the header row as push wrote it
     * @param  list<string>  $localeHeaders  the source locale and every other locale column
     * @return list<array<string, mixed>>
     */
    public function requests(Sheet $sheet, array $headers, array $localeHeaders): array
    {
        $sheetId = (int) $sheet->getProperties()->getSheetId();
        $columns = count($headers);
        $fromCode = count(self::WIDTHS);

        $requests = [
            ['updateSheetProperties' => [
                'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 1, 'frozenColumnCount' => 1]],
                'fields' => 'gridProperties.frozenRowCount,gridProperties.frozenColumnCount',
            ]],
            $this->format($this->range($sheetId, 0, 1, 0, $columns), [
                'backgroundColor' => $this->colour(self::HEADER_BACKGROUND),
                'textFormat' => ['bold' => true, 'foregroundColor' => $this->colour(self::HEADER_TEXT)],
            ], 'userEnteredFormat(backgroundColor,textFormat)'),
            $this->format($this->range($sheetId, 1, null, 0, $columns), [
                'wrapStrategy' => 'WRAP',
                'verticalAlignment' => 'TOP',
            ], 'userEnteredFormat(wrapStrategy,verticalAlignment)'),
            $this->format($this->range($sheetId, 1, null, 0, $fromCode), [
                'backgroundColor' => $this->colour(self::READ_ONLY),
            ], 'userEnteredFormat.backgroundColor'),
            // Plain text, so a typed "0012" or "1/2" stays as written instead
            // of becoming a number or a date.
            $this->format($this->range($sheetId, 1, null, 0, $this->lastLocaleColumn($headers, $localeHeaders, $fromCode)), [
                'numberFormat' => ['type' => 'TEXT'],
            ], 'userEnteredFormat.numberFormat'),
        ];

        foreach ($headers as $index => $header) {
            $width = self::WIDTHS[$header] ?? (in_array($header, $localeHeaders, true) ? self::LOCALE_WIDTH : null);

            if ($width !== null) {
                $requests[] = ['updateDimensionProperties' => [
                    'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1],
                    'properties' => ['pixelSize' => $width],
                    'fields' => 'pixelSize',
                ]];
            }
        }

        foreach ($this->managedRuleIndexes($sheet) as $index) {
            $requests[] = ['deleteConditionalFormatRule' => ['sheetId' => $sheetId, 'index' => $index]];
        }

        foreach ($localeHeaders as $locale) {
            $index = array_search($locale, $headers, true);

            if ($index === false) {
                continue;
            }

            $letter = TranslationSheet::column($index + 1);

            $requests[] = ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
                'ranges' => [$this->range($sheetId, 1, null, $index, $index + 1)],
                'booleanRule' => [
                    'condition' => ['type' => 'CUSTOM_FORMULA', 'values' => [['userEnteredValue' => "=AND(\$A2<>\"\",{$letter}2=\"\",".self::MARKER.')']]],
                    'format' => ['backgroundColor' => $this->colour(self::MISSING)],
                ],
            ]]];
        }

        foreach ($sheet->getProtectedRanges() ?? [] as $protected) {
            if ($protected->getDescription() === self::PROTECTION_DESCRIPTION) {
                $requests[] = ['deleteProtectedRange' => ['protectedRangeId' => (int) $protected->getProtectedRangeId()]];
            }
        }

        $requests[] = ['addProtectedRange' => ['protectedRange' => [
            'range' => $this->range($sheetId, 1, null, 0, $fromCode),
            'description' => self::PROTECTION_DESCRIPTION,
            'warningOnly' => true,
        ]]];

        $filter = $this->filter($sheet, $sheetId, $columns);

        if ($filter !== null) {
            $requests[] = ['setBasicFilter' => ['filter' => $filter]];
        }

        return $requests;
    }

    /**
     * A filter over every column: a new one when the tab has none, or the
     * existing one widened, with its sorting and criteria kept, when columns
     * were added past it. Null when the existing filter already covers them.
     *
     * @return array<string, mixed>|null
     */
    private function filter(Sheet $sheet, int $sheetId, int $columns): ?array
    {
        $range = $this->range($sheetId, 0, null, 0, $columns);
        $existing = $sheet->getBasicFilter();

        if ($existing === null) {
            return ['range' => $range];
        }

        $end = $existing->getRange()?->getEndColumnIndex();

        if ($end === null || $end >= $columns) {
            return null;
        }

        return array_filter([
            'range' => $range,
            'sortSpecs' => $this->toArrays($existing->getSortSpecs() ?? []),
            'filterSpecs' => $this->toArrays($existing->getFilterSpecs() ?? []),
        ], fn (array $value): bool => $value !== []);
    }

    /**
     * @param  list<Model>  $models
     * @return list<array<string, mixed>>
     */
    private function toArrays(array $models): array
    {
        return array_map(fn (Model $model): array => json_decode(json_encode($model->toSimpleObject()), true), $models);
    }

    /**
     * The end (exclusive) of the leading key, group, default and locale
     * columns, which push writes contiguously at the start of the header.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $localeHeaders
     */
    private function lastLocaleColumn(array $headers, array $localeHeaders, int $fromCode): int
    {
        $end = $fromCode;

        foreach ($headers as $index => $header) {
            if (in_array($header, $localeHeaders, true)) {
                $end = max($end, $index + 1);
            }
        }

        return $end;
    }

    /**
     * Indexes of this package's conditional rules, highest first so each
     * deletion leaves the remaining indexes valid.
     *
     * @return list<int>
     */
    private function managedRuleIndexes(Sheet $sheet): array
    {
        $indexes = [];

        foreach ($sheet->getConditionalFormats() ?? [] as $index => $rule) {
            foreach ($rule->getBooleanRule()?->getCondition()?->getValues() ?? [] as $value) {
                if (str_contains((string) $value->getUserEnteredValue(), self::MARKER)) {
                    $indexes[] = (int) $index;

                    break;
                }
            }
        }

        rsort($indexes);

        return $indexes;
    }

    /**
     * @param  array<string, mixed>  $format
     * @param  array<string, int>  $range
     * @return array<string, mixed>
     */
    private function format(array $range, array $format, string $fields): array
    {
        return ['repeatCell' => ['range' => $range, 'cell' => ['userEnteredFormat' => $format], 'fields' => $fields]];
    }

    /**
     * A grid range; a null end row runs to the bottom of the tab.
     *
     * @return array<string, int>
     */
    private function range(int $sheetId, int $startRow, ?int $endRow, int $startColumn, int $endColumn): array
    {
        return array_filter([
            'sheetId' => $sheetId,
            'startRowIndex' => $startRow,
            'endRowIndex' => $endRow,
            'startColumnIndex' => $startColumn,
            'endColumnIndex' => $endColumn,
        ], fn (?int $value): bool => $value !== null);
    }

    /**
     * @return array{red: float, green: float, blue: float}
     */
    private function colour(string $hex): array
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return ['red' => round($red / 255, 4), 'green' => round($green / 255, 4), 'blue' => round($blue / 255, 4)];
    }
}
