<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class TranslationBackupManagerTest extends TestCase
{
    protected TranslationBackupManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new TranslationBackupManager();
    }

    /** @test */
    public function it_creates_root_directory_and_gitignore_on_first_backup(): void
    {
        $rows = [['auth.failed', 'Failed', '']];
        $path = $this->manager->backup('en', $rows);

        $this->assertNotNull($path);
        $this->assertFileExists($path);

        $gitignore = $this->backupPath().DIRECTORY_SEPARATOR.'.gitignore';
        $this->assertFileExists($gitignore);
        $this->assertSame("*\n!.gitignore\n", File::get($gitignore));
    }

    /** @test */
    public function it_does_not_overwrite_existing_gitignore(): void
    {
        File::makeDirectory($this->backupPath(), 0755, true);
        $gitignore = $this->backupPath().DIRECTORY_SEPARATOR.'.gitignore';
        File::put($gitignore, "custom content\n");

        $this->manager->backup('en', [['k', 'v', '']]);

        $this->assertSame("custom content\n", File::get($gitignore));
    }

    /** @test */
    public function it_writes_per_locale_subdirectory(): void
    {
        $this->manager->backup('en', [['k', 'v', '']]);
        $this->manager->backup('fr', [['k', 'v', '']]);

        $this->assertTrue(File::isDirectory($this->backupPath().'/en'));
        $this->assertTrue(File::isDirectory($this->backupPath().'/fr'));
    }

    /** @test */
    public function it_serializes_rows_as_json(): void
    {
        $rows = [['auth.failed', 'Failed', 'Échec']];
        $path = $this->manager->backup('fr', $rows);

        $payload = json_decode(File::get($path), true);

        $this->assertSame('fr', $payload['locale']);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertSame($rows, $payload['rows']);
    }

    /** @test */
    public function backup_filename_uses_timestamp(): void
    {
        $path = $this->manager->backup('en', [['k', 'v', '']]);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{6}\.json$/', basename($path));
    }

    /** @test */
    public function prune_keeps_n_most_recent(): void
    {
        $localeDir = $this->backupPath().'/en';
        File::makeDirectory($localeDir, 0755, true);

        $files = [];
        for ($i = 0; $i < 7; $i++) {
            $name = sprintf('2026-01-%02d_120000.json', $i + 1);
            $full = $localeDir.DIRECTORY_SEPARATOR.$name;
            File::put($full, '{}');
            touch($full, time() - (7 - $i) * 60); // older first, newer last
            $files[] = $full;
        }

        $deleted = $this->manager->prune('en', 5);

        $this->assertSame(2, $deleted);
        $remaining = collect(File::files($localeDir))->map(fn ($f) => $f->getFilename())->all();
        $this->assertCount(5, $remaining);
        // The two oldest should have been deleted
        $this->assertNotContains(basename($files[0]), $remaining);
        $this->assertNotContains(basename($files[1]), $remaining);
    }

    /** @test */
    public function prune_returns_zero_when_under_limit(): void
    {
        $localeDir = $this->backupPath().'/en';
        File::makeDirectory($localeDir, 0755, true);
        File::put($localeDir.'/one.json', '{}');

        $this->assertSame(0, $this->manager->prune('en', 5));
    }

    /** @test */
    public function prune_is_per_locale(): void
    {
        $en = $this->backupPath().'/en';
        $fr = $this->backupPath().'/fr';
        File::makeDirectory($en, 0755, true);
        File::makeDirectory($fr, 0755, true);

        for ($i = 0; $i < 6; $i++) {
            $f = $en.'/en'.$i.'.json';
            File::put($f, '{}');
            touch($f, time() - (6 - $i) * 60);
        }
        File::put($fr.'/fr0.json', '{}');

        $this->manager->prune('en', 3);

        $this->assertCount(3, File::files($en));
        $this->assertCount(1, File::files($fr));
    }

    /** @test */
    public function prune_throws_for_negative_keep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->prune('en', -1);
    }

    /** @test */
    public function path_resolves_relative_via_storage_path(): void
    {
        config()->set('laravel-translation.backup.path', 'app/translation-backups');

        $this->assertSame(storage_path('app/translation-backups'), $this->manager->path());
    }

    /** @test */
    public function path_uses_absolute_paths_verbatim(): void
    {
        $abs = '/tmp/some-absolute-backup-dir';
        config()->set('laravel-translation.backup.path', $abs);

        $this->assertSame($abs, $this->manager->path());
    }

    /** @test */
    public function is_enabled_returns_false_when_path_is_false(): void
    {
        config()->set('laravel-translation.backup.path', false);

        $this->assertFalse($this->manager->isEnabled());
    }

    /** @test */
    public function is_enabled_returns_false_when_path_is_null(): void
    {
        config()->set('laravel-translation.backup.path', null);

        $this->assertFalse($this->manager->isEnabled());
    }

    /** @test */
    public function is_enabled_returns_true_for_string_path(): void
    {
        $this->assertTrue($this->manager->isEnabled());
    }

    /** @test */
    public function backup_returns_null_when_disabled(): void
    {
        config()->set('laravel-translation.backup.path', false);

        $this->assertNull($this->manager->backup('en', [['k', 'v', '']]));
    }

    /** @test */
    public function prune_returns_zero_when_disabled(): void
    {
        config()->set('laravel-translation.backup.path', false);

        $this->assertSame(0, $this->manager->prune('en', 5));
    }

    /** @test */
    public function path_throws_when_disabled(): void
    {
        config()->set('laravel-translation.backup.path', null);

        $this->expectException(\RuntimeException::class);
        $this->manager->path();
    }
}
