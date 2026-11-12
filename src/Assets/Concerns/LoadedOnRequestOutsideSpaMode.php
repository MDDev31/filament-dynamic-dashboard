<?php

namespace MDDev\DynamicDashboard\Assets\Concerns;

use Filament\Support\Facades\FilamentView;

/**
 * Decides, when the layout is rendered, whether a canvas asset is added to
 * every panel page or only to the pages that need it.
 *
 * - Panel without SPA mode: loaded on request. The canvas view pulls the
 *   assets in itself, in the document head, so other pages never download them.
 * - Panel in SPA mode: loaded on every page, as Filament does by default. A
 *   `wire:navigate` visit shows the new page before late head scripts have
 *   finished loading, so the dashboard would otherwise stay blank while
 *   GridStack downloads on the first visit. The window is long-lived there
 *   anyway: the assets are fetched once per session either way.
 */
trait LoadedOnRequestOutsideSpaMode
{
    /**
     * Whether Filament should leave this asset out of its global asset list.
     */
    public function isLoadedOnRequest(): bool
    {
        return ! FilamentView::hasSpaMode();
    }
}
