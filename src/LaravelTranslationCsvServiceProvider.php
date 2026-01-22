<?php

namespace PaperleafTech\LaravelTranslation;

use PaperleafTech\LaravelTranslation\Commands\ExportCommand;
use PaperleafTech\LaravelTranslation\Commands\ImportCommand;
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
                ExportCommand::class,
                ImportCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->publishConfigFile();
            });
    }
}
