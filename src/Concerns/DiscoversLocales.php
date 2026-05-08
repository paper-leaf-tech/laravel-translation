<?php

namespace PaperleafTech\LaravelTranslation\Concerns;

use Illuminate\Support\Facades\File;

trait DiscoversLocales
{
    /**
     * Return locale codes found as direct subdirectories of lang_path().
     * Skips dot-prefixed directories and the `vendor` directory (Laravel
     * uses `lang/vendor/<package>/<locale>/...` for package translations).
     *
     * @return array<int, string>
     */
    protected function discoverLocales(): array
    {
        $base = lang_path();

        if (! File::isDirectory($base)) {
            return [];
        }

        $locales = [];
        foreach (File::directories($base) as $directory) {
            $name = basename($directory);
            if (str_starts_with($name, '.') || $name === 'vendor') {
                continue;
            }
            $locales[] = $name;
        }

        sort($locales);

        return $locales;
    }
}
