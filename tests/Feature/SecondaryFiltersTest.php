<?php

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Livewire\DashboardManager;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Tests\Fixtures\BareDashboardPage;
use MDDev\DynamicDashboard\Tests\Fixtures\SecondaryFiltersPage;

/*
 * SecondaryFiltersPage declares seven filters, in this order:
 * keyword, period, brand, supplier, prescriber, age_bracket (multiple select), in_stock (toggle).
 */

/**
 * Mount the fixture page with the given secondary filters declaration.
 *
 * @param  array<int|string, string|array<int, string>>  $secondary
 * @param  array<string, mixed>  $queryFilters  Filters passed in the URL, as on a shared link.
 */
function secondaryFiltersPage(array $secondary, array $queryFilters = []): Testable
{
    SecondaryFiltersPage::$secondary = $secondary;

    return $queryFilters === []
        ? Livewire::test(SecondaryFiltersPage::class)
        : Livewire::withQueryParams(['filters' => $queryFilters])->test(SecondaryFiltersPage::class);
}

/**
 * The rendered filter bar (everything before the widgets canvas), on one line.
 */
function filterBarHtml(Testable $page): string
{
    $html = $page->html();
    $end = strpos($html, 'dashboard-canvas');

    return preg_replace('/\s+/', ' ', $end === false ? $html : substr($html, 0, $end));
}

/**
 * The "More filters" control as rendered, or null when it is not there.
 *
 * @return array{label: string, disabled: bool}|null
 */
function moreFiltersToggle(Testable $page): ?array
{
    if (! preg_match('/<button\b([^>]*x-on:click="\$wire\.secondaryFiltersOpen = ! \$wire\.secondaryFiltersOpen"[^>]*)>(.*?)<\/button>/s', filterBarHtml($page), $match)) {
        return null;
    }

    return [
        'label' => trim(html_entity_decode(strip_tags($match[2]))),
        'disabled' => (bool) preg_match('/\sdisabled(=|\s|$)/', $match[1]),
    ];
}

/**
 * Names of the filters placed in the secondary area, displayed or not.
 *
 * @return list<string>
 */
function secondaryAreaFilters(Testable $page, bool $withHidden = true): array
{
    $area = $page->instance()->getSchema('filtersForm')->getComponent('secondaryFilters', withHidden: true);

    if ($area === null) {
        return [];
    }

    return array_values(array_map(
        fn ($field): string => $field->getName(),
        $area->getChildSchema()->getFlatFields(withHidden: $withHidden),
    ));
}

/**
 * Column count of the main row's grid at the `lg` breakpoint.
 */
function mainRowColumns(Testable $page): int
{
    preg_match('/--cols-lg:\s*repeat\((\d+),/', filterBarHtml($page), $match);

    return (int) ($match[1] ?? 0);
}

describe('declaration', function (): void {
    it('renders the bar as before when the page declares no secondary filters', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage([]);

        expect(moreFiltersToggle($page))->toBeNull()
            ->and(secondaryAreaFilters($page))->toBe([])
            ->and(filterBarHtml($page))->not->toContain('filtersForm.secondaryFilters')
            ->and(mainRowColumns($page))->toBe(7)
            ->and($page->get('secondaryFiltersOpen'))->toBeFalse();

        // Same for a page that does not override the method at all.
        $bare = Livewire::test(BareDashboardPage::class);

        expect(moreFiltersToggle($bare))->toBeNull()
            ->and(filterBarHtml($bare))->not->toContain('filtersForm.secondaryFilters')
            ->and(mainRowColumns($bare))->toBe(4);
    });

    it('moves a flat list of filters to the secondary area', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'supplier']);
        $html = filterBarHtml($page);

        expect(moreFiltersToggle($page))->toBe(['label' => 'More filters', 'disabled' => false])
            ->and(secondaryAreaFilters($page))->toBe(['brand', 'supplier'])
            // The main row is sized from the five primary filters only.
            ->and(mainRowColumns($page))->toBe(5)
            // The area is bound to the Livewire property and uses a fixed responsive grid.
            ->and($html)->toContain('$wire.secondaryFiltersOpen')
            ->and($html)->toContain('--cols-sm: repeat(2, minmax(0, 1fr)); --cols-lg: repeat(4, minmax(0, 1fr))')
            ->and($page->get('secondaryFiltersOpen'))->toBeFalse();
    });

    it('never drops below four columns in the main row', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'supplier', 'prescriber', 'age_bracket', 'in_stock']);

        expect(mainRowColumns($page))->toBe(4)
            ->and(secondaryAreaFilters($page))->toHaveCount(5);
    });

    it('renders each group under its heading', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage([
            'Customer' => ['prescriber', 'age_bracket'],
            'Product' => ['brand', 'supplier'],
        ]);
        $html = filterBarHtml($page);

        expect(secondaryAreaFilters($page))->toBe(['prescriber', 'age_bracket', 'brand', 'supplier'])
            ->and(mainRowColumns($page))->toBe(4)
            ->and(strpos($html, 'Customer'))->toBeLessThan(strpos($html, 'Prescriber'))
            ->and(strpos($html, 'Age bracket'))->toBeLessThan(strpos($html, 'Product'))
            ->and(strpos($html, 'Product'))->toBeLessThan(strpos($html, 'Brand'));
    });

    it('shows no heading for a flat list', function (): void {
        $this->signIn();

        expect(filterBarHtml(secondaryFiltersPage(['brand'])))->not->toContain('fi-sc-text');
    });

    it('ignores names that match no filter', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'unknown_filter']);

        expect(secondaryAreaFilters($page))->toBe(['brand'])
            ->and(mainRowColumns($page))->toBe(6);

        // A group made only of unknown names disappears with its heading.
        $page = secondaryFiltersPage(['Ghost group' => ['nope', 'neither'], 'Product' => ['supplier']]);

        expect(secondaryAreaFilters($page))->toBe(['supplier'])
            ->and(filterBarHtml($page))->not->toContain('Ghost group');

        // Only unknown names: nothing is secondary, the bar is the default one.
        $page = secondaryFiltersPage(['nope']);

        expect(moreFiltersToggle($page))->toBeNull()
            ->and(mainRowColumns($page))->toBe(7);
    });

    it('accepts loose and grouped names together and keeps a repeated name once', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'Customer' => ['prescriber', 'brand'], 'supplier']);

        expect(secondaryAreaFilters($page))->toBe(['brand', 'supplier', 'prescriber']);
    });
});

describe('secondary filters stay ordinary filters', function (): void {
    it('keeps them in the filter state exactly like primary filters', function (): void {
        $this->signIn();

        $expected = secondaryFiltersPage([])->get('filters');
        $actual = secondaryFiltersPage(['Product' => ['brand', 'supplier'], 'Customer' => ['age_bracket', 'in_stock']])->get('filters');

        ksort($expected);
        ksort($actual);

        expect($actual)->toBe($expected)
            ->and($actual)->toHaveKeys(['keyword', 'brand', 'supplier', 'age_bracket', 'in_stock']);
    });

    it('applies their default values and resets them with the reset button', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['filters' => ['keyword' => 'default keyword', 'supplier' => 'default supplier']]);

        secondaryFiltersPage(['brand', 'supplier'])
            ->assertSet('filters.keyword', 'default keyword')
            ->assertSet('filters.supplier', 'default supplier')
            ->set('filters.supplier', 'changed')
            ->set('filters.brand', 'typed')
            ->set('filters.keyword', 'changed')
            ->call('resetFilters')
            ->assertSet('filters.keyword', 'default keyword')
            ->assertSet('filters.supplier', 'default supplier')
            ->assertSet('filters.brand', null);
    });

    it('still offers them in the manager for visibility and default values', function (): void {
        $this->signIn();
        SecondaryFiltersPage::$secondary = ['brand', 'supplier'];

        $manager = Livewire::test(DashboardManager::class, [
            'pageClass' => SecondaryFiltersPage::class,
            'currentDashboardId' => $this->defaultDashboard()->id,
        ])->instance();

        $filters = (new ReflectionMethod($manager, 'getPageDynamicFilters'))->invoke($manager);

        expect(array_map(fn ($field): string => $field->getName(), $filters))
            ->toBe(['keyword', 'period', 'brand', 'supplier', 'prescriber', 'age_bracket', 'in_stock']);
    });
});

describe('active count', function (): void {
    it('shows how many secondary filters have a value', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'supplier', 'age_bracket', 'in_stock']);

        expect(moreFiltersToggle($page)['label'])->toBe('More filters');

        $page->set('filters.brand', 'acme');
        expect(moreFiltersToggle($page)['label'])->toBe('More filters (1)');

        $page->set('filters.age_bracket', ['young', 'senior']);
        expect(moreFiltersToggle($page)['label'])->toBe('More filters (2)');

        $page->set('filters.in_stock', true);
        expect(moreFiltersToggle($page)['label'])->toBe('More filters (3)');

        $page->set('filters.brand', '')->set('filters.age_bracket', [])->set('filters.in_stock', false);
        expect(moreFiltersToggle($page)['label'])->toBe('More filters');
    });

    it('does not count primary filters, blank values or an unchecked toggle', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'age_bracket', 'in_stock'])
            ->set('filters.keyword', 'a primary filter')
            ->set('filters.brand', '   ')
            ->set('filters.age_bracket', [])
            ->set('filters.in_stock', false);

        expect(moreFiltersToggle($page))->toBe(['label' => 'More filters', 'disabled' => false])
            ->and($page->get('secondaryFiltersOpen'))->toBeFalse();
    });
});

describe('open state', function (): void {
    it('is open on first load when a default value targets a secondary filter', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['filters' => ['brand' => 'acme']]);

        $page = secondaryFiltersPage(['brand', 'supplier'])->assertSet('secondaryFiltersOpen', true);

        expect(moreFiltersToggle($page))->toBe(['label' => 'More filters (1)', 'disabled' => true]);
    });

    it('stays closed on first load when only primary filters have a default value', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['filters' => ['keyword' => 'acme']]);

        secondaryFiltersPage(['brand', 'supplier'])->assertSet('secondaryFiltersOpen', false);
    });

    it('is open on load when a secondary filter comes from the URL', function (): void {
        $this->signIn();

        // A first visit stores the filters in the session. Without that entry the
        // page applies the dashboard defaults instead of reading the URL.
        secondaryFiltersPage(['brand', 'supplier'])->assertSet('secondaryFiltersOpen', false);

        secondaryFiltersPage(['brand', 'supplier'], queryFilters: ['supplier' => 'acme'])
            ->assertSet('filters.supplier', 'acme')
            ->assertSet('secondaryFiltersOpen', true);
    });

    it('opens when a reset brings back a secondary default value', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['filters' => ['brand' => 'acme']]);

        secondaryFiltersPage(['brand', 'supplier'])
            ->set('filters.brand', '')
            ->set('secondaryFiltersOpen', false)
            ->assertSet('secondaryFiltersOpen', false)
            ->call('resetFilters')
            ->assertSet('filters.brand', 'acme')
            ->assertSet('secondaryFiltersOpen', true);
    });

    it('is open after switching to a dashboard whose defaults target a secondary filter', function (): void {
        $this->signIn();

        $second = Dashboard::create(['name' => 'Second', 'filters' => ['supplier' => 'acme']]);

        secondaryFiltersPage(['brand', 'supplier'])
            ->assertSet('secondaryFiltersOpen', false)
            ->call('mountAction', 'selectDashboard_'.$second->id)
            ->assertRedirect(SecondaryFiltersPage::getUrl());

        // The redirect lands on a fresh page instance showing the second dashboard.
        secondaryFiltersPage(['brand', 'supplier'])
            ->assertSet('currentDashboardId', $second->id)
            ->assertSet('filters.supplier', 'acme')
            ->assertSet('secondaryFiltersOpen', true);
    });

    it('survives re-renders, open or closed', function (): void {
        $this->signIn();

        // Opened by the user, then a primary filter changes: every change re-renders the page.
        $page = secondaryFiltersPage(['brand', 'supplier'])
            ->set('secondaryFiltersOpen', true)
            ->set('filters.keyword', 'first')
            ->set('filters.period', 'second')
            ->call('$refresh')
            ->assertSet('secondaryFiltersOpen', true);

        expect(secondaryAreaFilters($page))->toBe(['brand', 'supplier']);

        // Closed again by the user: later re-renders must not reopen it.
        $page->set('secondaryFiltersOpen', false)
            ->set('filters.keyword', 'third')
            ->call('$refresh')
            ->assertSet('secondaryFiltersOpen', false);
    });

    it('stays open while the user works in it, including after the last value is cleared', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'supplier'])
            ->set('secondaryFiltersOpen', true)
            ->set('filters.brand', 'a')
            ->set('filters.brand', 'ac')
            ->set('filters.supplier', 'x')
            ->assertSet('secondaryFiltersOpen', true);

        expect(moreFiltersToggle($page))->toBe(['label' => 'More filters (2)', 'disabled' => true]);

        $page->set('filters.brand', '')->set('filters.supplier', '')->assertSet('secondaryFiltersOpen', true);

        expect(moreFiltersToggle($page))->toBe(['label' => 'More filters', 'disabled' => false]);
    });

    it('cannot be closed while a secondary filter has a value', function (): void {
        $this->signIn();

        $page = secondaryFiltersPage(['brand', 'supplier'])
            ->set('filters.brand', 'acme')
            ->assertSet('secondaryFiltersOpen', true)
            // What a click on the (disabled) control would send.
            ->set('secondaryFiltersOpen', false)
            ->assertSet('secondaryFiltersOpen', true);

        expect(moreFiltersToggle($page)['disabled'])->toBeTrue();
    });
});

describe('per-dashboard visibility', function (): void {
    it('hides a secondary filter the dashboard does not display and does not count it', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update([
            'display_filters' => ['supplier' => false],
            'filters' => ['supplier' => 'hidden but set'],
        ]);

        $page = secondaryFiltersPage(['brand', 'supplier']);

        expect(secondaryAreaFilters($page, withHidden: false))->toBe(['brand'])
            ->and(moreFiltersToggle($page))->toBe(['label' => 'More filters', 'disabled' => false])
            ->and($page->get('secondaryFiltersOpen'))->toBeFalse()
            // The hidden filter keeps its value, as hidden primary filters do.
            ->and($page->get('filters.supplier'))->toBe('hidden but set');
    });

    it('renders no toggle when the dashboard displays none of the secondary filters', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['display_filters' => ['brand' => false, 'supplier' => false]]);

        $page = secondaryFiltersPage(['Product' => ['brand', 'supplier']]);

        expect(moreFiltersToggle($page))->toBeNull()
            ->and(secondaryAreaFilters($page, withHidden: false))->toBe([])
            ->and(filterBarHtml($page))->not->toContain('Product')
            // The main row still counts its own five filters.
            ->and(mainRowColumns($page))->toBe(5);
    });

    it('keeps the secondary filters reachable when they are the only ones displayed', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['display_filters' => [
            'keyword' => false, 'period' => false, 'prescriber' => false, 'age_bracket' => false, 'in_stock' => false,
        ]]);

        $page = secondaryFiltersPage(['brand', 'supplier']);

        expect(moreFiltersToggle($page))->toBe(['label' => 'More filters', 'disabled' => false])
            ->and(secondaryAreaFilters($page, withHidden: false))->toBe(['brand', 'supplier'])
            ->and(filterBarHtml($page))->toContain('fi-section');
    });

    it('drops the heading of a group whose filters are all hidden', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['display_filters' => ['prescriber' => false, 'age_bracket' => false]]);

        $html = filterBarHtml(secondaryFiltersPage([
            'Customer' => ['prescriber', 'age_bracket'],
            'Product' => ['brand', 'supplier'],
        ]));

        expect($html)->not->toContain('Customer')
            ->and($html)->toContain('Product');
    });
});

it('translates the control in every locale', function (): void {
    $english = require __DIR__.'/../../resources/lang/en/dashboard.php';

    foreach (glob(__DIR__.'/../../resources/lang/*', GLOB_ONLYDIR) as $localePath) {
        $locale = basename($localePath);
        $translations = require $localePath.'/dashboard.php';

        expect(str_contains($translations['more_filters_count'], ':count'))->toBeTrue($locale.' lost the :count placeholder')
            ->and(str_starts_with($translations['more_filters_count'], $translations['more_filters']))->toBeTrue($locale.' uses two different labels');

        if ($locale !== 'en') {
            expect($translations['more_filters'])->not->toBe($english['more_filters'], $locale.' is still in English');
        }
    }
});
