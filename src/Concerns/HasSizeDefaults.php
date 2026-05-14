<?php

namespace MDDev\DynamicDashboard\Concerns;

/**
 * Drop-in defaults for the size methods on the DynamicWidget contract.
 *
 * Override any of the methods on the widget class to constrain that axis.
 * `min == max` on an axis means the widget is not resizable on that axis.
 *
 * Method names carry the `DynamicDashboard` prefix to avoid collisions with
 * parent Filament widget methods (e.g. chart widgets' instance method
 * `getMaxHeight()` which returns a CSS height).
 */
trait HasSizeDefaults
{
    /** @inheritDoc */
    public static function getDynamicDashboardDefaultWidth(): int
    {
        return 4;
    }

    /** @inheritDoc */
    public static function getDynamicDashboardDefaultHeight(): int
    {
        return 1;
    }

    /** @inheritDoc */
    public static function getDynamicDashboardMinWidth(): int
    {
        return 1;
    }

    /** @inheritDoc */
    public static function getDynamicDashboardMaxWidth(): int
    {
        return 12;
    }

    /** @inheritDoc */
    public static function getDynamicDashboardMinHeight(): int
    {
        return 1;
    }

    /** @inheritDoc */
    public static function getDynamicDashboardMaxHeight(): int
    {
        return 12;
    }
}
