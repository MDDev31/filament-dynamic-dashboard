<?php

use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;

it('drops the personal dashboards of a deleted user and keeps the global ones', function (): void {
    $alice = $this->signIn();

    $personal = Dashboard::create(['name' => 'personal', 'is_personal' => true]);
    $global = Dashboard::create(['name' => 'global']);
    $this->createWidget($personal);

    $alice->delete();

    expect($personal->fresh())->toBeNull()
        ->and(DashboardWidget::query()->count())->toBe(0)
        ->and($global->fresh())->not->toBeNull()
        ->and($global->fresh()->created_by)->toBeNull();
});
