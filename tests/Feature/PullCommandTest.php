<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\File;
use Mockery;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class PullCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up test translation files
        File::deleteDirectory(lang_path('en'));
        File::deleteDirectory(lang_path('test'));

        parent::tearDown();
    }

    /** @test */
    public function it_pulls_translations_from_sheet(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'These credentials do not match our records.', 'Invalid credentials.'],
            ['auth.throttle', 'Too many attempts.', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->expectsOutput('Found 2 translation entries.')
            ->assertSuccessful();

        $this->assertTrue(File::exists(lang_path('en/auth.php')));

        $translations = require lang_path('en/auth.php');
        $this->assertEquals('Invalid credentials.', $translations['failed']);
        $this->assertEquals('Too many attempts.', $translations['throttle']);
    }

    /** @test */
    public function it_prioritizes_updated_value_over_original(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['test.key', 'Original', 'Updated'],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->assertSuccessful();

        $translations = require lang_path('en/test.php');
        $this->assertEquals('Updated', $translations['key']);
    }

    /** @test */
    public function it_falls_back_to_original_when_updated_is_empty(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['test.key', 'Original value here', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->assertSuccessful();

        $translations = require lang_path('en/test.php');
        $this->assertEquals('Original value here', $translations['key']);
    }

    /** @test */
    public function it_handles_nested_translations(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['validation.required', 'Required', ''],
            ['validation.email.format', 'Invalid email', ''],
            ['validation.email.domain', 'Invalid domain', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->assertSuccessful();

        $translations = require lang_path('en/validation.php');
        $this->assertEquals('Required', $translations['required']);
        $this->assertEquals('Invalid email', $translations['email']['format']);
        $this->assertEquals('Invalid domain', $translations['email']['domain']);
    }

    /** @test */
    public function it_shows_preview_in_dry_run_mode(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Failed', ''],
            ['auth.throttle', 'Throttled', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en', '--dry-run' => true])
            ->expectsOutput('DRY RUN - No files will be modified')
            ->assertSuccessful();

        $this->assertFalse(File::exists(lang_path('en/auth.php')));
    }

    /** @test */
    public function it_displays_spreadsheet_url_after_pull(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['test.key', 'Value', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-456/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->expectsOutput('View your spreadsheet: https://docs.google.com/spreadsheets/d/test-456/edit')
            ->assertSuccessful();
    }

    /** @test */
    public function it_handles_empty_sheet_gracefully(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn([]);

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->expectsOutput('No data found in Google Sheet.')
            ->assertSuccessful();
    }

    /** @test */
    public function it_creates_language_directory_if_not_exists(): void
    {
        $this->assertFalse(File::isDirectory(lang_path('test')));

        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Failed', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'test'])
            ->assertSuccessful();

        $this->assertTrue(File::isDirectory(lang_path('test')));
        $this->assertTrue(File::exists(lang_path('test/auth.php')));
    }
}
