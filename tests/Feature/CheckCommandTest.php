<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CheckCommandTest extends TestCase
{
    protected string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
        $this->packagePath = $this->temporaryDirectory('package');
    }

    #[Test]
    public function it_passes_when_every_locale_matches_the_source(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['greeting' => 'Hello, :name', 'nested' => ['save' => 'Save']],
            'fr/common.php' => ['greeting' => 'Bonjour, :name', 'nested' => ['save' => 'Enregistrer']],
            'en/resources/schools.php' => ['title' => 'Schools'],
            'fr/resources/schools.php' => ['title' => 'Écoles'],
            'en.json' => ['Hello!' => 'Hello!'],
            'fr.json' => ['Hello!' => 'Bonjour!'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('fr matches en: 4 keys in 3 groups.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_accepts_a_placeholder_whose_capitalisation_differs(): void
    {
        $this->writeLangFiles([
            'en/access.php' => ['granted' => 'You are now :role.'],
            'fr/access.php' => ['granted' => ':Role vous a été attribué.'],
        ]);

        $this->artisan('translations:check')->assertSuccessful();
    }

    #[Test]
    public function it_fails_when_a_key_is_missing_from_the_target(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save', 'cancel' => 'Cancel'],
            'en/welcome.php' => ['heading' => 'Welcome'],
            'fr/common.php' => ['save' => 'Enregistrer'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Missing from fr')
            ->expectsOutputToContain('2 translation problem(s) found.')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_a_key_is_only_in_the_target(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save'],
            'fr/common.php' => ['save' => 'Enregistrer', 'stale' => 'Ancien'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Not in en')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_a_json_line_is_missing_from_the_target(): void
    {
        $this->writeLangFiles([
            'en.json' => ['Hello!' => 'Hello!', 'Regards,' => 'Regards,'],
            'fr.json' => ['Hello!' => 'Bonjour!'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Missing from fr')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_a_line_drops_or_renames_a_placeholder(): void
    {
        foreach ([':name occupe déjà ce rôle ici.', ':nom occupe déjà le rôle :role ici.'] as $french) {
            $this->writeLangFiles([
                'en/access.php' => ['already-holds' => ':name already holds :role here.'],
                'fr/access.php' => ['already-holds' => $french],
            ]);

            $this->artisan('translations:check')
                ->expectsOutputToContain('Placeholders differ: en has [name, role], fr has')
                ->assertFailed();
        }
    }

    #[Test]
    public function it_reports_missing_keys_without_failing_when_allowed(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save', 'cancel' => 'Cancel'],
            'fr/common.php' => ['save' => 'Enregistrer'],
        ]);

        $this->artisan('translations:check', ['--allow-missing' => true])
            ->expectsOutputToContain('Missing from fr')
            ->assertSuccessful();
    }

    #[Test]
    public function it_still_fails_on_placeholders_when_missing_keys_are_allowed(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['greeting' => 'Hello, :name'],
            'fr/common.php' => ['greeting' => 'Bonjour'],
        ]);

        $this->artisan('translations:check', ['--allow-missing' => true])->assertFailed();
    }

    #[Test]
    public function it_checks_every_target_locale_or_only_the_one_given(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save'],
            'fr/common.php' => ['save' => 'Enregistrer'],
            'es/common.php' => [],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Missing from es')
            ->assertFailed();

        $this->artisan('translations:check', ['lang' => 'fr'])->assertSuccessful();
    }

    #[Test]
    public function it_checks_a_package_override_merged_over_the_package_lines(): void
    {
        $this->app['translator']->addNamespace('demo', $this->packagePath);

        $this->writeLangFiles([
            'en/messages.php' => ['saved' => 'Saved', 'deleted' => 'Deleted :count'],
            'fr/messages.php' => ['saved' => 'Sauvegardé', 'deleted' => 'Supprimé :count'],
        ], $this->packagePath);

        // A partial override is valid: Laravel merges it over the package's own fr.
        $this->writeLangFiles(['vendor/demo/fr/messages.php' => ['saved' => 'Enregistré']]);

        $this->artisan('translations:check')->assertSuccessful();

        $this->writeLangFiles(['vendor/demo/fr/messages.php' => ['deleted' => 'Supprimé']]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('demo::messages | deleted | Placeholders differ: en has [count], fr has []')
            ->assertFailed();
    }

    #[Test]
    public function it_ignores_stale_keys_a_package_ships_only_in_the_target(): void
    {
        $this->app['translator']->addNamespace('demo', $this->packagePath);

        // The package dropped `legacy` from its English but not its French; the app cannot fix that.
        $this->writeLangFiles([
            'en/messages.php' => ['saved' => 'Saved'],
            'fr/messages.php' => ['saved' => 'Sauvegardé', 'legacy' => 'Ancien'],
        ], $this->packagePath);
        $this->writeLangFiles(['vendor/demo/fr/messages.php' => ['saved' => 'Enregistré']]);

        $this->artisan('translations:check')->assertSuccessful();
    }

    #[Test]
    public function it_ignores_packages_without_an_override_for_the_locale(): void
    {
        $this->app['translator']->addNamespace('demo', $this->packagePath);

        $this->writeLangFiles([
            'en/messages.php' => ['saved' => 'Saved'],
            'fr/messages.php' => [],
        ], $this->packagePath);
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save'],
            'fr/common.php' => ['save' => 'Enregistrer'],
        ]);

        $this->artisan('translations:check')->assertSuccessful();
    }

    #[Test]
    public function it_compares_against_the_configured_source_locale(): void
    {
        config()->set('laravel-translation.source_locale', 'en_CA');

        $this->writeLangFiles([
            'en_CA/common.php' => ['save' => 'Save', 'cancel' => 'Cancel'],
            'fr_CA/common.php' => ['save' => 'Enregistrer'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Missing from fr_CA')
            ->doesntExpectOutputToContain('=== en_CA ===')
            ->assertFailed();
    }

    #[Test]
    public function it_checks_json_lines_without_a_source_json_file(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save'],
            'fr/common.php' => ['save' => 'Enregistrer'],
            'fr.json' => ['Hello, :name!' => 'Bonjour !'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Placeholders differ: en has [name], fr has []')
            ->doesntExpectOutputToContain('Not in en')
            ->assertFailed();
    }
}
