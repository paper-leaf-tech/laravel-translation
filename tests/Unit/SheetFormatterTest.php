<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use Google\Service\Sheets\Sheet;
use PaperleafTech\LaravelTranslation\Sheet\SheetFormatter;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SheetFormatterTest extends TestCase
{
    private const HEADERS = ['key', 'group', 'default', 'en', 'fr', 'notes'];

    private function sheet(array $fields = []): Sheet
    {
        return new Sheet(['properties' => ['sheetId' => 7, 'title' => 'Translations']] + $fields);
    }

    private function rule(string $formula): array
    {
        return ['ranges' => [['sheetId' => 7]], 'booleanRule' => ['condition' => ['type' => 'CUSTOM_FORMULA', 'values' => [['userEnteredValue' => $formula]]]]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ofType(array $requests, string $type): array
    {
        return array_values(array_map(fn (array $request): array => $request[$type], array_filter($requests, fn (array $request): bool => isset($request[$type]))));
    }

    private function requests(?Sheet $sheet = null): array
    {
        return (new SheetFormatter)->requests($sheet ?? $this->sheet(), self::HEADERS, ['en', 'fr']);
    }

    #[Test]
    public function it_adds_a_missing_translation_rule_per_locale_column(): void
    {
        $rules = $this->ofType($this->requests(), 'addConditionalFormatRule');

        $this->assertCount(2, $rules);
        $this->assertSame('=AND($A2<>"",D2="",N("laravel-translation")=0)', $rules[0]['rule']['booleanRule']['condition']['values'][0]['userEnteredValue']);
        $this->assertSame(4, $rules[1]['rule']['ranges'][0]['startColumnIndex']);
    }

    #[Test]
    public function it_replaces_only_its_own_rules_and_protection(): void
    {
        $requests = $this->requests($this->sheet([
            'conditionalFormats' => [
                $this->rule('=AND($A2<>"",D2="",'.SheetFormatter::MARKER.')'),
                $this->rule('=$F2="approved"'),
                $this->rule('=AND($A2<>"",E2="",'.SheetFormatter::MARKER.')'),
            ],
            'protectedRanges' => [
                ['protectedRangeId' => 5, 'description' => SheetFormatter::PROTECTION_DESCRIPTION],
                ['protectedRangeId' => 6, 'description' => 'Added by someone else'],
            ],
        ]));

        $this->assertSame([2, 0], array_column($this->ofType($requests, 'deleteConditionalFormatRule'), 'index'));
        $this->assertSame([5], array_column($this->ofType($requests, 'deleteProtectedRange'), 'protectedRangeId'));

        $added = $this->ofType($requests, 'addProtectedRange');
        $this->assertCount(1, $added);
        $this->assertTrue($added[0]['protectedRange']['warningOnly']);
        $this->assertSame(SheetFormatter::PROTECTION_DESCRIPTION, $added[0]['protectedRange']['description']);
        $this->assertSame(3, $added[0]['protectedRange']['range']['endColumnIndex']);
    }

    #[Test]
    public function it_adds_a_filter_only_when_the_tab_has_none(): void
    {
        $this->assertCount(1, $this->ofType($this->requests(), 'setBasicFilter'));
        $this->assertCount(0, $this->ofType($this->requests($this->sheet(['basicFilter' => ['range' => ['sheetId' => 7]]])), 'setBasicFilter'));
    }

    #[Test]
    public function it_freezes_the_header_and_sizes_only_managed_columns(): void
    {
        $requests = $this->requests();

        $frozen = $this->ofType($requests, 'updateSheetProperties')[0]['properties']['gridProperties'];
        $this->assertSame(['frozenRowCount' => 1, 'frozenColumnCount' => 1], $frozen);

        $sized = array_map(fn (array $request): int => $request['range']['startIndex'], $this->ofType($requests, 'updateDimensionProperties'));
        $this->assertSame([0, 1, 2, 3, 4], $sized);
    }
}
