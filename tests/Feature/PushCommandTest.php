<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Events\TranslationsPushed;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends TestCase
{
    private const HEADER = ['key', 'group', 'default', 'en', 'fr'];

    private const FAILED = 'These credentials do not match our records.';

    private FakeGoogleSheetsService $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
        $this->sheets = $this->fakeSheets();
        $this->writeLangFiles([
            'en/auth.php' => ['failed' => self::FAILED, 'throttle' => 'Too many attempts.'],
            'en/resources/schools.php' => ['title' => 'Schools'],
            'fr/auth.php' => ['failed' => 'Identifiants invalides.'],
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function seedSheet(array $rows, array $header = self::HEADER): void
    {
        $this->sheets->seed('Translations', [$header, ...$rows]);
    }

    private function rows(): array
    {
        return $this->sheets->rows('Translations');
    }

    #[Test]
    public function it_builds_the_sheet_from_code_on_the_first_push(): void
    {
        $this->artisan('translations:push')
            ->expectsOutputToContain('3 new key(s) added')
            ->assertSuccessful();

        $this->assertSame([
            self::HEADER,
            ['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.'],
            ['title', 'resources/schools', 'Schools', 'Schools'],
        ], $this->rows());
    }

    #[Test]
    public function it_fails_without_the_source_locale_directory(): void
    {
        File::deleteDirectory(lang_path('en'));

        $this->artisan('translations:push')->assertFailed();

        $this->assertArrayNotHasKey('Translations', $this->sheets->tabs);
    }

    #[Test]
    public function it_keeps_sheet_edits_translations_and_extra_columns(): void
    {
        $this->seedSheet([
            ['failed', 'auth', self::FAILED, 'Those credentials are wrong.', 'Mauvais identifiants.', 'reviewed', 'Shorter'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.', '', '', ''],
            ['title', 'resources/schools', 'Schools', 'Schools', '', 'draft', ''],
        ], [...self::HEADER, 'status', 'notes']);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([
            [...self::HEADER, 'status', 'notes'],
            ['failed', 'auth', self::FAILED, 'Those credentials are wrong.', 'Mauvais identifiants.', 'reviewed', 'Shorter'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.'],
            ['title', 'resources/schools', 'Schools', 'Schools', '', 'draft'],
        ], $this->rows());
    }

    #[Test]
    public function it_updates_english_changed_in_code_and_flags_translations_for_review(): void
    {
        $this->seedSheet([['failed', 'auth', 'Old text', 'Old text', 'Ancien texte']]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('need their translations reviewed')
            ->expectsOutputToContain('- auth.failed')
            ->assertSuccessful();

        $this->assertSame(['failed', 'auth', self::FAILED, self::FAILED, 'Ancien texte'], $this->rows()[1]);
    }

    #[Test]
    public function it_keeps_the_sheet_english_when_both_sides_changed(): void
    {
        $this->seedSheet([['failed', 'auth', 'Old text', 'Sheet text', '']]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('changed in both code and the sheet')
            ->assertSuccessful();

        $this->assertSame(['failed', 'auth', self::FAILED, 'Sheet text', 'Identifiants invalides.'], $this->rows()[1]);
    }

    #[Test]
    public function it_fills_blank_cells_from_code(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, '']]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('1 empty cell(s) filled from code')
            ->assertSuccessful();

        $this->assertSame('Identifiants invalides.', $this->rows()[1][4]);
    }

    #[Test]
    public function it_drops_rows_for_keys_removed_from_code(): void
    {
        $this->seedSheet([
            ['failed', 'auth', self::FAILED, self::FAILED, ''],
            ['gone', 'auth', 'Gone', 'Gone', 'Parti'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.', ''],
            ['title', 'resources/schools', 'Schools', 'Schools', ''],
        ]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('- auth.gone')
            ->assertSuccessful();

        $this->assertCount(4, $this->rows());
        $this->assertNotContains('gone', array_column($this->rows(), 0));
    }

    #[Test]
    public function it_adds_a_column_for_a_new_locale_after_the_existing_ones(): void
    {
        $this->writeLangFiles(['es/auth.php' => ['failed' => 'Credenciales inválidas.']]);
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, '', 'note']], [...self::HEADER, 'notes']);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([...self::HEADER, 'es', 'notes'], $this->rows()[0]);
        $this->assertSame(['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.', 'Credenciales inválidas.', 'note'], $this->rows()[1]);
    }

    #[Test]
    public function it_keeps_the_column_of_a_locale_whose_directory_was_removed(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, '', 'Ungültige Anmeldedaten.']], [...self::HEADER, 'de']);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([...self::HEADER, 'de'], $this->rows()[0]);
        $this->assertSame('Ungültige Anmeldedaten.', $this->rows()[1][5]);
    }

    #[Test]
    public function it_backs_up_the_sheet_before_writing(): void
    {
        $this->seedSheet([['failed', 'auth', 'Old', 'Old', 'Ancien']]);
        $before = $this->rows();

        $this->artisan('translations:push')
            ->expectsOutputToContain('Backup saved')
            ->assertSuccessful();

        $backups = File::files($this->backupPath());
        $this->assertCount(1, $backups);
        $this->assertSame($before, json_decode(File::get($backups[0]->getPathname()), true)['rows']);
    }

    #[Test]
    public function it_skips_the_backup_when_asked_or_disabled(): void
    {
        $this->seedSheet([['failed', 'auth', 'Old', 'Old', '']]);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->backupPath());

        config()->set('laravel-translation.backup.path', false);
        $this->artisan('translations:push')
            ->expectsOutputToContain('Backups disabled')
            ->assertSuccessful();
    }

    #[Test]
    public function it_writes_nothing_on_a_dry_run(): void
    {
        $this->artisan('translations:push', ['--dry-run' => true])
            ->expectsOutputToContain('3 new key(s) added')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertArrayNotHasKey('Translations', $this->sheets->tabs);

        $this->seedSheet([['failed', 'auth', 'Old', 'Old', '']]);
        $before = $this->rows();

        $this->artisan('translations:push', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->rows());
        $this->assertDirectoryDoesNotExist($this->backupPath());
    }

    #[Test]
    public function it_rebuilds_from_code_with_fresh(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, 'Sheet text', 'Feuille', 'note']], [...self::HEADER, 'notes']);

        $this->artisan('translations:push', ['--fresh' => true, '--no-backup' => true])->assertSuccessful();

        $this->assertSame(self::HEADER, $this->rows()[0]);
        $this->assertSame(['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'], $this->rows()[1]);
    }

    #[Test]
    public function it_pushes_into_an_existing_tab_of_the_default_size_without_growing_it(): void
    {
        $this->seedSheet([['old', 'auth', 'Old', 'Old', 'Vieux']]);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([1000, 26], $this->sheets->grids['Translations']);
        $this->assertSame([], $this->sheets->batches);
        $this->assertCount(4, $this->rows());
    }

    #[Test]
    public function it_stops_when_a_required_column_is_missing(): void
    {
        $this->sheets->seed('Translations', [['key', 'group', 'en', 'fr'], ['failed', 'auth', 'x', 'y']]);

        $this->artisan('translations:push')
            ->expectsOutputToContain('missing the column(s): default')
            ->assertFailed();

        $this->assertSame([['key', 'group', 'en', 'fr'], ['failed', 'auth', 'x', 'y']], $this->rows());
    }

    #[Test]
    public function it_dispatches_translations_pushed_after_writing(): void
    {
        Event::fake([TranslationsPushed::class]);

        $this->artisan('translations:push', ['--dry-run' => true])->assertSuccessful();
        Event::assertNotDispatched(TranslationsPushed::class);

        $this->artisan('translations:push')->assertSuccessful();
        Event::assertDispatched(TranslationsPushed::class, fn (TranslationsPushed $event): bool => $event->sheetName === 'Translations'
            && $event->sheetId === 1000
            && $event->headers === self::HEADER
            && count($event->rows) === 3);
    }

    #[Test]
    public function a_failing_listener_does_not_mask_a_successful_push(): void
    {
        Event::listen(TranslationsPushed::class, fn () => throw new RuntimeException('Listener broke.'));
        $output = new BufferedOutput;
        $thrown = null;

        try {
            Artisan::call('translations:push', [], $output);
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertSame('Listener broke.', $thrown, 'The listener exception should propagate as itself.');

        $printed = $output->fetch();
        $this->assertStringContainsString('✓ Pushed 3 row(s) to "Translations".', $printed);
        $this->assertStringContainsString('View sheet: ', $printed);
        $this->assertCount(4, $this->rows());
    }

    #[Test]
    public function it_formats_the_sheet_only_when_enabled(): void
    {
        $this->artisan('translations:push')->assertSuccessful();
        $this->assertSame([], $this->sheets->batches);

        config()->set('laravel-translation.format', true);
        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertCount(1, $this->sheets->batches);
        $this->assertNotEmpty(array_filter($this->sheets->batches[0], fn (array $request): bool => isset($request['addProtectedRange'])));
    }

    #[Test]
    public function it_warns_about_duplicate_rows_and_keeps_the_first(): void
    {
        $this->seedSheet([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Premier'],
            ['failed', 'auth', self::FAILED, self::FAILED, 'Second'],
        ]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('duplicate row(s)')
            ->assertSuccessful();

        $this->assertSame('Premier', $this->rows()[1][4]);
        $this->assertCount(4, $this->rows());
    }

    #[Test]
    public function it_keeps_newlines_and_spaces_in_cells(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, "Ligne un\nLigne deux\u{00A0}"]]);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame("Ligne un\nLigne deux\u{00A0}", $this->rows()[1][4]);
    }
}
