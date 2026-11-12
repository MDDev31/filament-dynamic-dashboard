<?php

use Livewire\Livewire;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;

/**
 * Current `[section, x, y, w, h]` of a widget, read back from the database.
 */
function layoutOf(DashboardWidget $widget): array
{
    $fresh = $widget->fresh();

    return [$fresh->section_slug, $fresh->x, $fresh->y, $fresh->w, $fresh->h];
}

it('stores the layout sent by the canvas', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $dashboard->update(['template_key' => '2-columns']);

    $first = $this->createWidget($dashboard, ['section_slug' => 'left']);
    $second = $this->createWidget($dashboard, ['section_slug' => 'left', 'y' => 2]);

    Livewire::test(TestDashboardPage::class)->call('persistLayout', [
        ['id' => $first->id, 'section' => 'right', 'x' => 2, 'y' => 1, 'w' => 3, 'h' => 2],
        ['id' => $second->id, 'section' => 'left', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
    ]);

    expect(layoutOf($first))->toBe(['right', 2, 1, 3, 2])
        ->and(layoutOf($second))->toBe(['left', 0, 0, 6, 4]);
});

it('stores an unknown section as the first section of the template', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $dashboard->update(['template_key' => '2-columns']);
    $widget = $this->createWidget($dashboard, ['section_slug' => 'right']);

    Livewire::test(TestDashboardPage::class)->call('persistLayout', [
        ['id' => $widget->id, 'section' => 'removed-section', 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 2],
    ]);

    expect(layoutOf($widget))->toBe(['left', 1, 2, 3, 2]);
});

it('ignores malformed items', function (array $item): void {
    $this->signIn();

    $widget = $this->createWidget($this->defaultDashboard(), ['x' => 1, 'y' => 1, 'w' => 4, 'h' => 2]);

    Livewire::test(TestDashboardPage::class)->call('persistLayout', [
        ['id' => $widget->id, 'section' => 'main', ...$item],
        'not an array',
    ]);

    expect(layoutOf($widget))->toBe(['main', 1, 1, 4, 2]);
})->with([
    'negative x' => [['x' => -1, 'y' => 0, 'w' => 4, 'h' => 2]],
    'negative y' => [['x' => 0, 'y' => -3, 'w' => 4, 'h' => 2]],
    'zero width' => [['x' => 0, 'y' => 0, 'w' => 0, 'h' => 2]],
    'zero height' => [['x' => 0, 'y' => 0, 'w' => 4, 'h' => 0]],
    'non numeric' => [['x' => 'left', 'y' => 0, 'w' => 4, 'h' => 2]],
    'missing key' => [['x' => 0, 'y' => 0, 'w' => 4]],
    'section not a string' => [['section' => ['main'], 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
]);

it('never moves a widget of another dashboard', function (): void {
    $this->signIn();

    $other = Dashboard::create(['name' => 'other']);
    $foreign = $this->createWidget($other, ['x' => 1, 'y' => 1, 'w' => 4, 'h' => 2]);

    Livewire::test(TestDashboardPage::class)->call('persistLayout', [
        ['id' => $foreign->id, 'section' => 'main', 'x' => 5, 'y' => 5, 'w' => 5, 'h' => 5],
    ]);

    expect(layoutOf($foreign))->toBe(['main', 1, 1, 4, 2]);
});

it('refuses to store a layout on a locked dashboard', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $widget = $this->createWidget($dashboard, ['x' => 1, 'y' => 1, 'w' => 4, 'h' => 2]);
    $dashboard->update(['is_locked' => true]);

    Livewire::test(TestDashboardPage::class)
        ->call('persistLayout', [
            ['id' => $widget->id, 'section' => 'main', 'x' => 5, 'y' => 5, 'w' => 5, 'h' => 5],
        ])
        ->assertForbidden();

    expect(layoutOf($widget))->toBe(['main', 1, 1, 4, 2]);
});

it('refuses to store a layout once the dashboard is no longer available to the user', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    Dashboard::create(['name' => 'still active']);
    $widget = $this->createWidget($dashboard, ['x' => 1, 'y' => 1, 'w' => 4, 'h' => 2]);

    $page = Livewire::test(TestDashboardPage::class)->assertSet('currentDashboardId', $dashboard->id);

    // Someone else deactivates it while the page is still open.
    $dashboard->update(['is_active' => false]);

    $page->call('persistLayout', [
        ['id' => $widget->id, 'section' => 'main', 'x' => 5, 'y' => 5, 'w' => 5, 'h' => 5],
    ])->assertForbidden();

    expect(layoutOf($widget))->toBe(['main', 1, 1, 4, 2]);
});
