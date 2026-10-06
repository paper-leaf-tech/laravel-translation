<?php

namespace PaperleafTech\LaravelTranslation;

use PaperleafTech\LaravelTranslation\Commands\CheckCommand;
use PaperleafTech\LaravelTranslation\Commands\PullCommand;
use PaperleafTech\LaravelTranslation\Commands\PushCommand;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Services\TranslationFileWriter;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelTranslationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-translation')
            ->hasConfigFile('laravel-translation')
            ->hasCommands([
                PushCommand::class,
                PullCommand::class,
                CheckCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('paper-leaf-tech/laravel-translation');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GoogleSheetsService::class, fn () => new GoogleSheetsService());
        $this->app->singleton(TranslationBackupManager::class, fn () => new TranslationBackupManager());
        $this->app->singleton(TranslationFileWriter::class, fn () => new TranslationFileWriter());
    }
}
