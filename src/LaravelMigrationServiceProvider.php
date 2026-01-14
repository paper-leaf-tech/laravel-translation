<?php

namespace PaperleafTech\LaravelTranslationCsv;

use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelMigrationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-translation-csv')
            ->hasConfigFile('laravel-translation-csv')
            ->hasCommands([
                // MigrationCommand::class,
                // NewMigrationJobCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->publishConfigFile();
            });
    }
}
