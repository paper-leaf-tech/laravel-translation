<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class FakeGoogleSheetsServiceTest extends TestCase
{
    #[Test]
    public function it_writes_from_an_anchor_and_reads_back_trimmed_like_google(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');
        $fake->updateSheetData('Tab', 'A1', [['a', 'b', ''], ['c']]);
        $fake->updateSheetData('Tab', 'B3', [['d']]);

        $this->assertSame([['a', 'b'], ['c'], ['', 'd']], $fake->getSheetData('Tab', 'A1:Z'));
    }

    #[Test]
    public function it_clears_from_a_row_and_from_a_column(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->seed('Tab', [['a', 'b', 'c'], ['d', 'e', 'f'], ['g', 'h', 'i']]);

        $fake->clearSheetData('Tab', 'A3:Z1000');
        $fake->clearSheetData('Tab', 'C1:Z1000');

        $this->assertSame([['a', 'b'], ['d', 'e']], $fake->rows('Tab'));
    }

    #[Test]
    public function it_reports_missing_tabs_and_creates_them_once(): void
    {
        $fake = new FakeGoogleSheetsService;

        $this->assertNull($fake->getSheetId('Tab'));
        $this->assertNull($fake->getSheet('Tab'));
        $this->assertTrue($fake->createSheetIfMissing('Tab'));
        $this->assertFalse($fake->createSheetIfMissing('Tab'));
        $this->assertSame(1000, $fake->getSheetId('Tab'));
        $this->assertSame(1000, $fake->getSheet('Tab')->getProperties()->getSheetId());
    }

    #[Test]
    public function it_honours_the_end_column_and_row_when_clearing(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->seed('Tab', [['a', 'b', 'c'], ['d', 'e', 'f'], ['g', 'h', 'i']]);

        $fake->clearSheetData('Tab', 'A2:B2');
        $fake->clearSheetData('Tab', 'C3:C');

        $this->assertSame([['a', 'b', 'c'], ['', '', 'f'], ['g', 'h']], $fake->rows('Tab'));
    }

    #[Test]
    public function it_reads_only_the_requested_range_and_the_whole_tab_by_name(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->seed('Tab', [['a', 'b', 'c'], ['d', 'e', 'f']]);

        $this->assertSame([['e', 'f']], $fake->getSheetData('Tab', 'B2:C'));
        $this->assertSame([['a', 'b', 'c'], ['d', 'e', 'f']], $fake->getSheetValues('Tab'));
    }

    #[Test]
    public function it_gives_new_tabs_a_default_grid_and_reports_it(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');

        $grid = $fake->getSheet('Tab')->getProperties()->getGridProperties();

        $this->assertSame([1000, 26], [$grid->getRowCount(), $grid->getColumnCount()]);
    }

    #[Test]
    public function it_grows_the_grid_when_seeding_more_than_it_holds(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->seed('Tab', [array_fill(0, 30, 'x')]);

        $this->assertSame([1000, 30], $fake->grids['Tab']);
    }

    #[Test]
    public function it_rejects_an_update_past_the_grid(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');
        $fake->grids['Tab'] = [3, 4];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds grid limits');

        try {
            $fake->updateSheetData('Tab', 'A2', [['a'], ['b'], ['c']]);
        } finally {
            $this->assertSame([], $fake->rows('Tab'));
        }
    }

    #[Test]
    public function it_rejects_an_update_wider_than_the_grid(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');
        $fake->grids['Tab'] = [3, 4];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds grid limits');

        $fake->updateSheetData('Tab', 'B1', [['a', 'b', 'c', 'd']]);
    }

    #[Test]
    public function it_rejects_clears_and_reads_past_the_grid(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');

        foreach ([
            fn () => $fake->clearSheetData('Tab', 'A1:ZZ'),
            fn () => $fake->clearSheetData('Tab', 'A1001:Z1001'),
            fn () => $fake->clearSheetData('Tab', 'AA1:AA2'),
            fn () => $fake->getSheetData('Tab', 'A1:ZZ'),
            fn () => $fake->getSheetData('Tab', 'A1:B1001'),
        ] as $call) {
            $message = null;

            try {
                $call();
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }

            $this->assertStringContainsString('exceeds grid limits', (string) $message);
        }
    }

    #[Test]
    public function it_records_batches_and_applies_append_dimension(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');
        $fake->grids['Tab'] = [3, 4];

        $requests = [
            ['appendDimension' => ['sheetId' => 1000, 'dimension' => 'ROWS', 'length' => 2]],
            ['appendDimension' => ['sheetId' => 1000, 'dimension' => 'COLUMNS', 'length' => 3]],
        ];
        $fake->batchUpdate($requests);

        $this->assertSame([$requests], $fake->batches);
        $this->assertSame([5, 7], $fake->grids['Tab']);
        $this->assertTrue($fake->updateSheetData('Tab', 'A1', array_fill(0, 5, array_fill(0, 7, 'x'))));
    }
}
