<?php

namespace MDDev\DynamicDashboard\Concerns;

/**
 * Drop-in empty implementations of the two settings methods on the
 * DynamicWidget contract.
 *
 * Use this on widgets that don't have any user-configurable settings, so the
 * widget class doesn't need to repeat empty-array boilerplate.
 */
trait HasEmptySettings
{
    /** @inheritDoc */
    public static function getSettingsFormSchema(): array
    {
        return [];
    }

    /** @inheritDoc */
    public static function getSettingsCasts(): array
    {
        return [];
    }
}
