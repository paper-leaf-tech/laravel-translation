<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Sheet\SheetRow;
use PaperleafTech\LaravelTranslation\Sheet\TranslationSheet;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TranslationSheetTest extends TestCase
{
    private FakeGoogleSheetsService $sheets;

    private TranslationSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheets = $this->fakeSheets();
        $this->sheet = $this->app->make(TranslationSheet::class);
    }

    #[Test]
    public function it_reads_null_when_the_tab_is_missing(): void
    {
        $this->assertNull($this->sheet->read('en', ['en', 'fr']));
    }

    #[Test]
    public function it_reads_an_empty_tab_as_no_rows(): void
    {
        $this->sheets->createSheetIfMissing('Translations');

        $contents = $this->sheet->read('en', ['en', 'fr']);

        $this->assertSame([], $contents->rows);
        $this->assertSame([], $contents->locales);
    }

    #[Test]
    public function it_reads_rows_by_header_name_whatever_the_column_order(): void
    {
        $this->sheets->seed('Translations', [
            ['notes', 'fr', 'key', 'en', 'group', 'default', 'de'],
            ['Shorter', 'Échec', 'failed', 'Failed!', 'auth', 'Failed', 'Fehler'],
            ['', '', 'throttle', 'Slow down', 'auth', 'Slow down'],
        ]);

        $contents = $this->sheet->read('en', ['en', 'fr']);

        $this->assertSame(['fr'], $contents->locales);
        $this->assertSame(['notes', 'de'], $contents->extras);
        $this->assertEquals([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed!', 'fr' => 'Échec'], ['notes' => 'Shorter', 'de' => 'Fehler']),
            new SheetRow('auth', 'throttle', 'Slow down', ['en' => 'Slow down', 'fr' => ''], ['notes' => '', 'de' => '']),
        ], $contents->rows);
        $this->assertSame($this->sheets->rows('Translations'), $contents->grid);
    }

    #[Test]
    public function it_trims_only_key_and_group(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en', 'fr'],
            [' failed ', " auth\n", 'Failed', 'Failed', "  Ligne un\nLigne deux\u{00A0}"],
        ]);

        $row = $this->sheet->read('en', ['en', 'fr'])->rows[0];

        $this->assertSame('failed', $row->key);
        $this->assertSame('auth', $row->group);
        $this->assertSame("  Ligne un\nLigne deux\u{00A0}", $row->value('fr'));
    }

    #[Test]
    public function it_skips_blank_rows(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en'],
            ['failed', 'auth', 'Failed', 'Failed'],
            [],
            ['', '', '', ''],
            ['throttle', 'auth', 'Slow', 'Slow'],
        ]);

        $this->assertCount(2, $this->sheet->read('en', ['en'])->rows);
    }

    #[Test]
    public function it_keeps_the_first_of_duplicate_rows(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en', 'fr'],
            ['failed', 'auth', 'Failed', 'Failed', 'Premier'],
            ['failed', 'auth', 'Failed', 'Failed', 'Second'],
        ]);

        $contents = $this->sheet->read('en', ['en', 'fr']);

        $this->assertCount(1, $contents->rows);
        $this->assertSame('Premier', $contents->rows[0]->value('fr'));
        $this->assertSame(['auth.failed'], $contents->duplicates);
    }

    #[Test]
    public function it_names_missing_required_columns(): void
    {
        $this->sheets->seed('Translations', [['key', 'group', 'fr']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing the column(s): default, en');

        $this->sheet->read('en', ['en', 'fr']);
    }

    #[Test]
    public function it_rejects_two_columns_with_the_same_header(): void
    {
        $this->sheets->seed('Translations', [['key', 'group', 'default', 'en', 'notes', 'notes']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('two "notes" columns');

        $this->sheet->read('en', ['en']);
    }

    #[Test]
    public function it_rejects_values_under_a_blank_header(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', '', 'default', 'en'],
            ['failed', 'auth', 'stray note', 'Failed', 'Failed'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Column C has values but no header');

        $this->sheet->read('en', ['en']);
    }

    #[Test]
    public function it_ignores_an_empty_column_with_a_blank_header(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', '', 'default', 'en'],
            ['failed', 'auth', '', 'Failed', 'Failed'],
        ]);

        $this->assertCount(1, $this->sheet->read('en', ['en'])->rows);
    }

    #[Test]
    public function it_writes_the_header_and_rows_and_clears_what_was_left_over(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en', 'fr', 'old', 'older'],
            ['a', 'g', 'A', 'A', 'x', 'y', 'z'],
            ['b', 'g', 'B', 'B', 'x', 'y', 'z'],
            ['c', 'g', 'C', 'C', 'x', 'y', 'z'],
        ]);

        $this->sheet->write(['key', 'group', 'default', 'en', 'notes'], [
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed!'], ['notes' => 'n']),
        ]);

        $this->assertSame([
            ['key', 'group', 'default', 'en', 'notes'],
            ['failed', 'auth', 'Failed', 'Failed!', 'n'],
        ], $this->sheets->rows('Translations'));
    }

    #[Test]
    public function it_creates_the_tab_when_writing_to_a_missing_one(): void
    {
        config()->set('laravel-translation.sheet', 'Strings');

        $this->sheet->write(['key', 'group', 'default', 'en'], []);

        $this->assertSame([['key', 'group', 'default', 'en']], $this->sheets->rows('Strings'));
    }

    #[Test]
    #[DataProvider('columns')]
    public function it_converts_column_numbers_to_letters(int $number, string $letters): void
    {
        $this->assertSame($letters, TranslationSheet::column($number));
    }

    public static function columns(): array
    {
        return [[1, 'A'], [3, 'C'], [26, 'Z'], [27, 'AA'], [52, 'AZ'], [703, 'AAA']];
    }
}
