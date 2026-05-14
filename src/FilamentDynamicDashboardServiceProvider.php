<?php

namespace MDDev\DynamicDashboard;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Livewire\DashboardManager;
use MDDev\DynamicDashboard\Templates\TemplateRegistry;
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
            ->hasMigrations([
                'create_dynamic_dashboard_tables',
                'upgrade_dynamic_dashboard_tables_to_v2',
            ]);

        $this->app->singleton(TemplateRegistry::class);
    }

    /**
     * Boots the package and registers Blade/Livewire components and Filament assets.
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

        FilamentAsset::register(
            [
                Css::make('gridstack', __DIR__.'/../resources/dist/gridstack.min.css'),
                Css::make('dashboard', __DIR__.'/../resources/css/dashboard.css'),
                Js::make('gridstack', __DIR__.'/../resources/dist/gridstack-all.js'),
                Js::make('dashboard', __DIR__.'/../resources/js/dashboard.js'),
            ],
            package: 'mddev31/filament-dynamic-dashboard',
        );

        $this->cascadeDeletePersonalDashboardsWithUser();
    }

    /**
     * When a user is deleted, drop their personal dashboards.
     *
     * The FK on `created_by` is `nullOnDelete`, so without this hook the
     * personal dashboards would survive their owner as orphan rows that nobody
     * can ever see again. Globals created by the same user keep their row and
     * lose the `created_by` reference (audit residue) — only personals cascade.
     */
    protected function cascadeDeletePersonalDashboardsWithUser(): void
    {
        $userModel = config('auth.providers.users.model');

        if (! $userModel || ! class_exists($userModel) || ! is_subclass_of($userModel, Model::class)) {
            return;
        }

        $userModel::deleting(function (Model $user): void {
            DashboardModelHelper::model()::query()
                ->where('is_personal', true)
                ->where('created_by', $user->getKey())
                ->delete();
        });
    }
}
