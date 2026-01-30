<?php

namespace MDDev\DynamicDashboard\Models;

use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MDDev\DynamicDashboard\Casts\AsWidgetSettings;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;
use MDDev\DynamicDashboard\Database\Factories\DashboardWidgetFactory;
use MDDev\DynamicDashboard\DynamicDashboardHelper;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardModel;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardWidgetModel;

/**
 * Default Eloquent implementation of a dashboard widget entry.
 *
 * Stores the widget class name, display settings, and custom settings
 * that are passed to the Filament widget at render time.
 */
class DashboardWidget extends Model implements DynamicDashboardWidgetModel
{
    use HasFactory;

    protected $table = 'dashboard_widgets';

    /**
     * The attributes that are mass assignable
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'dashboard_id',
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
     * The attributes that should be cast
     *
     * @var array<string, string>
     */
    protected $casts = [
        'dashboard_id' => 'integer',
        'type' => 'string',
        'ordering' => 'integer',
        'columns' => 'integer',
        'is_active' => 'boolean',
        'display_title' => 'boolean',
        'settings' => AsWidgetSettings::class,
    ];

    protected static function newFactory(): DashboardWidgetFactory
    {
        return DashboardWidgetFactory::new();
    }

    /**
     * Auto-assign ordering within the parent dashboard so new widgets appear last.
     */
    protected static function booted(): void
    {
        static::creating(function (DashboardWidget $widget): void {
            if ($widget->ordering === null) {
                $widget->ordering = (static::query()
                    ->where('dashboard_id', $widget->dashboard_id)
                    ->max('ordering') ?? 0) + 1;
            }
        });
    }

    /**
     * Get the dashboard that owns this widget
     *
     * @return BelongsTo<DynamicDashboardModel, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(DynamicDashboardHelper::DashboardModel());
    }

    /**
     * {@inheritDoc}
     *
     * Builds a WidgetConfiguration by merging columnSpan, heading, and stored settings
     * into the widget class. Returns null when the type class is missing or invalid.
     */
    public function getWidget(): ?WidgetConfiguration
    {
        $widget = null;
        if (class_exists($this->type) && is_subclass_of($this->type, DynamicWidget::class)) {
            $widget = $this->type::make([
                'dynamicDashboardWidgetId'=>$this->getId(),
                'dynamicDashboardWidgetTitle'=>$this->getName(),
                'columnSpan' => $this->columns,
                ...$this->settings ?? []
            ]);
        }

        return $widget;
    }

    /** {@inheritDoc} */
    public function getId(): int
    {
        return $this->id;
    }

    /** {@inheritDoc} */
    public function getName(): string
    {
        return $this->name;
    }

    /** {@inheritDoc} */
    public function getType(): string
    {
        return $this->type;
    }

    /** {@inheritDoc} */
    public function getColumns(): int
    {
        return $this->columns ?? 3;
    }

    /** {@inheritDoc} */
    public function getSettings(): array
    {
        return $this->settings ?? [];
    }

    /** {@inheritDoc} */
    public function getDisplayTitle(): bool
    {
        return $this->display_title ?? true;
    }

    /**
     * Scope to get active widgets for a specific dashboard
     */
    #[Scope]
    protected function availableFor(Builder $query, DynamicDashboardModel $dashboard): void
    {
        $query
            ->where('dashboard_id', $dashboard->getId())
            ->where('is_active', true)
            ->orderBy('ordering');
    }
}
