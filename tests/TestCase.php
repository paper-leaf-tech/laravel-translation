<?php

namespace PaperleafTech\LaravelTranslation\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use PaperleafTech\LaravelTranslation\LaravelTranslationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $testBackupPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBackupPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-translation-tests-'.uniqid();
        config()->set('laravel-translation.backup.path', $this->testBackupPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->testBackupPath) && File::isDirectory($this->testBackupPath)) {
            File::deleteDirectory($this->testBackupPath);
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
    }

    protected function backupPath(): string
    {
        return $this->testBackupPath;
    }
}
