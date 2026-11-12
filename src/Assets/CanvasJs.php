<?php

namespace MDDev\DynamicDashboard\Assets;

use Filament\Support\Assets\Js;
use MDDev\DynamicDashboard\Assets\Concerns\LoadedOnRequestOutsideSpaMode;

/**
 * Script used by the dashboard canvas (GridStack and the canvas component).
 */
class CanvasJs extends Js
{
    use LoadedOnRequestOutsideSpaMode;
}
