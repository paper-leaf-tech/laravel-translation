<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\File;
use Mockery;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class PushCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test translation files
        $this->createTestTranslationFiles();
    }

    protected function tearDown(): void
    {
        // Clean up test translation files
        File::deleteDirectory(lang_path('en'));
        File::deleteDirectory(lang_path('test'));

        parent::tearDown();
    }

    protected function createTestTranslationFiles(): void
    {
        File::ensureDirectoryExists(lang_path('en'));

        File::put(lang_path('en/auth.php'), <<<'PHP'
<?php

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts.',
];
PHP);

        File::put(lang_path('en/validation.php'), <<<'PHP'
<?php

return [
    'required' => 'The :attribute field is required.',
    'email' => 'The :attribute must be a valid email address.',
];
PHP);
    }

    /** @test */
    public function it_fails_when_translation_directory_does_not_exist(): void
    {
        $this->artisan('translations:push', ['lang' => 'nonexistent'])
            ->expectsOutput('Translation directory not found: '.lang_path('nonexistent'))
            ->assertFailed();
    }

    /** @test */
    public function it_collects_translations_from_files(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')->once()->andReturn(true);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->expectsOutput('Found 4 translation keys.')
            ->assertSuccessful();
    }

    /** @test */
    public function it_skips_backup_when_no_backup_flag_is_used(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')->once()->andReturn(true);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');
        $mock->shouldNotReceive('createBackup');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->assertSuccessful();
    }

    /** @test */
    public function it_creates_backup_when_sheet_has_data(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Old value', 'Old value'],
        ]);
        $mock->shouldReceive('createBackup')->once()->andReturn('Backup 2024-01-01 12:00:00');
        $mock->shouldReceive('pruneBackups')->once()->with(5)->andReturn(0);
        $mock->shouldReceive('updateSheetData')->once()->andReturn(true);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['lang' => 'en'])
            ->expectsOutput('✓ Backup created: Backup 2024-01-01 12:00:00')
            ->assertSuccessful();
    }

    /** @test */
    public function it_displays_spreadsheet_url_after_push(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')->once()->andReturn(true);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-123/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->expectsOutput('View your spreadsheet: https://docs.google.com/spreadsheets/d/test-123/edit')
            ->assertSuccessful();
    }

    /** @test */
    public function it_handles_nested_translations(): void
    {
        File::put(lang_path('en/nested.php'), <<<'PHP'
<?php

return [
    'level1' => [
        'level2' => [
            'level3' => 'Deep value',
        ],
    ],
];
PHP);

        $capturedData = null;
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedData))
            ->andReturn(true);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->assertSuccessful();

        // Verify nested key was flattened correctly
        $keys = array_column($capturedData, 0);
        $this->assertContains('nested.level1.level2.level3', $keys);
    }
}
