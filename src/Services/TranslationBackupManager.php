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

    /**
     * Save the sheet's cells as JSON before a push overwrites them.
     * Returns the file written, or null when backups are disabled.
     *
     * @param  list<list<string>>  $rows
     */
    public function backup(array $rows): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $root = $this->path();
        File::ensureDirectoryExists($root);
        $this->ensureGitignore($root);

        $filePath = $root.DIRECTORY_SEPARATOR.date('Y-m-d_His').'.json';

        File::put($filePath, json_encode([
            'sheet' => config('laravel-translation.sheet', 'Translations'),
            'created_at' => date(\DateTimeInterface::ATOM),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $filePath;
    }

    /**
     * Keep the $keep newest backups (by mtime) and delete the rest. Per-locale
     * folders left by 0.4 are not touched. Returns how many were deleted.
     */
    public function prune(int $keep): int
    {
        if ($keep < 0) {
            throw new \InvalidArgumentException('Keep count must be a non-negative integer');
        }

        if (! $this->isEnabled() || ! File::isDirectory($this->path())) {
            return 0;
        }

        $toDelete = collect(File::files($this->path()))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values()
            ->slice($keep);

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
