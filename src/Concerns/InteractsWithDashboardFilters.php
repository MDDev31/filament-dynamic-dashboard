<?php

namespace MDDev\DynamicDashboard\Concerns;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\On;

/**
 * Widget-side counterpart to the dashboard's page filters.
 *
 * Wraps Filament's InteractsWithPageFilters so `$this->pageFilters` behaves
 * exactly as in a standard Filament dashboard. Because dashboard widgets render
 * inside a GridStack `wire:ignore` region, Livewire's `#[Reactive]` propagation
 * never reaches them — so this trait also listens for the page's
 * `dynamic-dashboard:filters-updated` event and refreshes `$pageFilters`,
 * which triggers the widget's own re-render.
 *
 * Use this trait instead of InteractsWithPageFilters on any DynamicWidget that
 * needs to react to live filter-bar changes.
 */
trait InteractsWithDashboardFilters
{
    use InteractsWithPageFilters;

    /**
     * Sync the page filters when the dashboard broadcasts a change.
     *
     * @param  array<string, mixed>  $filters
     */
    #[On('dynamic-dashboard:filters-updated')]
    public function syncDashboardPageFilters(array $filters): void
    {
        $this->pageFilters = $filters;
    }
}
