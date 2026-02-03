<?php

namespace MDDev\DynamicDashboard\Models;

use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MDDev\DynamicDashboard\Casts\AsWidgetSettings;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

/**
 * Default Eloquent implementation of a dashboard widget entry.
 *
 * Stores the widget class name, display settings, and custom settings
 * that are passed to the Filament widget at render time.
 *
 * @property int $id
 * @property int $dashboard_id
 * @property int|null $dashboard_grid_block_id
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property int $ordering
 * @property int $columns
 * @property bool $is_active
 * @property bool $display_title
 * @property array $settings
 * @property-read DashboardGridBlock|null $effective_grid_block
 */
class DashboardWidget extends Model
{
    protected $table = 'dashboard_widgets';

    /**
     * The attributes that are mass assignable
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'dashboard_id',
        'dashboard_grid_block_id',
        'name',
        'description',
        'type',
        'ordering',
        'columns',
        'is_active',
        'display_title',
        'settings',
    ];

    /**
     * Default attribute values
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'columns' => 3,
        'display_title' => true,
        'settings' => '[]',
    ];

    /**
     * The attributes that should be cast
     *
     * @var array<string, string>
     */
    protected $casts = [
        'dashboard_id' => 'integer',
        'dashboard_grid_block_id' => 'integer',
        'type' => 'string',
        'ordering' => 'integer',
        'columns' => 'integer',
        'is_active' => 'boolean',
        'display_title' => 'boolean',
        'settings' => AsWidgetSettings::class,
    ];

    /**
     * Auto-assign ordering within the parent dashboard so new widgets appear last.
     * Handle reordering when widget position changes.
     */
    protected static function booted(): void
    {
        static::creating(function (DashboardWidget $widget): void {
            if ($widget->ordering === null) {
                $widget->ordering = (static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->where('dashboard_grid_block_id', $widget->dashboard_grid_block_id)
                    ->max('ordering') ?? 0) + 1;
            } else {
                // If ordering is specified, shift existing widgets to make room
                static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->where('dashboard_grid_block_id', $widget->dashboard_grid_block_id)
                    ->where('ordering', '>=', $widget->ordering)
                    ->increment('ordering');
            }
        });

        static::saving(function (DashboardWidget $widget): void {
            // Skip if new record (handled by creating) or ordering not changed
            if (!$widget->exists || !$widget->isDirty('ordering')) {
                return;
            }

            $oldOrdering = $widget->getOriginal('ordering');
            $newOrdering = $widget->ordering;
            $oldBlockId = $widget->getOriginal('dashboard_grid_block_id');
            $newBlockId = $widget->dashboard_grid_block_id;

            // If block changed, handle differently
            if ($oldBlockId !== $newBlockId) {
                // Close gap in old block
                static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->where('dashboard_grid_block_id', $oldBlockId)
                    ->where('ordering', '>', $oldOrdering)
                    ->decrement('ordering');

                // Make room in new block
                static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->where('dashboard_grid_block_id', $newBlockId)
                    ->where('ordering', '>=', $newOrdering)
                    ->increment('ordering');

                return;
            }

            // Same block, just reordering
            if ($newOrdering < $oldOrdering) {
                // Moving up: increment all widgets between new and old position
                static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->where('dashboard_grid_block_id', $widget->dashboard_grid_block_id)
                    ->where('id', '!=', $widget->id)
                    ->whereBetween('ordering', [$newOrdering, $oldOrdering - 1])
                    ->increment('ordering');
            } elseif ($newOrdering > $oldOrdering) {
                // Moving down: decrement all widgets between old and new position
                static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->where('dashboard_grid_block_id', $widget->dashboard_grid_block_id)
                    ->where('id', '!=', $widget->id)
                    ->whereBetween('ordering', [$oldOrdering + 1, $newOrdering])
                    ->decrement('ordering');
            }
        });
    }

    /**
     * Get the dashboard that owns this widget
     *
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(DashboardModelHelper::model(), 'dashboard_id');
    }

    /**
     * Get the grid block this widget is assigned to
     *
     * @return BelongsTo<DashboardGridBlock, $this>
     */
    public function gridBlock(): BelongsTo
    {
        return $this->belongsTo(DashboardGridBlock::class, 'dashboard_grid_block_id');
    }

    /**
     * Build a WidgetConfiguration from the stored type and settings.
     *
     * Merges columnSpan, heading, and stored settings
     * into the widget class. Returns null when the type class is missing or invalid.
     */
    public function getWidget(): ?WidgetConfiguration
    {
        $widget = null;
        if (class_exists($this->type) && is_subclass_of($this->type, DynamicWidget::class)) {
            $widget = $this->type::make([
                'dynamicDashboardWidgetId' => $this->id,
                'dynamicDashboardWidgetTitle' => $this->name,
                'columnSpan' => $this->columns,
                ...$this->settings ?? [],
            ]);
        }

        return $widget;
    }

    /**
     * Get the effective block for this widget.
     * Returns the assigned block or the grid's first root block if none assigned.
     */
    protected function effectiveGridBlock(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->dashboard_grid_block_id
                ? $this->gridBlock
                : $this->dashboard?->effective_grid?->rootBlocks()->orderBy('ordering')->first()
        );
    }

    /**
     * Scope to get active widgets for a specific dashboard
     */
    #[Scope]
    protected function availableFor(Builder $query, Dashboard $dashboard): void
    {
        $query
            ->where('dashboard_id', $dashboard->id)
            ->where('is_active', true)
            ->orderBy('ordering');
    }

    /**
     * Scope to get widgets for a specific dashboard and block
     */
    #[Scope]
    protected function forDashboardAndBlock(
        Builder $query,
        int $dashboardId,
        ?int $blockId,
        ?int $excludeId = null
    ): void {
        $query->where('dashboard_id', $dashboardId)
            ->where('dashboard_grid_block_id', $blockId)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('ordering');
    }

    /**
     * Get the maximum ordering value for widgets in a specific block
     */
    public static function maxOrderingInBlock(int $dashboardId, ?int $blockId): int
    {
        return static::query()
            ->where('dashboard_id', $dashboardId)
            ->where('dashboard_grid_block_id', $blockId)
            ->max('ordering') ?? 0;
    }

    /**
     * Get the widget that comes before the given ordering in the same block
     */
    public static function previousInBlock(
        int $dashboardId,
        ?int $blockId,
        int $ordering
    ): ?self {
        return static::query()
            ->where('dashboard_id', $dashboardId)
            ->where('dashboard_grid_block_id', $blockId)
            ->where('ordering', '<', $ordering)
            ->orderByDesc('ordering')
            ->first();
    }
}
