<?php

namespace MDDev\DynamicDashboard\Assets;

use Filament\Support\Assets\Css;
use MDDev\DynamicDashboard\Assets\Concerns\LoadedOnRequestOutsideSpaMode;

/**
 * Stylesheet used by the dashboard canvas (GridStack and the canvas itself).
 */
class CanvasCss extends Css
{
    use LoadedOnRequestOutsideSpaMode;
}
