<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use MDDev\DynamicDashboard\Pages\DynamicDashboard;

/**
 * Plain dashboard page, as an application would declare it.
 */
class TestDashboardPage extends DynamicDashboard
{
    protected static ?string $slug = 'test-dashboard';

    public static bool $editable = true;

    /** @inheritDoc */
    public static function canEdit(): bool
    {
        return static::$editable;
    }
}
