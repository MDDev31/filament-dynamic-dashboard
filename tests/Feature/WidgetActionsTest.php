<?php

use Livewire\Livewire;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\SettingsWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;
use MDDev\DynamicDashboard\Tests\Fixtures\TestWidget;

it('adds a widget at its default size below the existing ones', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $this->createWidget($dashboard, ['y' => 0, 'h' => 3]);

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'addWidget')
        ->set('mountedActions.0.data.name', 'Created')
        ->set('mountedActions.0.data.type', SettingsWidget::class)
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $created = DashboardWidget::query()->where('name', 'Created')->sole();

    expect($created->dashboard_id)->toBe($dashboard->id)
        ->and($created->section_slug)->toBe('main')
        ->and([$created->x, $created->y, $created->w, $created->h])->toBe([0, 3, 4, 3]);
});

it('edits and deletes a widget of the current dashboard', function (): void {
    $this->signIn();

    $widget = $this->createWidget($this->defaultDashboard(), ['name' => 'Original']);

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'editWidget', ['widget' => $widget->id])
        ->assertSet('mountedActions.0.data.widget_id', $widget->id)
        ->assertSet('mountedActions.0.data.name', 'Original')
        ->set('mountedActions.0.data.name', 'Renamed')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    expect($widget->fresh()->name)->toBe('Renamed');

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'deleteWidget', ['widget' => $widget->id])
        ->call('callMountedAction');

    expect($widget->fresh())->toBeNull();
});

it('cannot reach a widget of another dashboard with a forged id', function (): void {
    $this->signIn();
    $bob = $this->createUser('bob');

    $bobs = Dashboard::create(['name' => 'bob personal', 'is_personal' => true, 'created_by' => $bob->id]);
    $foreign = $this->createWidget($bobs, ['name' => 'Foreign']);

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'deleteWidget', ['widget' => $foreign->id])
        ->call('callMountedAction');

    expect($foreign->fresh())->not->toBeNull();

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'editWidget', ['widget' => $foreign->id])
        ->assertSet('mountedActions.0.data.widget_id', null)
        ->set('mountedActions.0.data.widget_id', $foreign->id)
        ->set('mountedActions.0.data.name', 'Hacked')
        ->set('mountedActions.0.data.type', TestWidget::class)
        ->call('callMountedAction');

    expect($foreign->fresh()->name)->toBe('Foreign');
});

it('blocks every widget action while the dashboard is locked', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $widget = $this->createWidget($dashboard);
    $dashboard->update(['is_locked' => true]);

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'addWidget')
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'editWidget', ['widget' => $widget->id])
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'deleteWidget', ['widget' => $widget->id])
        ->assertSet('mountedActions', []);

    expect($widget->fresh())->not->toBeNull();

    $dashboard->update(['is_locked' => false]);

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'deleteWidget', ['widget' => $widget->id])
        ->call('callMountedAction');

    expect($widget->fresh())->toBeNull();
});

it('blocks every widget action for a user who cannot edit', function (): void {
    $this->signIn();
    TestDashboardPage::$editable = false;

    $widget = $this->createWidget($this->defaultDashboard());

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'addWidget')
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'deleteWidget', ['widget' => $widget->id])
        ->assertSet('mountedActions', []);

    expect($widget->fresh())->not->toBeNull();
});
