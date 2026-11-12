<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MDDev\DynamicDashboard\Tests\Fixtures\TestWidget;

it('ends a fresh install on the v2 schema', function (): void {
    expect(Schema::hasTable('dashboard_grids'))->toBeFalse()
        ->and(Schema::hasTable('dashboard_grid_blocks'))->toBeFalse()
        ->and(Schema::hasColumns('dashboards', ['template_key', 'is_personal', 'created_by']))->toBeTrue()
        ->and(Schema::hasColumn('dashboards', 'dashboard_grid_id'))->toBeFalse()
        ->and(Schema::hasColumns('dashboard_widgets', ['section_slug', 'x', 'y', 'w', 'h']))->toBeTrue()
        ->and(Schema::hasColumn('dashboard_widgets', 'ordering'))->toBeFalse()
        ->and(Schema::hasColumn('dashboard_widgets', 'columns'))->toBeFalse()
        ->and(Schema::hasColumn('dashboard_widgets', 'dashboard_grid_block_id'))->toBeFalse()
        ->and(Schema::hasIndex('dashboard_widgets', 'idx_dashboard_widgets_dashboard_section'))->toBeTrue()
        ->and(Schema::hasIndex('dashboards', 'idx_dashboards_personal_creator'))->toBeTrue()
        ->and(DB::table('dashboards')->value('template_key'))->toBe('flat-12');
});

it('keeps the foreign keys the package relies on', function (): void {
    $foreignKeys = fn (string $table): array => collect(Schema::getForeignKeys($table))
        ->mapWithKeys(fn (array $foreignKey): array => [implode(',', $foreignKey['columns']) => $foreignKey['on_delete']])
        ->all();

    expect($foreignKeys('dashboard_widgets'))->toBe(['dashboard_id' => 'cascade'])
        ->and($foreignKeys('dashboards'))->toBe(['created_by' => 'set null']);
});

it('can run the upgrade migration several times', function (): void {
    $this->runPackageMigration('upgrade_dynamic_dashboard_tables_to_v2');
    $this->runPackageMigration('upgrade_dynamic_dashboard_tables_to_v2');

    expect(Schema::hasColumns('dashboard_widgets', ['section_slug', 'x', 'y', 'w', 'h']))->toBeTrue()
        ->and(Schema::hasIndex('dashboards', 'idx_dashboards_personal_creator'))->toBeTrue();
});

it('upgrades a v1 database and keeps its widgets', function (): void {
    // Rebuild a v1 database: the create migration only, without the personal
    // columns (they did not exist in v1), holding one widget in the old layout.
    Schema::drop('dashboard_widgets');
    Schema::drop('dashboards');
    $this->runPackageMigration('create_dynamic_dashboard_tables');

    Schema::table('dashboards', fn (Blueprint $table) => $table->dropIndex('idx_dashboards_personal_creator'));
    Schema::table('dashboards', fn (Blueprint $table) => $table->dropForeign(['created_by']));
    Schema::table('dashboards', fn (Blueprint $table) => $table->dropColumn(['is_personal', 'created_by']));

    DB::table('dashboard_widgets')->insert([
        'dashboard_id' => DB::table('dashboards')->value('id'),
        'dashboard_grid_block_id' => DB::table('dashboard_grid_blocks')->value('id'),
        'name' => 'Legacy',
        'type' => TestWidget::class,
        'ordering' => 3,
        'columns' => 6,
    ]);

    $this->runPackageMigration('upgrade_dynamic_dashboard_tables_to_v2');

    $widget = DB::table('dashboard_widgets')->first();

    expect($widget->section_slug)->toBe('main')
        ->and([(int) $widget->x, (int) $widget->y, (int) $widget->w, (int) $widget->h])->toBe([0, 3, 6, 1])
        ->and(Schema::hasTable('dashboard_grids'))->toBeFalse()
        ->and(Schema::hasColumn('dashboard_widgets', 'dashboard_grid_block_id'))->toBeFalse()
        ->and(Schema::hasColumns('dashboards', ['template_key', 'is_personal', 'created_by']))->toBeTrue()
        ->and(Schema::hasIndex('dashboards', 'idx_dashboards_personal_creator'))->toBeTrue();
});
