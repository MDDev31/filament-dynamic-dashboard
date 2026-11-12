<?php

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Tests\Fixtures\BareDashboardPage;
use MDDev\DynamicDashboard\Tests\Fixtures\SecondaryFiltersPage;

/*
 * Below 768px the filter bar folds into one row. The folding itself is CSS
 * (checked in a browser, not here); these tests cover what the page renders
 * for it: the hooks the stylesheet relies on, the "Filters" control and its
 * count, the open state, and the opt-out.
 *
 * SecondaryFiltersPage declares seven filters, in this order:
 * keyword, period, brand, supplier, prescriber, age_bracket (multiple select), in_stock (toggle).
 */

/**
 * The rendered page, on one line.
 */
function smallScreenPageHtml(Testable $page): string
{
    return preg_replace('/\s+/', ' ', $page->html());
}

/**
 * The "Filters" control as rendered, or null when it is not there.
 *
 * @return array{label: string, attributes: string}|null
 */
function smallScreenFiltersToggle(Testable $page): ?array
{
    if (! preg_match('/<button\b([^>]*x-on:click="\$wire\.smallScreenFiltersOpen = ! \$wire\.smallScreenFiltersOpen"[^>]*)>(.*?)<\/button>/s', smallScreenPageHtml($page), $match)) {
        return null;
    }

    return [
        'label' => trim(html_entity_decode(strip_tags($match[2]))),
        'attributes' => $match[1],
    ];
}

describe('markup', function (): void {
    it('renders the control and the hooks the stylesheet folds the bar with', function (): void {
        $this->signIn();

        SecondaryFiltersPage::$secondary = ['brand', 'supplier'];
        $page = Livewire::test(SecondaryFiltersPage::class);
        $html = smallScreenPageHtml($page);
        $toggle = smallScreenFiltersToggle($page);

        expect($toggle)->not->toBeNull()
            ->and($toggle['label'])->toBe('Filters')
            ->and($toggle['attributes'])->toContain('dd-filters-toggle')
            ->and($toggle['attributes'])->toContain('x-bind:aria-expanded="$wire.smallScreenFiltersOpen ? \'true\' : \'false\'"')
            // the bar follows the Livewire flag in the browser
            ->and($html)->toContain('x-bind:class="{ \'dd-filters-open\': $wire.smallScreenFiltersOpen }"')
            ->and($html)->toMatch('/class="[^"]*\bdd-filters\b(?!-)[^"]*"/')
            ->and($html)->toMatch('/class="[^"]*\bdd-filters-bar\b[^"]*"/')
            // what a folded bar hides besides the main row
            ->and($html)->toMatch('/class="[^"]*\bdd-filters-more\b[^"]*"/')
            ->and($html)->toMatch('/class="[^"]*\bdd-filters-secondary\b[^"]*"/');
    });

    it('keeps the control hidden unless the stylesheet reveals it', function (): void {
        $this->signIn();

        $toggle = smallScreenFiltersToggle(Livewire::test(BareDashboardPage::class));

        // Hidden inline: with an outdated published stylesheet the button
        // does not appear and every filter stays visible, as before.
        expect($toggle['attributes'])->toMatch('/style="[^"]*display:\s*none/');

        $css = file_get_contents(__DIR__.'/../../resources/css/dashboard.css');
        $rules = substr($css, strpos($css, 'Filter bar on small screens'));

        expect($rules)->toContain('@media (max-width: 47.9375rem)')
            ->and($rules)->toMatch('/\.dd-filters \.dd-filters-toggle\s*\{[^}]*display:\s*inline-flex\s*!important/')
            ->and($rules)->toMatch('/\.dd-filters:not\(\.dd-filters-open\)[^{]*\{\s*display:\s*none/')
            // nothing about the bar outside the media query
            ->and(substr($css, 0, strpos($css, 'Filter bar on small screens')))->not->toContain('dd-filters');
    });

    it('renders no control when the dashboard displays no filter', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['display_filters' => ['keyword' => false]]);

        expect(smallScreenFiltersToggle(Livewire::test(BareDashboardPage::class)))->toBeNull();
    });
});

describe('active count', function (): void {
    it('counts primary and secondary filters that have a value', function (): void {
        $this->signIn();

        SecondaryFiltersPage::$secondary = ['brand', 'age_bracket', 'in_stock'];
        $page = Livewire::test(SecondaryFiltersPage::class);

        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters');

        $page->set('filters.keyword', 'shoes');
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters (1)');

        $page->set('filters.brand', 'acme')->set('filters.age_bracket', ['young', 'senior']);
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters (3)');

        $page->set('filters.in_stock', true);
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters (4)');

        $page->set('filters.keyword', '   ')
            ->set('filters.brand', '')
            ->set('filters.age_bracket', [])
            ->set('filters.in_stock', false);
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters');
    });

    it('counts default values and follows a reset', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update(['filters' => ['keyword' => 'default keyword', 'period' => 'last month']]);

        $page = Livewire::test(SecondaryFiltersPage::class);
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters (2)');

        $page->set('filters.period', '')->set('filters.brand', 'acme')->set('filters.supplier', 'north');
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters (3)');

        $page->call('resetFilters');
        expect(smallScreenFiltersToggle($page)['label'])->toBe('Filters (2)');
    });

    it('does not count a filter the dashboard does not display', function (): void {
        $this->signIn();

        $this->defaultDashboard()->update([
            'display_filters' => ['supplier' => false],
            'filters' => ['supplier' => 'hidden but set', 'brand' => 'acme'],
        ]);

        expect(smallScreenFiltersToggle(Livewire::test(SecondaryFiltersPage::class))['label'])->toBe('Filters (1)');
    });
});

describe('open state', function (): void {
    it('starts folded, even when filters have a value', function (): void {
        $this->signIn();

        SecondaryFiltersPage::$secondary = ['brand'];
        $this->defaultDashboard()->update(['filters' => ['keyword' => 'default keyword', 'brand' => 'acme']]);

        $page = Livewire::test(SecondaryFiltersPage::class);

        expect($page->get('smallScreenFiltersOpen'))->toBeFalse()
            // the secondary area keeps its own rule: open while one of its filters has a value
            ->and($page->get('secondaryFiltersOpen'))->toBeTrue();
    });

    it('survives re-renders, open or folded', function (): void {
        $this->signIn();

        $page = Livewire::test(SecondaryFiltersPage::class)
            // what the button does in the browser, sent along with the next request
            ->set('smallScreenFiltersOpen', true)
            ->set('filters.keyword', 'shoes')
            ->call('resetFilters');

        expect($page->get('smallScreenFiltersOpen'))->toBeTrue();

        $page->set('smallScreenFiltersOpen', false)->set('filters.keyword', 'boots');

        expect($page->get('smallScreenFiltersOpen'))->toBeFalse()
            ->and(smallScreenFiltersToggle($page)['label'])->toBe('Filters (1)');
    });

    it('leaves the filters in the form whatever the state', function (): void {
        $this->signIn();

        $page = Livewire::test(SecondaryFiltersPage::class);
        $names = fn (): array => array_keys($page->instance()->getSchema('filtersForm')->getFlatFields(withHidden: false));

        expect($names())->toHaveCount(7);

        $page->set('smallScreenFiltersOpen', true);

        expect($names())->toHaveCount(7);
    });
});

describe('opt-out', function (): void {
    it('renders the bar as it was when the page turns the folding off', function (): void {
        $this->signIn();

        SecondaryFiltersPage::$secondary = ['brand'];
        SecondaryFiltersPage::$collapsesOnSmallScreens = false;

        $page = Livewire::test(SecondaryFiltersPage::class)->set('filters.keyword', 'shoes');
        $html = smallScreenPageHtml($page);

        expect(smallScreenFiltersToggle($page))->toBeNull()
            ->and($html)->not->toContain('dd-filters-toggle')
            ->and($html)->not->toContain('dd-filters-open')
            ->and($html)->not->toMatch('/class="[^"]*\bdd-filters\b(?!-)[^"]*"/')
            // the filters themselves are untouched
            ->and($page->get('filters.keyword'))->toBe('shoes')
            ->and(array_keys($page->instance()->getSchema('filtersForm')->getFlatFields(withHidden: false)))->toHaveCount(7);
    });
});

it('translates the control in every locale', function (): void {
    $english = require __DIR__.'/../../resources/lang/en/dashboard.php';
    // Dutch uses the same word as English.
    $sameAsEnglish = ['en', 'nl'];

    foreach (glob(__DIR__.'/../../resources/lang/*', GLOB_ONLYDIR) as $localePath) {
        $locale = basename($localePath);
        $translations = require $localePath.'/dashboard.php';

        expect(str_contains($translations['filters_count'], ':count'))->toBeTrue($locale.' lost the :count placeholder')
            ->and(str_starts_with($translations['filters_count'], $translations['filters']))->toBeTrue($locale.' uses two different labels')
            ->and($translations['filters'])->not->toBe($translations['more_filters'], $locale.' cannot tell the two controls apart');

        if (! in_array($locale, $sameAsEnglish, true)) {
            expect($translations['filters'])->not->toBe($english['filters'], $locale.' is still in English');
        }
    }
});
