<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FakeGoogleSheetsServiceTest extends TestCase
{
    #[Test]
    public function it_writes_from_an_anchor_and_reads_back_trimmed_like_google(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->createSheetIfMissing('Tab');
        $fake->updateSheetData('Tab', 'A1', [['a', 'b', ''], ['c']]);
        $fake->updateSheetData('Tab', 'B3', [['d']]);

        $this->assertSame([['a', 'b'], ['c'], ['', 'd']], $fake->getSheetData('Tab', 'A1:ZZ'));
    }

    #[Test]
    public function it_clears_from_a_row_and_from_a_column(): void
    {
        $fake = new FakeGoogleSheetsService;
        $fake->seed('Tab', [['a', 'b', 'c'], ['d', 'e', 'f'], ['g', 'h', 'i']]);

        $fake->clearSheetData('Tab', 'A3:ZZ');
        $fake->clearSheetData('Tab', 'C1:ZZ');

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
}
