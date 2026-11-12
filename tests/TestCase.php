<?php

namespace MDDev\DynamicDashboard\Tests;

use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MDDev\DynamicDashboard\FilamentDynamicDashboardServiceProvider;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\BareDashboardPage;
use MDDev\DynamicDashboard\Tests\Fixtures\SecondaryFiltersPage;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;
use MDDev\DynamicDashboard\Tests\Fixtures\TestPanelProvider;
use MDDev\DynamicDashboard\Tests\Fixtures\TestWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a real Filament panel (see TestPanelProvider) on an in-memory SQLite
 * database migrated with the package's own migration stubs.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Filament, Livewire and their dependencies are discovered from the
     * installed packages, so the suite runs unchanged on Filament 4 and 5.
     */
    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        parent::setUp();

        TestDashboardPage::$editable = true;
        BareDashboardPage::$editable = true;
        SecondaryFiltersPage::$secondary = [];

        Filament::setCurrentPanel(Filament::getPanel('test'));
    }

    /**
     * The user model class configured as `auth.providers.users.model`.
     *
     * @return class-string<User>
     */
    protected function userModel(): string
    {
        return User::class;
    }

    /** @inheritDoc */
    protected function getPackageProviders($app): array
    {
        return [
            FilamentDynamicDashboardServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    /** @inheritDoc */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set('auth.providers.users.model', $this->userModel());
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
    }

    /** @inheritDoc */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        $this->runPackageMigration('create_dynamic_dashboard_tables');
        $this->runPackageMigration('upgrade_dynamic_dashboard_tables_to_v2');
    }

    /**
     * Run the `up()` step of one of the package's migration stubs.
     */
    protected function runPackageMigration(string $name): void
    {
        (require __DIR__.'/../database/migrations/'.$name.'.php.stub')->up();
    }

    /**
     * Create a user of the configured user model.
     */
    protected function createUser(string $name = 'user'): User
    {
        return $this->userModel()::create(['name' => $name]);
    }

    /**
     * Create a user and authenticate as them.
     */
    protected function signIn(string $name = 'alice'): User
    {
        $user = $this->createUser($name);

        $this->actingAs($user);

        return $user;
    }

    /**
     * The dashboard seeded by the create migration.
     */
    protected function defaultDashboard(): Dashboard
    {
        return Dashboard::query()->orderBy('id')->firstOrFail();
    }

    /**
     * Create a widget on the given dashboard.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createWidget(Dashboard $dashboard, array $attributes = []): DashboardWidget
    {
        return DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'name' => 'Widget',
            'type' => TestWidget::class,
            ...$attributes,
        ]);
    }
}
