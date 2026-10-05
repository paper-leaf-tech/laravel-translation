<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TranslationConventionsTest extends TestCase
{
    #[Test]
    public function the_source_locale_defaults_to_english(): void
    {
        $this->assertSame('en', TranslationConventions::sourceLocale());
        $this->assertTrue(TranslationConventions::isSourceLocale('en'));
        $this->assertFalse(TranslationConventions::isSourceLocale('fr'));
    }

    #[Test]
    public function it_reads_the_source_locale_from_config(): void
    {
        config()->set('laravel-translation.source_locale', 'en_CA');

        $this->assertSame('en_CA', TranslationConventions::sourceLocale());
        $this->assertTrue(TranslationConventions::isSourceLocale('en_CA'));
        $this->assertFalse(TranslationConventions::isSourceLocale('en'));
    }
}
