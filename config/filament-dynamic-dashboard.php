<?php

/*
|--------------------------------------------------------------------------
| Dynamic Dashboard Configuration
|--------------------------------------------------------------------------
|
| dashboard_columns     – Responsive grid breakpoints for the dashboard layout.
| widget_columns        – Default column span applied to new widgets.
| use_spatie_permissions – When true, enables Spatie role-based dashboard visibility.
|
*/

return [
    'dashboard_columns' => ['sm' => 3, 'md' => 6, 'lg' => 12],
    'widget_columns' => 3,
    'use_spatie_permissions' => false,
];
