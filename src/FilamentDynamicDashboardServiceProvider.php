<?php

namespace MDDev\DynamicDashboard;

use Livewire\Livewire;
use MDDev\DynamicDashboard\Livewire\DashboardManager;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWithRoles;
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
     * Boots the package, registers Blade/Livewire components, and performs the Spatie model swap.
     *
     * When `use_spatie_permissions` is enabled and no custom Dashboard model is configured,
     * the default Dashboard model is replaced with DashboardWithRoles so that role-based
     * visibility works out of the box.
     */
    public function packageBooted(): void
    {
        parent::packageBooted();

        Livewire::component('filament-dynamic-dashboard::dashboard-manager', DashboardManager::class);

        if (config('filament-dynamic-dashboard.use_spatie_permissions', false)) {
            $configuredModel = config('filament-dynamic-dashboard.models.dashboard');

            if ($configuredModel === Dashboard::class || $configuredModel === null) {
                config(['filament-dynamic-dashboard.models.dashboard' => DashboardWithRoles::class]);
            }
        }
    }
}
