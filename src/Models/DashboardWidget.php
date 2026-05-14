<?php

namespace MDDev\DynamicDashboard\Models;

use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MDDev\DynamicDashboard\Casts\AsWidgetSettings;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

/**
 * Default Eloquent implementation of a dashboard widget entry.
 *
 * Stores the widget class name, display settings, custom settings, and grid
 * coordinates ({x, y, w, h}) used by GridStack on the dashboard canvas.
 *
 * @property int $id
 * @property int $dashboard_id
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property string $section_slug
 * @property int $x
 * @property int $y
 * @property int $w
 * @property int $h
 * @property bool $is_active
 * @property bool $display_title
 * @property array $settings
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
        'name',
        'description',
        'type',
        'section_slug',
        'x',
        'y',
        'w',
        'h',
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
        'section_slug' => 'main',
        'x' => 0,
        'y' => 0,
        'w' => 4,
        'h' => 1,
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
        'type' => 'string',
        'section_slug' => 'string',
        'x' => 'integer',
        'y' => 'integer',
        'w' => 'integer',
        'h' => 'integer',
        'is_active' => 'boolean',
        'display_title' => 'boolean',
        'settings' => AsWidgetSettings::class,
    ];

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
     * Build a WidgetConfiguration from the stored type and settings.
     *
     * Size is no longer overridden here — it is enforced by GridStack on the
     * canvas using the widget class's static min/max methods.
     * Returns null when the type class is missing or invalid.
     */
    public function getWidget(): ?WidgetConfiguration
    {
        if (! class_exists($this->type) || ! is_subclass_of($this->type, DynamicWidget::class)) {
            return null;
        }

        return $this->type::make([
            'dynamicDashboardWidgetId' => $this->id,
            'dynamicDashboardWidgetTitle' => $this->name,
            ...$this->settings ?? [],
        ]);
    }

    /**
     * Scope to get active widgets for a specific dashboard.
     */
    #[Scope]
    protected function availableFor(Builder $query, Dashboard $dashboard): void
    {
        $query
            ->where('dashboard_id', $dashboard->id)
            ->where('is_active', true)
            ->orderBy('section_slug')
            ->orderBy('y')
            ->orderBy('x');
    }
}
