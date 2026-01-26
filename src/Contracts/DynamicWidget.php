<?php

namespace MDDev\DynamicDashboard\Contracts;

use Filament\Schemas\Components\Component;
use Filament\Widgets\Widget;

/**
 * Contract for Filament widgets that can be dynamically added to dashboards.
 *
 * @mixin Widget
 */
interface DynamicWidget
{
    /**
     * Get the display name of the widget for selection.
     */
    public static function getWidgetLabel(): string;

    /**
     * Get the Filament Schema components for the widget settings form.
     *
     * @return array<Component>
     */
    public static function getSettingsFormSchema(): array;

    /**
     * Define the casts for the settings parameters.
     *
     * @return array<string, string>
     */
    public static function getSettingsCasts(): array;
}
