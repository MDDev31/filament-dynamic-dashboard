<?php

/*
|--------------------------------------------------------------------------
| Dynamic Dashboard Configuration
|--------------------------------------------------------------------------
|
| dashboard_columns     – Responsive grid breakpoints for the dashboard layout.
| widget_columns        – Default column span applied to new widgets.
| use_spatie_permissions – When true, the ServiceProvider auto-swaps the
|                          Dashboard model with DashboardWithRoles (Spatie).
| models.dashboard      – Eloquent model implementing DynamicDashboardModel.
| models.widget         – Eloquent model implementing DynamicDashboardWidgetModel.
|
*/

use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;

return [
    'dashboard_columns' => ['sm' => 3, 'md' => 6, 'lg' => 12],
    'widget_columns' => 3,
    'use_spatie_permissions' => true,
    'models' => [
        'dashboard' => Dashboard::class,
        'widget' => DashboardWidget::class,
    ],
];
