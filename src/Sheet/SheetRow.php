<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

/**
 * One row of the translation tab: a line's group and key, the source text
 * as of the last push (default), the text per locale, and the cells of any
 * column the package does not manage.
 */
final readonly class SheetRow
{
    /**
     * @param  array<string, string>  $values  locale => text, source locale included
     * @param  array<string, string>  $extras  unrecognised header => text
     */
    public function __construct(
        public string $group,
        public string $key,
        public string $default = '',
        public array $values = [],
        public array $extras = [],
    ) {}

    public static function idFor(string $group, string $key): string
    {
        return $group."\0".$key;
    }

    public function id(): string
    {
        return self::idFor($this->group, $this->key);
    }

    /**
     * The row as people read it in command output, e.g. `auth.failed`.
     */
    public function label(): string
    {
        return "{$this->group}.{$this->key}";
    }

    public function value(string $locale): string
    {
        return $this->values[$locale] ?? '';
    }
}
