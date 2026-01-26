<?php

namespace MDDev\DynamicDashboard\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MDDev\DynamicDashboard\Database\Factories\DashboardFactory;
use MDDev\DynamicDashboard\DynamicDashboardHelper;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardModel;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardWidgetModel;

/**
 * Default Eloquent implementation of a dynamic dashboard.
 *
 * @see DashboardWithRoles for the Spatie-enabled variant
 */
class Dashboard extends Model implements DynamicDashboardModel
{
    use HasFactory;

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
    ];

    protected static function newFactory(): DashboardFactory
    {
        return DashboardFactory::new();
    }

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
     * The explicit 'dashboard_id' foreign key is required because the related model
     * is resolved at runtime via config, so Laravel cannot infer it from the class name.
     *
     * @return HasMany<DynamicDashboardWidgetModel, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DynamicDashboardHelper::WidgetModel(), 'dashboard_id')->orderBy('ordering');
    }

    /** {@inheritDoc} */
    public function getName(): string
    {
        return $this->name;
    }

    /** {@inheritDoc} */
    public function getDisplayFilters(?string $filterName = null): array|bool
    {
        if (isset($filterName)) {
            // always display a filter by default
            return $this->display_filters[$filterName] ?? true;
        }

        return $this->display_filters ?? [];
    }

    /** {@inheritDoc} */
    public function getFilters(): array
    {
        return $this->filters ?? [];
    }

    /** {@inheritDoc} */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** {@inheritDoc} */
    public function getId(): int
    {
        return $this->id;
    }

    /** {@inheritDoc} */
    public function isLocked(): bool
    {
        return $this->is_locked;
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
