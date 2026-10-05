<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TranslationBackupManagerTest extends TestCase
{
    private TranslationBackupManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new TranslationBackupManager;
    }

    #[Test]
    public function it_saves_the_sheet_rows_as_json_with_a_gitignore(): void
    {
        $rows = [['key', 'group', 'default', 'en'], ['failed', 'auth', 'Failed', 'Échec']];

        $path = $this->manager->backup($rows);

        $this->assertSame($this->backupPath(), dirname($path));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{6}\.json$/', basename($path));
        $payload = json_decode(File::get($path), true);
        $this->assertSame('Translations', $payload['sheet']);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertSame($rows, $payload['rows']);
        $this->assertSame("*\n!.gitignore\n", File::get($this->backupPath().'/.gitignore'));
    }

    #[Test]
    public function it_does_not_overwrite_an_existing_gitignore(): void
    {
        File::ensureDirectoryExists($this->backupPath());
        File::put($this->backupPath().'/.gitignore', "custom\n");

        $this->manager->backup([['key']]);

        $this->assertSame("custom\n", File::get($this->backupPath().'/.gitignore'));
    }

    #[Test]
    public function prune_keeps_the_newest_and_leaves_old_locale_folders_alone(): void
    {
        File::ensureDirectoryExists($this->backupPath().'/fr');
        File::put($this->backupPath().'/fr/2026-01-01_120000.json', '{}');

        $files = [];
        for ($i = 1; $i <= 7; $i++) {
            $files[$i] = $this->backupPath().sprintf('/2026-01-%02d_120000.json', $i);
            File::put($files[$i], '{}');
            touch($files[$i], time() - (8 - $i) * 60);
        }

        $this->assertSame(2, $this->manager->prune(5));
        $this->assertFileDoesNotExist($files[1]);
        $this->assertFileDoesNotExist($files[2]);
        $this->assertFileExists($files[3]);
        $this->assertFileExists($this->backupPath().'/fr/2026-01-01_120000.json');
    }

    #[Test]
    public function prune_rejects_a_negative_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->prune(-1);
    }

    #[Test]
    public function disabled_backups_write_and_prune_nothing(): void
    {
        config()->set('laravel-translation.backup.path', false);

        $this->assertFalse($this->manager->isEnabled());
        $this->assertNull($this->manager->backup([['key']]));
        $this->assertSame(0, $this->manager->prune(5));
    }

    #[Test]
    public function relative_paths_resolve_under_storage_and_absolute_ones_are_kept(): void
    {
        config()->set('laravel-translation.backup.path', 'app/translation-backups');
        $this->assertSame(storage_path('app/translation-backups'), $this->manager->path());

        config()->set('laravel-translation.backup.path', '/tmp/somewhere');
        $this->assertSame('/tmp/somewhere', $this->manager->path());
    }
}
