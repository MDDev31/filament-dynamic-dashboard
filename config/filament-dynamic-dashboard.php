<?php

/*
|--------------------------------------------------------------------------
| Dynamic Dashboard Configuration
|--------------------------------------------------------------------------
|
| template_paths        – Extra directories scanned for layout JSON templates.
|                         The package's own preset directory is always loaded
|                         first; user paths override on matching `key`.
| default_template      – Key of the template used when a dashboard has none
|                         set (and as the fallback when a referenced key is
|                         missing).
| disabled_templates    – Template keys to hide from the dashboard create/edit
|                         selector. Dashboards that already reference a
|                         disabled template still render correctly — this
|                         only controls what appears in the UI.
| use_spatie_permissions – When true, enables Spatie role-based dashboard
|                         visibility.
| default_personal      – When true, new dashboards default to personal
|                         (visible only to their creator). When false, new
|                         dashboards default to global (visible to everyone
|                         with page access).
|
*/

return [
    'template_paths' => [
        resource_path('dashboard-templates'),
    ],
    'default_template' => 'flat-12',
    'disabled_templates' => [
        // e.g. 'flat-24-dense', 'kpi-strip-chart',
    ],
    'use_spatie_permissions' => false,
    'default_personal' => false,
];
