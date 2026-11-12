<?php

use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Livewire\DashboardManager;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;

/**
 * Mount the manager the way the dashboard page's slideover does.
 */
function mountManager(Dashboard $current): Testable
{
    return Livewire::test(DashboardManager::class, [
        'pageClass' => TestDashboardPage::class,
        'currentDashboardId' => $current->id,
    ]);
}

it('lists global dashboards and the user own personal ones only', function (): void {
    $this->signIn();
    $bob = $this->createUser('bob');

    $default = $this->defaultDashboard();
    $mine = Dashboard::create(['name' => 'alice personal', 'is_personal' => true]);
    $theirs = Dashboard::create(['name' => 'bob personal', 'is_personal' => true, 'created_by' => $bob->id]);
    $otherPage = Dashboard::create(['name' => 'other page', 'page' => 'App\\Filament\\Pages\\Other']);

    $manager = mountManager($default)
        ->assertOk()
        ->assertCanSeeTableRecords([$default, $mine, $otherPage])
        ->assertCanNotSeeTableRecords([$theirs]);

    // Row operations cannot reach the hidden record either.
    $manager->call('updateTableColumnState', 'is_locked', (string) $theirs->id, true);
    expect($theirs->fresh()->is_locked)->toBeFalse();

    $manager->call('updateTableColumnState', 'is_locked', (string) $mine->id, true);
    expect($mine->fresh()->is_locked)->toBeTrue();
});

it('is forbidden to a user who cannot edit', function (): void {
    $this->signIn();
    TestDashboardPage::$editable = false;

    mountManager($this->defaultDashboard())->assertForbidden();
});

it('does not let the client swap the page class', function (): void {
    $this->signIn();

    mountManager($this->defaultDashboard())->set('pageClass', 'App\\Filament\\Pages\\Other');
})->throws(CannotUpdateLockedPropertyException::class);

it('never deactivates the current or the last active dashboard', function (): void {
    $this->signIn();

    $default = $this->defaultDashboard();
    $second = Dashboard::create(['name' => 'second']);

    $manager = mountManager($default);

    // The current dashboard cannot be deactivated.
    $manager->call('updateTableColumnState', 'is_active', (string) $default->id, false);
    expect($default->fresh()->is_active)->toBeTrue();

    // Another one can, as long as one stays active.
    $manager->call('updateTableColumnState', 'is_active', (string) $second->id, false);
    expect($second->fresh()->is_active)->toBeFalse();

    // Viewed from the second dashboard, the default one is now the last active.
    $second->update(['is_active' => true]);
    $default->update(['is_active' => false]);

    mountManager($default)->call('updateTableColumnState', 'is_active', (string) $second->id, false);
    expect($second->fresh()->is_active)->toBeTrue();
});

it('counts the available dashboards once per request and refreshes after a change', function (): void {
    $this->signIn();

    $default = $this->defaultDashboard();
    $second = Dashboard::create(['name' => 'second']);
    Dashboard::create(['name' => 'third']);
    Dashboard::create(['name' => 'fourth']);

    $countQueries = function (callable $callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            // Matched loosely: the quoting of the aggregate alias differs between Laravel versions.
            ->filter(fn (array $query): bool => str_starts_with($query['query'], 'select count(*)')
                && str_contains($query['query'], 'is_active'))
            ->count();
    };

    $manager = null;
    expect($countQueries(function () use (&$manager, $default): void {
        $manager = mountManager($default);
    }))->toBe(1);

    $isLastActive = function (Dashboard $dashboard) use (&$manager): bool {
        $method = new ReflectionMethod(DashboardManager::class, 'isLastActiveDashboard');

        return $method->invoke($manager->instance(), $dashboard->fresh());
    };

    expect($isLastActive($default))->toBeFalse();

    // Deactivating the three others in turn leaves the default as the last
    // active one: the count cached before each change must not be reused.
    foreach (Dashboard::query()->whereKeyNot($default->id)->get() as $dashboard) {
        $manager->call('updateTableColumnState', 'is_active', (string) $dashboard->id, false);
    }

    expect($second->fresh()->is_active)->toBeFalse()
        ->and($isLastActive($default))->toBeTrue();
});

it('duplicates a dashboard with its widgets for the current user', function (): void {
    $alice = $this->signIn();
    $bob = $this->createUser('bob');

    $default = $this->defaultDashboard();
    $source = Dashboard::create(['name' => 'Source', 'template_key' => '2-columns', 'created_by' => $bob->id]);
    $this->createWidget($source, ['name' => 'Copied', 'section_slug' => 'right', 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 2]);

    mountManager($default)->callTableAction('replicate', $source);

    $copy = Dashboard::query()->where('name', 'Source (copy)')->sole();
    $widget = $copy->widgets()->sole();

    expect($copy->template_key)->toBe('2-columns')
        ->and($copy->created_by)->toBe($alice->id)
        ->and($copy->ordering)->toBeGreaterThan($source->ordering)
        ->and([$widget->name, $widget->section_slug, $widget->x, $widget->y, $widget->w, $widget->h])
        ->toBe(['Copied', 'right', 1, 2, 3, 2]);
});
