<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TranslationCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
    }

    #[Test]
    public function app_groups_are_the_php_files_only(): void
    {
        $this->writeLangFiles([
            'en/auth.php' => ['failed' => 'Failed', 'nested' => ['save' => 'Save'], 'count' => 3],
            'en/resources/schools.php' => ['title' => 'Schools'],
            'en.json' => ['Hello!' => 'Hello!'],
            'vendor/demo/fr/messages.php' => ['saved' => 'Enregistré'],
        ]);

        $groups = $this->app->make(TranslationCatalogue::class)->appGroups('en');

        $this->assertSame([
            'auth' => ['failed' => 'Failed', 'nested.save' => 'Save'],
            'resources/schools' => ['title' => 'Schools'],
        ], $groups);
    }

    #[Test]
    public function source_json_lines_fall_back_to_the_other_locales_keys(): void
    {
        $this->writeLangFiles([
            'fr.json' => ['Hello, :name!' => 'Bonjour, :name !', 'Regards,' => 'Cordialement,'],
            'es.json' => ['Hello, :name!' => '¡Hola, :name!'],
        ]);

        $lines = $this->app->make(TranslationCatalogue::class)->lines('en');

        $this->assertSame(['Hello, :name!' => 'Hello, :name!', 'Regards,' => 'Regards,'], $lines['*']);
    }

    #[Test]
    public function a_source_json_file_wins_over_the_fallback(): void
    {
        $this->writeLangFiles([
            'en.json' => ['Hello!' => 'Hi!'],
            'fr.json' => ['Hello!' => 'Salut !', 'Other' => 'Autre'],
        ]);

        $lines = $this->app->make(TranslationCatalogue::class)->lines('en');

        $this->assertSame(['Hello!' => 'Hi!'], $lines['*']);
    }

    #[Test]
    public function it_names_the_file_when_json_is_malformed(): void
    {
        $this->writeLangFiles(['fr.json' => '{"Hello!": "Bonjour",}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(lang_path('fr.json'));

        $this->app->make(TranslationCatalogue::class)->lines('fr');
    }
}
