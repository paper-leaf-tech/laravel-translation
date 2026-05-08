<?php

namespace PaperleafTech\LaravelTranslation\Support;

final class TranslationConventions
{
    public const SOURCE_LOCALE = 'en';

    public const SHEET_NAME_PREFIX = 'Translations - ';

    public const HEADER_KEY = 'Key';

    public const HEADER_ORIGINAL = 'Original Value';

    public const HEADER_UPDATED = 'Updated Value';

    public const HEADER_SOURCE = 'English (Source)';

    public const HEADER_TRANSLATION = 'Translation';

    public static function sheetNameFor(string $locale): string
    {
        return self::SHEET_NAME_PREFIX.$locale;
    }

    public static function isSourceLocale(string $locale): bool
    {
        return $locale === self::SOURCE_LOCALE;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public static function headersFor(string $locale): array
    {
        if (self::isSourceLocale($locale)) {
            return [self::HEADER_KEY, self::HEADER_ORIGINAL, self::HEADER_UPDATED];
        }

        return [self::HEADER_KEY, self::HEADER_SOURCE, self::HEADER_TRANSLATION];
    }
}
