<?php

namespace PaperleafTech\LaravelTranslation\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use PaperleafTech\LaravelTranslation\LaravelTranslationServiceProvider;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;

abstract class TestCase extends Orchestra
{
    protected string $testBackupPath;

    /** @var list<string> */
    protected array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBackupPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-translation-tests-'.uniqid();
        config()->set('laravel-translation.backup.path', $this->testBackupPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->testBackupPath ?? null, ...$this->temporaryDirectories] as $directory) {
            if ($directory !== null && File::isDirectory($directory)) {
                File::deleteDirectory($directory);
            }
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelTranslationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('laravel-translation.spreadsheet_id', 'test-spreadsheet-id');
        config()->set('laravel-translation.credentials_path', __DIR__.'/fixtures/credentials.json');
        config()->set('laravel-translation.format', false);
    }

    protected function backupPath(): string
    {
        return $this->testBackupPath;
    }

    /**
     * Point lang_path() at an empty directory that is removed after the test.
     */
    protected function useTemporaryLangPath(): string
    {
        $path = $this->temporaryDirectory('lang');
        $this->app->useLangPath($path);

        return $path;
    }

    protected function temporaryDirectory(string $name): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR."laravel-translation-{$name}-".uniqid();
        File::ensureDirectoryExists($path);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    /**
     * @param  array<string, array<array-key, mixed>|string>  $files  path relative to $root => PHP array, JSON array, or raw file contents
     */
    protected function writeLangFiles(array $files, ?string $root = null): void
    {
        foreach ($files as $file => $contents) {
            $path = ($root ?? lang_path()).'/'.$file;

            File::ensureDirectoryExists(dirname($path));
            File::put($path, match (true) {
                is_string($contents) => $contents,
                str_ends_with($file, '.json') => json_encode($contents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                default => '<?php return '.var_export($contents, true).';',
            });
        }
    }

    protected function fakeSheets(): FakeGoogleSheetsService
    {
        $fake = new FakeGoogleSheetsService;
        $this->app->instance(GoogleSheetsService::class, $fake);

        return $fake;
    }
}
