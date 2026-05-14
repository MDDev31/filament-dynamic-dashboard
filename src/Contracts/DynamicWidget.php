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

    /**
     * Default width of a new widget instance, in grid columns.
     *
     * Prefixed to avoid clashing with parent Filament widget methods such as
     * Filament chart widgets' instance method `getMaxHeight()`. PHP forbids
     * overriding an instance method with a static method of the same name.
     */
    public static function getDynamicDashboardDefaultWidth(): int;

    /**
     * Default height of a new widget instance, in grid rows.
     */
    public static function getDynamicDashboardDefaultHeight(): int;

    /**
     * Minimum width the user can resize this widget to, in grid columns.
     * Return the same value as getDynamicDashboardMaxWidth() to lock width.
     */
    public static function getDynamicDashboardMinWidth(): int;

    /**
     * Maximum width the user can resize this widget to, in grid columns.
     * Return the same value as getDynamicDashboardMinWidth() to lock width.
     */
    public static function getDynamicDashboardMaxWidth(): int;

    /**
     * Minimum height the user can resize this widget to, in grid rows.
     * Return the same value as getDynamicDashboardMaxHeight() to lock height.
     */
    public static function getDynamicDashboardMinHeight(): int;

    /**
     * Maximum height the user can resize this widget to, in grid rows.
     * Return the same value as getDynamicDashboardMinHeight() to lock height.
     */
    public static function getDynamicDashboardMaxHeight(): int;
}
