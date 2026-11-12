<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use Filament\Widgets\StatsOverviewWidget;
use MDDev\DynamicDashboard\Concerns\HasEmptySettings;
use MDDev\DynamicDashboard\Concerns\HasSizeDefaults;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

/**
 * Minimal dynamic widget: no settings, default size (4 x 2).
 */
class TestWidget extends StatsOverviewWidget implements DynamicWidget
{
    use HasEmptySettings, HasSizeDefaults;

    /** @inheritDoc */
    public static function getWidgetLabel(): string
    {
        return 'Test widget';
    }

    /** @inheritDoc */
    protected function getStats(): array
    {
        return [];
    }
}
