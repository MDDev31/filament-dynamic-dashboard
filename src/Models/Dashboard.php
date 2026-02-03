<?php

namespace MDDev\DynamicDashboard\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Default Eloquent implementation of a dynamic dashboard.
 *
 * @see DashboardWithRoles for the Spatie-enabled variant
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $page
 * @property bool $is_active
 * @property bool $is_locked
 * @property int $ordering
 * @property int $columns
 * @property array $settings
 * @property array $filters
 * @property array $display_filters
 * @property int|null $dashboard_grid_id
 * @property-read DashboardGrid|null $effective_grid
 */
class Dashboard extends Model
{

    protected $table = 'dashboards';

    /**
     * The attributes that are mass assignable
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'page',
        'is_active',
        'is_locked',
        'ordering',
        'columns',
        'settings',
        'filters',
        'display_filters',
        'dashboard_grid_id',
    ];

    /**
     * Default attribute values
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'filters' => '[]',
        'display_filters' => '[]',
    ];

    /**
     * The attributes that should be cast
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'ordering' => 'integer',
        'columns' => 'integer',
        'settings' => 'array',
        'filters' => 'array',
        'display_filters' => 'array',
        'dashboard_grid_id' => 'integer',
    ];

    /**
     * Auto-assign ordering on creation so new dashboards appear last.
     */
    protected static function booted(): void
    {
        static::creating(function (Dashboard $dashboard): void {
            if ($dashboard->ordering === null) {
                $dashboard->ordering = (static::query()->max('ordering') ?? 0) + 1;
            }
        });
    }

    /**
     * Get the widgets for this dashboard.
     *
     * @return HasMany<DashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class, 'dashboard_id')->orderBy('ordering');
    }

    /**
     * Get the grid assigned to this dashboard.
     *
     * @return BelongsTo<DashboardGrid, $this>
     */
    public function grid(): BelongsTo
    {
        return $this->belongsTo(DashboardGrid::class, 'dashboard_grid_id');
    }

    /**
     * Get filter visibility settings.
     *
     * When $filterName is provided, returns whether that specific filter should be displayed.
     * When omitted, returns the full visibility map.
     *
     * @return bool|array<string>
     */
    public function getDisplayFilters(?string $filterName = null): array|bool
    {
        if (isset($filterName)) {
            // always display a filter by default
            return $this->display_filters[$filterName] ?? true;
        }

        return $this->display_filters ?? [];
    }

    /**
     * Get the effective grid for this dashboard.
     * Returns the assigned grid or the default grid if none assigned.
     */
    protected function effectiveGrid(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->dashboard_grid_id
                ? $this->grid
                : DashboardGrid::default()->first()
        );
    }

    /**
     * Scope to get available dashboards for a given page
     */
    #[Scope]
    protected function available(Builder $query, ?string $pageClass = null): void
    {
        $query->where('is_active', true)
            ->orderBy('ordering');

        if ($pageClass) {
            $query->where(function (Builder $query) use ($pageClass): void {
                $query->where('page', $pageClass)
                    ->orWhereNull('page');
            });
        }
    }
}
