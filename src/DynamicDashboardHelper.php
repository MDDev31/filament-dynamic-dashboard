<?php

namespace MDDev\DynamicDashboard;

use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardModel;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardWidgetModel;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;

/**
 * Resolves the configured Eloquent model classes for dashboards and widgets.
 */
class DynamicDashboardHelper
{
    /**
     * Get the Dashboard model class.
     *
     * @return class-string<DynamicDashboardModel>
     */
    public static function DashboardModel(): string
    {
        return config('filament-dynamic-dashboard.models.dashboard', Dashboard::class);
    }

    /**
     * Get the DashboardWidget model class.
     *
     * @return class-string<DynamicDashboardWidgetModel>
     */
    public static function WidgetModel(): string
    {
        return config('filament-dynamic-dashboard.models.widget', DashboardWidget::class);
    }
}
