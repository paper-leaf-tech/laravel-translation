<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\File;
use Mockery;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class PullCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(lang_path('en'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(lang_path('en'));
        File::deleteDirectory(lang_path('fr'));
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
        $mock->shouldReceive('getSheetId')->with('Translations - en')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->expectsOutputToContain('Found 2 translation entries')
            ->assertSuccessful();

        $translations = require lang_path('en/auth.php');
        $this->assertSame('Invalid credentials.', $translations['failed']);
        $this->assertSame('Too many attempts.', $translations['throttle']);
    }

    /** @test */
    public function it_prioritizes_updated_value_over_original_for_source_locale(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['test.key', 'Original', 'Updated'],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->assertSuccessful();

        $translations = require lang_path('en/test.php');
        $this->assertSame('Updated', $translations['key']);
    }

    /** @test */
    public function it_falls_back_to_original_when_updated_is_empty_for_source_locale(): void
    {
        $sheetData = [
            ['Key', 'Original Value', 'Updated Value'],
            ['test.key', 'Original value here', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->assertSuccessful();

        $translations = require lang_path('en/test.php');
        $this->assertSame('Original value here', $translations['key']);
    }

    /** @test */
    public function it_skips_rows_with_empty_translation_for_non_source_locale(): void
    {
        File::ensureDirectoryExists(lang_path('fr'));

        $sheetData = [
            ['Key', 'English (Source)', 'Translation'],
            ['auth.failed', 'Failed', 'Échec'],
            ['auth.throttle', 'Too many attempts.', ''],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->with('Translations - fr')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'fr'])
            ->assertSuccessful();

        $translations = require lang_path('fr/auth.php');
        $this->assertSame('Échec', $translations['failed']);
        $this->assertArrayNotHasKey('throttle', $translations);
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
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->assertSuccessful();

        $translations = require lang_path('en/validation.php');
        $this->assertSame('Required', $translations['required']);
        $this->assertSame('Invalid email', $translations['email']['format']);
        $this->assertSame('Invalid domain', $translations['email']['domain']);
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
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en', '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertFalse(File::exists(lang_path('en/auth.php')));
    }

    /** @test */
    public function it_handles_empty_sheet_gracefully(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn([]);

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'en'])
            ->expectsOutputToContain('No data found in sheet tab')
            ->assertSuccessful();
    }

    /** @test */
    public function it_creates_language_directory_if_not_exists(): void
    {
        $this->assertFalse(File::isDirectory(lang_path('test')));

        $sheetData = [
            ['Key', 'English (Source)', 'Translation'],
            ['auth.failed', 'Failed', 'Translated value'],
        ];

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSheetData')->once()->andReturn($sheetData);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull', ['lang' => 'test'])
            ->assertSuccessful();

        $this->assertTrue(File::isDirectory(lang_path('test')));
        $this->assertTrue(File::exists(lang_path('test/auth.php')));
    }

    /** @test */
    public function it_pulls_all_locales_when_no_arg_given(): void
    {
        File::ensureDirectoryExists(lang_path('fr'));

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->with('Translations - en')->andReturn(123);
        $mock->shouldReceive('getSheetId')->with('Translations - fr')->andReturn(456);
        $mock->shouldReceive('getSheetData')->andReturnUsing(function ($sheet, $range) {
            if ($sheet === 'Translations - en') {
                return [
                    ['Key', 'Original Value', 'Updated Value'],
                    ['auth.failed', 'Failed', ''],
                ];
            }
            return [
                ['Key', 'English (Source)', 'Translation'],
                ['auth.failed', 'Failed', 'Échec'],
            ];
        });
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('=== en ===')
            ->expectsOutputToContain('=== fr ===')
            ->assertSuccessful();

        $en = require lang_path('en/auth.php');
        $fr = require lang_path('fr/auth.php');

        $this->assertSame('Failed', $en['failed']);
        $this->assertSame('Échec', $fr['failed']);
    }

    /** @test */
    public function it_skips_locales_whose_sheet_tab_does_not_exist(): void
    {
        File::ensureDirectoryExists(lang_path('fr'));

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetId')->with('Translations - en')->andReturn(123);
        $mock->shouldReceive('getSheetId')->with('Translations - fr')->andReturn(null);
        $mock->shouldReceive('getSheetData')->with('Translations - en', Mockery::any())->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Failed', ''],
        ]);
        $mock->shouldNotReceive('getSheetData')->with('Translations - fr', Mockery::any());
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:pull')
            ->expectsOutputToContain("Sheet tab 'Translations - fr' not found; skipping")
            ->assertSuccessful();
    }

    /** @test */
    public function it_warns_when_no_local_locales_found_during_discovery(): void
    {
        // Remove the en dir created in setUp
        File::deleteDirectory(lang_path('en'));

        $this->app->instance(GoogleSheetsService::class, Mockery::mock(GoogleSheetsService::class));

        $this->artisan('translations:pull')
            ->expectsOutputToContain('No locales found')
            ->assertSuccessful();
    }
}
