<?php

use Illuminate\Support\Facades\DB;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\Mode;
use MDDev\DynamicDashboard\Tests\Fixtures\SettingsWidget;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;

describe('scopes', function (): void {
    it('lists the active dashboards a user may open on a page', function (): void {
        $this->signIn();
        $bob = $this->createUser('bob');

        Dashboard::create(['name' => 'mine', 'is_personal' => true]);
        Dashboard::create(['name' => 'theirs', 'is_personal' => true, 'created_by' => $bob->id]);
        Dashboard::create(['name' => 'inactive', 'is_active' => false]);
        Dashboard::create(['name' => 'other page', 'page' => 'App\\Filament\\Pages\\Other']);

        expect(Dashboard::query()->available(TestDashboardPage::class)->pluck('name')->all())
            ->toBe(['Default Dashboard', 'mine']);
    });

    it('hides other users personal dashboards whatever their state', function (): void {
        $this->signIn();
        $bob = $this->createUser('bob');

        Dashboard::create(['name' => 'mine', 'is_personal' => true]);
        Dashboard::create(['name' => 'theirs', 'is_personal' => true, 'created_by' => $bob->id]);
        Dashboard::create(['name' => 'inactive', 'is_active' => false]);

        expect(Dashboard::query()->visibleToCurrentUser()->orderBy('id')->pluck('name')->all())
            ->toBe(['Default Dashboard', 'mine', 'inactive']);
    });

    it('shows a guest the global dashboards only, never a personal one without owner', function (): void {
        // No authenticated user: the creator is left empty.
        $orphan = Dashboard::create(['name' => 'orphan', 'is_personal' => true]);

        expect($orphan->created_by)->toBeNull()
            ->and(Dashboard::query()->visibleToCurrentUser()->pluck('name')->all())->toBe(['Default Dashboard'])
            ->and(Dashboard::query()->available(TestDashboardPage::class)->pluck('name')->all())->toBe(['Default Dashboard'])
            ->and(TestDashboardPage::canDisplay($orphan))->toBeFalse();

        // Nor does it appear for a signed-in user who is not its creator.
        $this->signIn();

        expect(Dashboard::query()->visibleToCurrentUser()->pluck('name')->all())->toBe(['Default Dashboard'])
            ->and(TestDashboardPage::canDisplay($orphan))->toBeFalse();
    });

    it('lists the active widgets of a dashboard in reading order', function (): void {
        $dashboard = $this->defaultDashboard();
        $other = Dashboard::create(['name' => 'other']);

        $this->createWidget($dashboard, ['name' => 'second', 'y' => 2]);
        $this->createWidget($dashboard, ['name' => 'first', 'y' => 0]);
        $this->createWidget($dashboard, ['name' => 'inactive', 'is_active' => false]);
        $this->createWidget($other, ['name' => 'elsewhere']);

        expect(DashboardWidget::query()->availableFor($dashboard)->pluck('name')->all())
            ->toBe(['first', 'second']);
    });
});

describe('ownership', function (): void {
    it('records the creator and appends new dashboards at the end', function (): void {
        $alice = $this->signIn();

        $first = Dashboard::create(['name' => 'first']);
        $second = Dashboard::create(['name' => 'second']);

        expect($first->created_by)->toBe($alice->id)
            ->and($second->ordering)->toBe($first->ordering + 1);
    });
});

describe('widget settings cast', function (): void {
    it('returns an empty array for null or non-array JSON without a deprecation', function (): void {
        $widget = $this->createWidget($this->defaultDashboard());

        $deprecations = [];
        set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            DB::table('dashboard_widgets')->where('id', $widget->id)->update(['settings' => null]);
            $fromNull = $widget->fresh()->settings;

            DB::table('dashboard_widgets')->where('id', $widget->id)->update(['settings' => '"a string"']);
            $fromScalar = $widget->fresh()->settings;
        } finally {
            restore_error_handler();
        }

        expect($fromNull)->toBe([])
            ->and($fromScalar)->toBe([])
            ->and($deprecations)->toBe([]);
    });

    it('applies the casts declared by the widget class', function (): void {
        $widget = $this->createWidget($this->defaultDashboard(), [
            'type' => SettingsWidget::class,
            'settings' => ['mode' => Mode::Weekly, 'modes' => ['daily', 'weekly'], 'limit' => '5', 'free' => 'text'],
        ]);

        expect(DB::table('dashboard_widgets')->where('id', $widget->id)->value('settings'))
            ->toBeJson()
            ->json()->mode->toBe('weekly');

        expect($widget->fresh()->settings)->toBe([
            'mode' => Mode::Weekly,
            'modes' => [Mode::Daily, Mode::Weekly],
            'limit' => 5,
            'free' => 'text',
        ]);
    });

    it('leaves settings uncast when the widget type is unknown or was not selected', function (): void {
        $widget = $this->createWidget($this->defaultDashboard(), [
            'type' => SettingsWidget::class,
            'settings' => ['limit' => '5'],
        ]);

        // Partial select: the `type` attribute is not available to the cast.
        $partial = DashboardWidget::query()->select(['id', 'settings'])->findOrFail($widget->id);
        expect($partial->settings)->toBe(['limit' => '5']);

        DB::table('dashboard_widgets')->where('id', $widget->id)->update(['type' => 'App\\Missing\\Widget']);
        expect($widget->fresh()->settings)->toBe(['limit' => '5']);
    });
});

describe('widget placement', function (): void {
    it('finds the first free row below the tallest widget of a section', function (): void {
        $dashboard = $this->defaultDashboard();

        expect(DashboardWidget::nextFreeRow($dashboard->id, 'main'))->toBe(0);

        $this->createWidget($dashboard, ['y' => 0, 'h' => 3]);
        $this->createWidget($dashboard, ['y' => 1, 'h' => 1]);
        $this->createWidget($dashboard, ['y' => 9, 'h' => 4, 'section_slug' => 'elsewhere']);

        expect(DashboardWidget::nextFreeRow($dashboard->id, 'main'))->toBe(3);
    });

    it('stacks orphan widgets without overlap when the template changes', function (): void {
        $dashboard = Dashboard::create(['name' => 'split', 'template_key' => '2-columns']);

        $existing = $this->createWidget($dashboard, ['name' => 'left', 'section_slug' => 'left', 'y' => 0, 'h' => 2]);
        $tall = $this->createWidget($dashboard, ['name' => 'right tall', 'section_slug' => 'right', 'x' => 2, 'y' => 0, 'h' => 3]);
        $small = $this->createWidget($dashboard, ['name' => 'right small', 'section_slug' => 'right', 'x' => 2, 'y' => 3, 'h' => 1]);

        // `3-columns` keeps `left` and `right`: nothing is an orphan.
        $dashboard->update(['template_key' => '3-columns']);
        expect($tall->fresh()->section_slug)->toBe('right');

        // `flat-12` only has `main`: every widget moves there, one below the other.
        $dashboard->update(['template_key' => 'flat-12']);

        $rows = DashboardWidget::query()
            ->where('dashboard_id', $dashboard->id)
            ->orderBy('y')
            ->get()
            ->map(fn (DashboardWidget $widget): array => [$widget->section_slug, $widget->x, $widget->y, $widget->h])
            ->all();

        expect($rows)->toBe([
            ['main', 0, 0, 2],
            ['main', 0, 2, 3],
            ['main', 0, 5, 1],
        ])->and($existing->fresh()->name)->toBe('left')
            ->and($small->fresh()->y)->toBe(5);
    });
});
