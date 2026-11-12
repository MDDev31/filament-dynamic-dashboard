<?php

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Livewire\Livewire;
use MDDev\DynamicDashboard\Assets\CanvasCss;
use MDDev\DynamicDashboard\Assets\CanvasJs;
use MDDev\DynamicDashboard\Tests\Fixtures\PlainPage;
use MDDev\DynamicDashboard\Tests\Fixtures\TestDashboardPage;

const CANVAS_ASSETS = [
    'js/mddev31/filament-dynamic-dashboard/gridstack.js',
    'js/mddev31/filament-dynamic-dashboard/dashboard.js',
    'css/mddev31/filament-dynamic-dashboard/gridstack.css',
    'css/mddev31/filament-dynamic-dashboard/dashboard.css',
];

/**
 * Fetch a panel page the way a browser would: as its own request.
 *
 * Livewire collects the `@assets` of a page in static state that it resets at
 * the start of every real request. Several requests share one PHP process
 * here, so that reset is done by hand.
 */
function fetchPage(string $url): string
{
    Livewire::flushState();

    return test()->get($url)->assertOk()->getContent();
}

it('registers the canvas assets so that filament:assets publishes them', function (): void {
    $package = 'mddev31/filament-dynamic-dashboard';

    $scripts = collect(FilamentAsset::getScripts([$package]))->keyBy(fn (CanvasJs $asset): string => $asset->getId());
    $styles = collect(FilamentAsset::getStyles([$package]))->keyBy(fn (CanvasCss $asset): string => $asset->getId());

    expect($scripts->keys()->all())->toBe(['gridstack', 'dashboard'])
        ->and($styles->keys()->all())->toBe(['gridstack', 'dashboard']);

    foreach ([...$scripts, ...$styles] as $asset) {
        expect($asset->getPath())->toBeFile();
    }
});

it('loads the canvas assets on request outside SPA mode and globally in SPA mode', function (): void {
    $script = FilamentAsset::getScripts(['mddev31/filament-dynamic-dashboard'])[0];
    $style = FilamentAsset::getStyles(['mddev31/filament-dynamic-dashboard'])[0];

    expect($script->isLoadedOnRequest())->toBeTrue()
        ->and($style->isLoadedOnRequest())->toBeTrue();

    FilamentView::spa();

    expect($script->isLoadedOnRequest())->toBeFalse()
        ->and($style->isLoadedOnRequest())->toBeFalse();
});

it('adds the canvas assets to the head of dashboard pages only outside SPA mode', function (): void {
    $this->signIn();

    $dashboard = fetchPage(TestDashboardPage::getUrl());
    $plain = fetchPage(PlainPage::getUrl());

    $head = strstr($dashboard, '</head>', before_needle: true);

    foreach (CANVAS_ASSETS as $asset) {
        expect(substr_count($dashboard, $asset))->toBe(1, $asset.' on the dashboard page')
            ->and(substr_count($head, $asset))->toBe(1, $asset.' in the head')
            ->and(substr_count($plain, $asset))->toBe(0, $asset.' on another page');
    }

    // GridStack must be defined before the component that uses it.
    expect(strpos($dashboard, CANVAS_ASSETS[0]))->toBeLessThan(strpos($dashboard, CANVAS_ASSETS[1]));
});

it('keeps the canvas assets on every panel page in SPA mode, exactly once', function (): void {
    Filament::getPanel('test')->spa();
    $this->signIn();

    $dashboard = fetchPage(TestDashboardPage::getUrl());
    $plain = fetchPage(PlainPage::getUrl());

    foreach (CANVAS_ASSETS as $asset) {
        expect(substr_count($dashboard, $asset))->toBe(1, $asset.' on the dashboard page')
            ->and(substr_count($plain, $asset))->toBe(1, $asset.' on another page');
    }
});
