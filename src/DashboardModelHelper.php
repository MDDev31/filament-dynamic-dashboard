<?php

namespace MDDev\DynamicDashboard;

use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWithRoles;

class DashboardModelHelper
{
    /**
     * Get the Dashboard model class based on Spatie permission config.
     *
     * @return class-string<Dashboard>
     */
    public static function model(): string
    {
        return config('filament-dynamic-dashboard.use_spatie_permissions')
            ? DashboardWithRoles::class
            : Dashboard::class;
    }
}
