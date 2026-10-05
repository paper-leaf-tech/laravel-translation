<?php

namespace PaperleafTech\LaravelTranslation\Support;

/**
 * The :placeholders in a translation line. Laravel swaps `:name`, `:Name` and
 * `:NAME` for the same replacement in different cases, so a translation may
 * capitalise a placeholder differently from the source and still be correct;
 * comparison is therefore case-insensitive.
 */
final class Placeholders
{
    /**
     * A colon followed by a name, not preceded by a letter or digit, so times
     * (10:30), URLs (https://) and a typographic colon before a space are not
     * mistaken for placeholders.
     */
    private const PATTERN = '/(?<![\p{L}\p{N}_]):([a-zA-Z_][a-zA-Z0-9_]*)/u';

    /**
     * @return list<string> lower-cased, unique and sorted
     */
    public static function in(string $line): array
    {
        preg_match_all(self::PATTERN, $line, $matches);

        $names = array_values(array_unique(array_map('strtolower', $matches[1])));
        sort($names);

        return $names;
    }

    public static function match(string $first, string $second): bool
    {
        return self::in($first) === self::in($second);
    }
}
