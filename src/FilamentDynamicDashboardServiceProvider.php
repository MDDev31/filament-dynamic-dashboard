<?php

namespace MDDev\DynamicDashboard;

use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Assets\CanvasCss;
use MDDev\DynamicDashboard\Assets\CanvasJs;
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
    }

    /**
     * Registers the package's container bindings.
     */
    public function packageRegistered(): void
    {
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

        // `php artisan filament:assets` publishes these like any other asset.
        // Outside SPA mode they are loaded on request: Filament leaves them
        // out of its global list and the canvas view pulls them in itself, so
        // only dashboard pages download them (see LoadedOnRequestOutsideSpaMode).
        FilamentAsset::register(
            [
                CanvasCss::make('gridstack', __DIR__.'/../resources/dist/gridstack.min.css'),
                CanvasCss::make('dashboard', __DIR__.'/../resources/css/dashboard.css'),
                CanvasJs::make('gridstack', __DIR__.'/../resources/dist/gridstack-all.js'),
                CanvasJs::make('dashboard', __DIR__.'/../resources/js/dashboard.js'),
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
     *
     * The listener is registered on the event dispatcher (not through
     * `User::deleting()`) so the user model is not booted on every request.
     * A soft delete keeps the personal dashboards — the user can still be
     * restored — and they are only dropped once the user is force-deleted.
     */
    protected function cascadeDeletePersonalDashboardsWithUser(): void
    {
        $userModel = config('auth.providers.users.model');

        if (! $userModel || ! class_exists($userModel) || ! is_subclass_of($userModel, Model::class)) {
            return;
        }

        Event::listen('eloquent.deleting: '.$userModel, function (Model $user): void {
            if (method_exists($user, 'isForceDeleting') && ! $user->isForceDeleting()) {
                return;
            }

            DashboardModelHelper::model()::query()
                ->where('is_personal', true)
                ->where('created_by', $user->getKey())
                ->delete();
        });
    }
}
