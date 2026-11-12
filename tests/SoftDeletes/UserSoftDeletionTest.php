<?php

use MDDev\DynamicDashboard\Models\Dashboard;

it('keeps personal dashboards on soft delete and drops them on force delete', function (): void {
    $alice = $this->signIn();

    $personal = Dashboard::create(['name' => 'personal', 'is_personal' => true]);

    $alice->delete();

    expect($alice->trashed())->toBeTrue()
        ->and($personal->fresh())->not->toBeNull();

    $alice->restore();

    expect($personal->fresh()->created_by)->toBe($alice->id);

    $alice->forceDelete();

    expect($personal->fresh())->toBeNull();
});
