<?php

namespace MDDev\DynamicDashboard\Models;

use Spatie\Permission\Traits\HasRoles;

/**
 * Spatie Permission-enabled dashboard variant.
 *
 * Automatically swapped in by the ServiceProvider when
 * `filament-dynamic-dashboard.use_spatie_permissions` is true
 * and no custom model is configured.
 */
class DashboardWithRoles extends Dashboard
{
    use HasRoles;

    protected string $guard_name = 'web';
}
