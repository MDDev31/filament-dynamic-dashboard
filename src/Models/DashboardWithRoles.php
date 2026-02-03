<?php

namespace MDDev\DynamicDashboard\Models;

use Spatie\Permission\Traits\HasRoles;

/**
 * Spatie Permission-enabled dashboard variant.
 *
 * Extends the base Dashboard model with role-based visibility.
 * Use this model by extending it in your application when
 * `filament-dynamic-dashboard.use_spatie_permissions` is true.
 */
class DashboardWithRoles extends Dashboard
{
    use HasRoles;

    protected string $guard_name = 'web';
}
