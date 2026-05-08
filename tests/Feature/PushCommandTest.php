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
        $this->createEnglishTranslations();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(lang_path('en'));
        File::deleteDirectory(lang_path('fr'));
        File::deleteDirectory(lang_path('test'));
        File::deleteDirectory(lang_path('vendor'));
        File::deleteDirectory(lang_path('.cache'));

        parent::tearDown();
    }

    protected function createEnglishTranslations(): void
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

    protected function createFrenchTranslations(): void
    {
        File::ensureDirectoryExists(lang_path('fr'));
        File::put(lang_path('fr/auth.php'), <<<'PHP'
<?php

return [
    'failed' => 'Identifiants invalides.',
];
PHP);
    }

    protected function mockSheets(): Mockery\MockInterface
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('createSheetIfMissing')->andReturn(false)->byDefault();
        $mock->shouldReceive('getSheetData')->andReturn([])->byDefault();
        $mock->shouldReceive('updateSheetData')->andReturn(true)->byDefault();
        $mock->shouldReceive('clearSheetData')->andReturn(true)->byDefault();
        $mock->shouldReceive('getSheetId')->andReturn(123)->byDefault();
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://docs.google.com/spreadsheets/d/test-id/edit')->byDefault();

        $this->app->instance(GoogleSheetsService::class, $mock);

        return $mock;
    }

    /** @test */
    public function it_fails_when_translation_directory_does_not_exist(): void
    {
        $this->mockSheets();

        $this->artisan('translations:push', ['lang' => 'nonexistent'])
            ->expectsOutputToContain('Translation directory not found')
            ->assertFailed();
    }

    /** @test */
    public function it_collects_translations_from_files(): void
    {
        $this->mockSheets();

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->expectsOutputToContain('Found 4 translation key(s)')
            ->assertSuccessful();
    }

    /** @test */
    public function it_skips_backup_when_no_backup_flag_is_used(): void
    {
        $mock = $this->mockSheets();
        $mock->shouldReceive('getSheetData')->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Old', ''],
        ]);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->assertSuccessful();

        $this->assertFalse(File::isDirectory($this->backupPath()));
    }

    /** @test */
    public function it_writes_local_backup_when_sheet_has_data(): void
    {
        $mock = $this->mockSheets();
        $mock->shouldReceive('getSheetData')->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Old', 'Edited'],
        ]);

        $this->artisan('translations:push', ['lang' => 'en'])
            ->assertSuccessful();

        $localeDir = $this->backupPath().'/en';
        $this->assertTrue(File::isDirectory($localeDir));
        $files = File::files($localeDir);
        $this->assertCount(1, $files);

        $payload = json_decode(File::get($files[0]->getPathname()), true);
        $this->assertSame('en', $payload['locale']);
        $this->assertContains(['auth.failed', 'Old', 'Edited'], $payload['rows']);
    }

    /** @test */
    public function it_creates_gitignore_in_backup_root_on_first_run(): void
    {
        $mock = $this->mockSheets();
        $mock->shouldReceive('getSheetData')->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Old', ''],
        ]);

        $this->artisan('translations:push', ['lang' => 'en'])
            ->assertSuccessful();

        $gitignore = $this->backupPath().'/.gitignore';
        $this->assertFileExists($gitignore);
        $this->assertSame("*\n!.gitignore\n", File::get($gitignore));
    }

    /** @test */
    public function it_creates_sheet_tab_named_with_prefix(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('createSheetIfMissing')->once()->with('Translations - en')->andReturn(true);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')->andReturn(true);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->expectsOutputToContain('Created new sheet tab: Translations - en')
            ->assertSuccessful();
    }

    /** @test */
    public function it_does_not_announce_when_sheet_already_exists(): void
    {
        $mock = $this->mockSheets();
        $mock->shouldReceive('createSheetIfMissing')->andReturn(false);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->doesntExpectOutputToContain('Created new sheet tab')
            ->assertSuccessful();
    }

    /** @test */
    public function it_pushes_all_locales_when_no_arg_given(): void
    {
        $this->createFrenchTranslations();

        $captured = [];
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('createSheetIfMissing')->with('Translations - en')->once()->andReturn(true);
        $mock->shouldReceive('createSheetIfMissing')->with('Translations - fr')->once()->andReturn(true);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')
            ->andReturnUsing(function ($sheet, $range, $rows) use (&$captured) {
                $captured[$sheet] = $rows;
                return true;
            });
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('=== en ===')
            ->expectsOutputToContain('=== fr ===')
            ->assertSuccessful();

        $this->assertArrayHasKey('Translations - en', $captured);
        $this->assertArrayHasKey('Translations - fr', $captured);

        // English headers
        $this->assertSame(['Key', 'Original Value', 'Updated Value'], $captured['Translations - en'][0]);
        // French headers
        $this->assertSame(['Key', 'English (Source)', 'Translation'], $captured['Translations - fr'][0]);

        // French sheet should have English in column B
        $frRows = array_slice($captured['Translations - fr'], 1);
        $authFailed = collect($frRows)->firstWhere(0, 'auth.failed');
        $this->assertNotNull($authFailed);
        $this->assertSame('These credentials do not match our records.', $authFailed[1]);
        $this->assertSame('Identifiants invalides.', $authFailed[2]);
    }

    /** @test */
    public function it_skips_vendor_and_dot_directories_when_discovering_locales(): void
    {
        File::ensureDirectoryExists(lang_path('vendor/some-package/en'));
        File::ensureDirectoryExists(lang_path('.cache'));

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldNotReceive('createSheetIfMissing')->with('Translations - vendor');
        $mock->shouldNotReceive('createSheetIfMissing')->with('Translations - .cache');
        $mock->shouldReceive('createSheetIfMissing')->with('Translations - en')->andReturn(false);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')->andReturn(true);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->assertSuccessful();
    }

    /** @test */
    public function it_continues_after_per_locale_failure(): void
    {
        $this->createFrenchTranslations();

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('createSheetIfMissing')->andReturn(false);
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        // English push succeeds; French push throws on updateSheetData
        $mock->shouldReceive('updateSheetData')->with('Translations - en', Mockery::any(), Mockery::any())->once()->andReturn(true);
        $mock->shouldReceive('updateSheetData')->with('Translations - fr', Mockery::any(), Mockery::any())->once()->andThrow(new \RuntimeException('boom'));

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('Failed locales: fr')
            ->assertFailed();
    }

    /** @test */
    public function it_pushes_source_locale_first(): void
    {
        $this->createFrenchTranslations();

        $order = [];
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('createSheetIfMissing')->andReturnUsing(function ($name) use (&$order) {
            $order[] = $name;
            return false;
        });
        $mock->shouldReceive('getSheetData')->andReturn([]);
        $mock->shouldReceive('updateSheetData')->andReturn(true);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->assertSuccessful();

        $this->assertSame(['Translations - en', 'Translations - fr'], $order);
    }

    /** @test */
    public function it_logs_review_count_when_english_source_changed_for_non_source_locale(): void
    {
        $this->createFrenchTranslations();

        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('createSheetIfMissing')->andReturn(false);
        $mock->shouldReceive('getSheetData')->andReturnUsing(function ($sheet, $range) {
            if ($sheet === 'Translations - fr') {
                return [
                    ['Key', 'English (Source)', 'Translation'],
                    ['auth.failed', 'OUTDATED ENGLISH', 'Identifiants invalides.'],
                ];
            }
            return [];
        });
        $mock->shouldReceive('updateSheetData')->andReturn(true);
        $mock->shouldReceive('getSheetId')->andReturn(123);
        $mock->shouldReceive('getSpreadsheetUrl')->andReturn('https://example.test/edit');

        $this->app->instance(GoogleSheetsService::class, $mock);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('had their English source change')
            ->assertSuccessful();
    }

    /** @test */
    public function it_skips_backup_when_disabled_via_env(): void
    {
        config()->set('laravel-translation.backup.path', false);

        $mock = $this->mockSheets();
        $mock->shouldReceive('getSheetData')->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Old', ''],
        ]);

        $this->artisan('translations:push', ['lang' => 'en'])
            ->expectsOutputToContain('Backups disabled via TRANSLATION_BACKUP_PATH')
            ->assertSuccessful();

        $this->assertFalse(File::isDirectory($this->backupPath()));
    }

    /** @test */
    public function it_skips_backup_when_path_is_null(): void
    {
        config()->set('laravel-translation.backup.path', null);

        $mock = $this->mockSheets();
        $mock->shouldReceive('getSheetData')->andReturn([
            ['Key', 'Original Value', 'Updated Value'],
            ['auth.failed', 'Old', ''],
        ]);

        $this->artisan('translations:push', ['lang' => 'en'])
            ->expectsOutputToContain('Backups disabled via TRANSLATION_BACKUP_PATH')
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

        $captured = null;
        $mock = $this->mockSheets();
        $mock->shouldReceive('updateSheetData')
            ->with(Mockery::any(), Mockery::any(), Mockery::capture($captured))
            ->andReturn(true);

        $this->artisan('translations:push', ['lang' => 'en', '--no-backup' => true])
            ->assertSuccessful();

        $keys = array_column($captured, 0);
        $this->assertContains('nested.level1.level2.level3', $keys);
    }
}
