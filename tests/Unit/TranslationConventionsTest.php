<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TranslationConventionsTest extends TestCase
{
    #[Test]
    public function it_builds_sheet_name_for_simple_locale(): void
    {
        $this->assertSame('Translations - en', TranslationConventions::sheetNameFor('en'));
        $this->assertSame('Translations - fr-CA', TranslationConventions::sheetNameFor('fr-CA'));
    }

    #[Test]
    public function it_recognizes_the_source_locale(): void
    {
        $this->assertTrue(TranslationConventions::isSourceLocale('en'));
        $this->assertFalse(TranslationConventions::isSourceLocale('fr'));
        $this->assertFalse(TranslationConventions::isSourceLocale('en-US'));
    }

    #[Test]
    public function it_returns_source_headers_for_source_locale(): void
    {
        $this->assertSame(['Key', 'Original Value', 'Updated Value'], TranslationConventions::headersFor('en'));
    }

    #[Test]
    public function it_returns_translation_headers_for_non_source_locale(): void
    {
        $this->assertSame(['Key', 'English (Source)', 'Translation'], TranslationConventions::headersFor('fr'));
    }
}
