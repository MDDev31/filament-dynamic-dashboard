<?php

use Filament\Support\Facades\FilamentView;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Tests\Fixtures\BareDashboardPage;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;

it('renders the first displayable dashboard with its widgets', function (): void {
    $this->signIn();

    $default = $this->defaultDashboard();
    $this->createWidget($default, ['name' => 'Shown widget']);
    $this->createWidget($default, ['name' => 'Untitled widget', 'display_title' => false]);

    Livewire::test(TestDashboardPage::class)
        ->assertOk()
        ->assertSet('currentDashboardId', $default->id)
        ->assertSee('Default Dashboard')
        ->assertSeeHtml('<span class="dashboard-widget-title-badge">Shown widget</span>')
        ->assertDontSeeHtml('<span class="dashboard-widget-title-badge">Untitled widget</span>');
});

it('never selects a dashboard of another page or another user', function (): void {
    $this->signIn();
    $bob = $this->createUser('bob');

    $this->defaultDashboard()->update(['is_personal' => true, 'created_by' => $bob->id]);
    Dashboard::create(['name' => 'other page', 'page' => 'App\\Filament\\Pages\\Other']);

    Livewire::test(TestDashboardPage::class)
        ->assertOk()
        ->assertSet('currentDashboardId', null)
        ->assertDontSee('Default Dashboard')
        ->assertDontSee('other page');
});

it('switches dashboard through the selector', function (): void {
    $this->signIn();

    $second = Dashboard::create(['name' => 'Second dashboard']);

    Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'selectDashboard_'.$second->id)
        ->assertSet('currentDashboardId', $second->id)
        ->assertRedirect(TestDashboardPage::getUrl());
});

it('does not let the client change the current dashboard', function (): void {
    $this->signIn();

    $other = Dashboard::create(['name' => 'second']);

    Livewire::test(TestDashboardPage::class)->set('currentDashboardId', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('sanitizes the dashboard description', function (): void {
    $this->signIn();

    $this->defaultDashboard()->update([
        'description' => '<p>Hello <strong>bold</strong> <u>under</u> <a href="https://example.com">link</a></p>'
            .'<ul><li>item</li></ul>'
            .'<script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">bad</a>',
    ]);

    Livewire::test(TestDashboardPage::class)
        ->assertSeeHtml('<strong>bold</strong>')
        ->assertSeeHtml('<u>under</u>')
        ->assertSeeHtml('href="https://example.com"')
        ->assertSeeHtml('<li>item</li>')
        ->assertDontSeeHtml('alert(1)')
        ->assertDontSeeHtml('onerror')
        ->assertDontSeeHtml('javascript:alert');
});

it('keeps heading and filters on later requests without relying on header actions', function (): void {
    $this->signIn();

    $this->defaultDashboard()->update([
        'name' => 'Sales overview',
        'filters' => ['keyword' => 'default keyword'],
    ]);

    Livewire::test(BareDashboardPage::class)
        ->assertSee('Sales overview')
        ->assertSet('filters.keyword', 'default keyword')
        // A later request starts with an empty per-request cache.
        ->set('filters.keyword', 'typed by the user')
        ->assertSee('Sales overview')
        ->assertSet('filters.keyword', 'typed by the user')
        ->call('resetFilters')
        ->assertSee('Sales overview')
        ->assertSet('filters.keyword', 'default keyword');
});

it('hides a filter the dashboard does not display', function (): void {
    $this->signIn();

    $page = Livewire::test(BareDashboardPage::class);
    expect($page->instance()->getSchema('filtersForm')->getFlatFields(withHidden: false))->toHaveKey('keyword');

    $this->defaultDashboard()->update(['display_filters' => ['keyword' => false]]);

    $page = Livewire::test(BareDashboardPage::class);
    expect($page->instance()->getSchema('filtersForm')->getFlatFields(withHidden: false))->not->toHaveKey('keyword');
});

it('keys every widget by its id so Livewire keeps tracking it when it moves', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $dashboard->update(['template_key' => '2-columns']);
    $first = $this->createWidget($dashboard, ['section_slug' => 'left']);
    $second = $this->createWidget($dashboard, ['section_slug' => 'right']);

    $page = Livewire::test(TestDashboardPage::class);

    expect(array_keys($page->snapshot['memo']['children']))
        ->toEqualCanonicalizing(['widget-'.$first->id, 'widget-'.$second->id]);

    // Swap the sections: the loop positions change, the keys must not.
    $page->call('persistLayout', [
        ['id' => $first->id, 'section' => 'right', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2],
        ['id' => $second->id, 'section' => 'left', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2],
    ])->call('$refresh');

    expect(array_keys($page->snapshot['memo']['children']))
        ->toEqualCanonicalizing(['widget-'.$first->id, 'widget-'.$second->id]);
});

it('reloads with a Livewire navigation only when the panel is in SPA mode', function (): void {
    $this->signIn();

    $second = Dashboard::create(['name' => 'Second dashboard']);

    $page = Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'selectDashboard_'.$second->id)
        ->assertRedirect(TestDashboardPage::getUrl());

    expect($page->effects['redirectUsingNavigate'] ?? false)->toBeFalse();

    FilamentView::spa();

    $page = Livewire::test(TestDashboardPage::class)
        ->call('mountAction', 'selectDashboard_'.$this->defaultDashboard()->id)
        ->assertRedirect(TestDashboardPage::getUrl());

    expect($page->effects['redirectUsingNavigate'] ?? false)->toBeTrue();
});

it('gives the compact layout each widget\'s designed height and reading order', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $dashboard->update(['template_key' => 'kpi-strip-chart']);
    $this->createWidget($dashboard, ['section_slug' => 'kpi', 'x' => 3, 'y' => 0, 'w' => 4, 'h' => 2]);
    $this->createWidget($dashboard, ['section_slug' => 'chart', 'x' => 6, 'y' => 4, 'w' => 6, 'h' => 5]);

    Livewire::test(TestDashboardPage::class)
        // one row height per section, taken from the template
        ->assertSeeHtml('style="--dd-row-height: 60px;"')
        ->assertSeeHtml('style="--dd-row-height: 120px;"')
        // rows and (y * 1000 + x) for each widget
        ->assertSeeHtml('style="--dd-rows: 2; --dd-order: 3;"')
        ->assertSeeHtml('style="--dd-rows: 5; --dd-order: 4006;"');
});

it('skips a widget whose stored type is not a dynamic widget any more', function (): void {
    $this->signIn();

    $dashboard = $this->defaultDashboard();
    $this->createWidget($dashboard, ['name' => 'Still a widget']);
    // An existing class that is not a widget, and a class that no longer exists.
    $this->createWidget($dashboard, ['name' => 'Not a widget', 'type' => Dashboard::class]);
    $this->createWidget($dashboard, ['name' => 'Gone', 'type' => 'App\Filament\Widgets\RemovedWidget']);

    Livewire::test(TestDashboardPage::class)
        ->assertOk()
        ->assertSeeHtml('<span class="dashboard-widget-title-badge">Still a widget</span>')
        ->assertDontSeeHtml('Not a widget')
        ->assertDontSeeHtml('<span class="dashboard-widget-title-badge">Gone</span>');
});
