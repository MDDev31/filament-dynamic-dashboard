<?php

namespace MDDev\DynamicDashboard;

use Livewire\Livewire;
use MDDev\DynamicDashboard\Livewire\DashboardManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers config, views, translations, migrations, and Livewire components for the package.
 */
class FilamentDynamicDashboardServiceProvider extends PackageServiceProvider
{
    /**
     * Configures the given package
     * as a Package Service Provider.
     *
     * @param  Package  $package  the package to be configured
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('filament-dynamic-dashboard')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations(['create_dynamic_dashboard_tables']);
    }

    /**
     * Boots the package and registers Blade/Livewire components.
     */
    public function packageBooted(): void
    {
        // Livewire 4 uses addNamespace for namespaced components
        // Livewire 3 uses the component() method
        if (method_exists(app('livewire'), 'addNamespace')) {
            Livewire::addNamespace(
                namespace: 'filament-dynamic-dashboard',
                classNamespace: 'MDDev\\DynamicDashboard\\Livewire',
                classPath: __DIR__.'/Livewire',
                classViewPath: __DIR__.'/../resources/views/livewire',
            );
        } else {
            Livewire::component('filament-dynamic-dashboard::dashboard-manager', DashboardManager::class);
        }
    }
}
