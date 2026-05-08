<?php

namespace PaperleafTech\LaravelTranslation\Services;

use Illuminate\Support\Facades\File;

class TranslationBackupManager
{
    public function isEnabled(): bool
    {
        $configured = config('laravel-translation.backup.path');

        return $configured !== false && $configured !== null;
    }

    public function path(): string
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException('Translation backups are disabled (TRANSLATION_BACKUP_PATH is null or false).');
        }

        $configured = config('laravel-translation.backup.path');

        return str_starts_with($configured, DIRECTORY_SEPARATOR)
            ? $configured
            : storage_path($configured);
    }

    public function localePath(string $locale): string
    {
        return $this->path().DIRECTORY_SEPARATOR.$locale;
    }

    /**
     * Persist a JSON snapshot of sheet rows for a locale.
     * Returns the absolute file path written, or null if backups are disabled.
     */
    public function backup(string $locale, array $rows): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $root = $this->path();
        if (! File::isDirectory($root)) {
            File::makeDirectory($root, 0755, true);
        }
        $this->ensureGitignore($root);

        $localeDir = $this->localePath($locale);
        if (! File::isDirectory($localeDir)) {
            File::makeDirectory($localeDir, 0755, true);
        }

        $timestamp = date('Y-m-d_His');
        $filePath = $localeDir.DIRECTORY_SEPARATOR.$timestamp.'.json';

        File::put($filePath, json_encode([
            'locale' => $locale,
            'created_at' => date(\DateTimeInterface::ATOM),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $filePath;
    }

    /**
     * Keep N most recent backups for the locale (by mtime); delete older ones.
     * Returns count deleted. Returns 0 when backups are disabled.
     */
    public function prune(string $locale, int $keep): int
    {
        if ($keep < 0) {
            throw new \InvalidArgumentException('Keep count must be a non-negative integer');
        }

        if (! $this->isEnabled()) {
            return 0;
        }

        $localeDir = $this->localePath($locale);
        if (! File::isDirectory($localeDir)) {
            return 0;
        }

        $files = collect(File::files($localeDir))
            ->filter(fn ($f) => $f->getExtension() === 'json')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        $toDelete = $files->slice($keep);

        foreach ($toDelete as $file) {
            File::delete($file->getPathname());
        }

        return $toDelete->count();
    }

    protected function ensureGitignore(string $root): void
    {
        $gitignore = $root.DIRECTORY_SEPARATOR.'.gitignore';
        if (! File::exists($gitignore)) {
            File::put($gitignore, "*\n!.gitignore\n");
        }
    }
}
