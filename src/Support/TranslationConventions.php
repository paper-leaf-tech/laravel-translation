<?php

namespace PaperleafTech\LaravelTranslation\Support;

/**
 * The source locale every other locale is translated from.
 */
final class TranslationConventions
{
    public static function sourceLocale(): string
    {
        return (string) config('laravel-translation.source_locale', 'en');
    }

    public static function isSourceLocale(string $locale): bool
    {
        return $locale === self::sourceLocale();
    }
}
