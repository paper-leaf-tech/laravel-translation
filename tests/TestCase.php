<?php

namespace PaperleafTech\LaravelTranslation\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PaperleafTech\LaravelTranslation\LaravelTranslationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelTranslationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default configuration
        config()->set('laravel-translation.spreadsheet_id', 'test-spreadsheet-id');
        config()->set('laravel-translation.sheet_name', 'Translations');
        config()->set('laravel-translation.credentials_path', __DIR__.'/fixtures/credentials.json');
    }
}
