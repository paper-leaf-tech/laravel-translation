<?php

namespace PaperleafTech\LaravelTranslation\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\Translator;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Every translation line an application renders for one locale, by group.
 *
 * A group is named the way `__()` addresses it: `auth` or `resources/schools`
 * for the app's PHP files, `*` for its JSON lines (lang/{locale}.json, keyed
 * by their source text), and `filament-panels::layout` for a package's file.
 *
 * A package's lines are its own files with any override published under
 * lang/vendor/{namespace}/{locale} merged over them, which is what Laravel
 * loads at runtime, so a partial override is read as complete.
 */
class TranslationCatalogue
{
    public const JSON_GROUP = '*';

    public function __construct(protected Translator $translator) {}

    /**
     * @param  list<string>  $vendorNamespaces  packages whose lines to include
     * @return array<string, array<string, string>> group => [dotted key => line], both sorted
     */
    public function lines(string $locale, array $vendorNamespaces = []): array
    {
        $groups = $this->appGroups($locale);

        $json = $this->jsonLines($locale);
        if ($json !== null) {
            $groups[self::JSON_GROUP] = $json;
        }

        foreach ($vendorNamespaces as $namespace) {
            foreach ($this->packageFiles($namespace, $locale) as $file => $contents) {
                $groups["{$namespace}::{$file}"] = $this->flatten($contents);
            }
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * The app's own PHP files: what push and pull sync to the sheet.
     *
     * @return array<string, array<string, string>> group => [dotted key => line], both sorted
     */
    public function appGroups(string $locale): array
    {
        $groups = array_map(fn (array $contents): array => $this->flatten($contents), $this->readDirectory(lang_path($locale)));
        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * lang/{locale}.json. Laravel renders a JSON key that has no source-locale
     * line as the key itself, so without lang/{source}.json the source lines
     * are the other locales' keys mapped to themselves.
     *
     * @return array<string, string>|null null when the locale has no JSON lines
     */
    protected function jsonLines(string $locale): ?array
    {
        $path = lang_path("{$locale}.json");

        if (File::exists($path)) {
            return $this->flatten($this->readJson($path), dotted: false);
        }

        if (! TranslationConventions::isSourceLocale($locale)) {
            return null;
        }

        $keys = [];
        foreach (File::glob(lang_path('*.json')) as $other) {
            foreach (array_keys($this->readJson($other)) as $key) {
                $keys[(string) $key] = (string) $key;
            }
        }

        return $keys === [] ? null : $this->flatten($keys, dotted: false);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readJson(string $path): array
    {
        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded)) {
            $reason = json_last_error() === JSON_ERROR_NONE ? 'expected a JSON object' : json_last_error_msg();

            throw new RuntimeException("Could not read {$path}: {$reason}.");
        }

        return $decoded;
    }

    /**
     * Packages with an override published for this locale: the ones the app
     * has chosen to correct, and so the ones worth checking.
     *
     * @return list<string>
     */
    public function vendorNamespaces(string $locale): array
    {
        $vendorPath = lang_path('vendor');

        if (! File::isDirectory($vendorPath)) {
            return [];
        }

        return collect(File::directories($vendorPath))
            ->filter(fn (string $directory): bool => File::isDirectory($directory.DIRECTORY_SEPARATOR.$locale))
            ->map(fn (string $directory): string => basename($directory))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    protected function packageFiles(string $namespace, string $locale): array
    {
        $hint = $this->translator->getLoader()->namespaces()[$namespace] ?? null;

        $files = $hint === null ? [] : $this->readDirectory($hint.DIRECTORY_SEPARATOR.$locale);

        foreach ($this->readDirectory(lang_path("vendor/{$namespace}/{$locale}")) as $file => $contents) {
            $files[$file] = array_replace_recursive($files[$file] ?? [], $contents);
        }

        return $files;
    }

    /**
     * @return array<string, array<array-key, mixed>> file path relative to the directory, without extension => returned array
     */
    protected function readDirectory(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::getRequire($file->getPathname());
            $files[$this->groupName($file)] = is_array($contents) ? $contents : [];
        }

        return $files;
    }

    protected function groupName(SplFileInfo $file): string
    {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

        return substr($relative, 0, -strlen('.php'));
    }

    /**
     * JSON lines are already flat and their keys are sentences, so their dots
     * must not be read as nesting.
     *
     * @param  array<array-key, mixed>  $contents
     * @return array<string, string>
     */
    protected function flatten(array $contents, bool $dotted = true): array
    {
        $lines = array_filter($dotted ? Arr::dot($contents) : $contents, 'is_string');
        ksort($lines, SORT_STRING);

        return $lines;
    }
}
