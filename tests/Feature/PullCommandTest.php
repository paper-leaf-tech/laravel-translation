<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PullCommandTest extends TestCase
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
            'en/auth.php' => "<?php\n\nreturn [\n    // Shown when sign-in fails.\n    'failed' => '".self::FAILED."',\n    'greeting' => 'Hello, :name',\n];\n",
            'fr/auth.php' => "<?php\n\nreturn [\n    // Shown when sign-in fails.\n    'failed' => 'Ancienne valeur.',\n    'greeting' => '',\n];\n",
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function seedSheet(array $rows, array $header = self::HEADER): void
    {
        $this->sheets->seed('Translations', [$header, ...$rows]);
    }

    private function lines(string $locale, string $group = 'auth'): array
    {
        return require lang_path("{$locale}/{$group}.php");
    }

    #[Test]
    public function it_writes_each_locale_column_into_its_lang_files(): void
    {
        $this->seedSheet([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'],
            ['greeting', 'auth', 'Hello, :name', 'Hi, :name', 'Bonjour, :name'],
        ]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('Updated 2 key(s) in')
            ->assertSuccessful();

        $this->assertSame('Identifiants invalides.', $this->lines('fr')['failed']);
        $this->assertSame('Bonjour, :name', $this->lines('fr')['greeting']);
        $this->assertSame('Hi, :name', $this->lines('en')['greeting']);
    }

    #[Test]
    public function it_keeps_comments_in_the_files_it_updates(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertStringContainsString('// Shown when sign-in fails.', File::get(lang_path('fr/auth.php')));
    }

    #[Test]
    public function it_never_writes_a_blank_cell(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, '', '']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame(self::FAILED, $this->lines('en')['failed']);
        $this->assertSame('Ancienne valeur.', $this->lines('fr')['failed']);
    }

    #[Test]
    public function it_pulls_into_files_in_subdirectories(): void
    {
        $this->writeLangFiles([
            'en/resources/schools.php' => ['title' => 'Schools', 'form' => ['name' => 'Name']],
            'fr/resources/schools.php' => ['title' => '', 'form' => ['name' => '']],
        ]);
        $this->seedSheet([
            ['form.name', 'resources/schools', 'Name', 'Name', 'Nom'],
            ['title', 'resources/schools', 'Schools', 'Schools', 'Écoles'],
        ]);

        $this->artisan('translations:pull', ['locale' => 'fr'])->assertSuccessful();

        $this->assertSame('Écoles', $this->lines('fr', 'resources/schools')['title']);
        $this->assertSame('Nom', $this->lines('fr', 'resources/schools')['form']['name']);
    }

    #[Test]
    public function it_rejects_values_whose_placeholders_differ_from_the_code_and_fails(): void
    {
        $this->seedSheet([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'],
            ['greeting', 'auth', 'Hello, :name', 'Hello, :name', 'Bonjour'],
        ]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('1 value(s) rejected')
            ->expectsOutputToContain('auth.greeting')
            ->assertFailed();

        $this->assertSame('Identifiants invalides.', $this->lines('fr')['failed']);
        $this->assertSame('', $this->lines('fr')['greeting']);
    }

    #[Test]
    public function it_rejects_an_english_edit_that_drops_a_placeholder(): void
    {
        $this->seedSheet([['greeting', 'auth', 'Hello, :name', 'Hello there', '']]);

        $this->artisan('translations:pull')->assertFailed();

        $this->assertSame('Hello, :name', $this->lines('en')['greeting']);
    }

    #[Test]
    public function it_accepts_placeholders_whose_capitalisation_differs(): void
    {
        $this->seedSheet([['greeting', 'auth', 'Hello, :name', 'Hello, :name', ':Name, bonjour']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame(':Name, bonjour', $this->lines('fr')['greeting']);
    }

    #[Test]
    public function it_skips_the_check_when_code_has_no_source_line(): void
    {
        $this->writeLangFiles(['fr/extra.php' => ['only' => '']]);
        $this->seedSheet([['only', 'extra', '', '', 'Seulement :thing']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame('Seulement :thing', $this->lines('fr', 'extra')['only']);
    }

    #[Test]
    public function it_pulls_only_the_named_locale(): void
    {
        $this->seedSheet([['greeting', 'auth', 'Hello, :name', 'Hi, :name', 'Bonjour, :name']]);

        $this->artisan('translations:pull', ['locale' => 'fr'])->assertSuccessful();

        $this->assertSame('Bonjour, :name', $this->lines('fr')['greeting']);
        $this->assertSame('Hello, :name', $this->lines('en')['greeting']);
    }

    #[Test]
    public function it_fails_for_a_locale_without_a_column(): void
    {
        $this->seedSheet([]);

        $this->artisan('translations:pull', ['locale' => 'es'])
            ->expectsOutputToContain('no "es" column')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_the_tab_is_missing(): void
    {
        $this->artisan('translations:pull')
            ->expectsOutputToContain('Run translations:push first')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_a_required_column_is_missing(): void
    {
        $this->seedSheet([], ['key', 'group', 'en', 'fr']);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('missing the column(s): default')
            ->assertFailed();
    }

    #[Test]
    public function it_writes_nothing_on_a_dry_run(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.']]);
        $before = File::get(lang_path('fr/auth.php'));

        $this->artisan('translations:pull', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('1 value(s) to apply')
            ->assertSuccessful();

        $this->assertSame($before, File::get(lang_path('fr/auth.php')));
    }

    #[Test]
    public function it_ignores_json_vendor_and_keyless_rows(): void
    {
        $this->seedSheet([
            ['Hello!', '*', 'Hello!', 'Hello!', 'Bonjour !'],
            ['saved', 'filament::actions', 'Saved', 'Saved', 'Enregistré'],
            ['', 'auth', '', '', 'Orphelin'],
        ]);
        $before = File::get(lang_path('fr/auth.php'));

        $this->artisan('translations:pull')
            ->doesntExpectOutputToContain('not found')
            ->assertSuccessful();

        $this->assertSame($before, File::get(lang_path('fr/auth.php')));
    }

    #[Test]
    public function it_warns_about_keys_missing_from_code(): void
    {
        $this->seedSheet([['captcha', 'auth', '', '', 'Captcha invalide.']]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('Skipped 1 key(s) not in')
            ->expectsOutputToContain('- auth.captcha')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_when_the_file_is_missing(): void
    {
        $this->seedSheet([['heading', 'welcome', 'Welcome', 'Welcome', 'Bienvenue']]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('welcome.php not found')
            ->assertSuccessful();

        $this->assertFileDoesNotExist(lang_path('fr/welcome.php'));
    }

    #[Test]
    public function it_keeps_newlines_and_surrounding_spaces_in_values(): void
    {
        $this->seedSheet([['failed', 'auth', self::FAILED, self::FAILED, " Ligne un\nLigne deux\u{00A0}"]]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame(" Ligne un\nLigne deux\u{00A0}", $this->lines('fr')['failed']);
    }

    #[Test]
    public function it_uses_the_first_of_duplicate_rows_and_warns(): void
    {
        $this->seedSheet([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Premier'],
            ['failed', 'auth', self::FAILED, self::FAILED, 'Second'],
        ]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('duplicate row(s)')
            ->assertSuccessful();

        $this->assertSame('Premier', $this->lines('fr')['failed']);
    }
}
