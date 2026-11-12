<?php

namespace MDDev\DynamicDashboard\Models;

use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use MDDev\DynamicDashboard\Casts\AsWidgetSettings;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;
use MDDev\DynamicDashboard\DashboardModelHelper;

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
 *
 * @method static Builder<static> availableFor(Dashboard $dashboard)
 */
class DashboardWidget extends Model
{
    protected $table = 'dashboard_widgets';

    /**
     * The attributes that are mass assignable
     *
     * @var list<string>
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
     *
     * @deprecated The canvas mounts widgets directly from the Blade view and no
     *             longer calls this method. It is kept for backward compatibility
     *             and will be removed in the next major version.
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
     * First free row of a dashboard section: the row just below the lowest
     * edge (`y + h`) of every widget already in it, or 0 when it is empty.
     *
     * Used to stack a widget at the bottom of a section without ever
     * overlapping a widget that is more than one row tall.
     */
    public static function nextFreeRow(int $dashboardId, string $sectionSlug): int
    {
        return (int) static::query()
            ->where('dashboard_id', $dashboardId)
            ->where('section_slug', $sectionSlug)
            ->max(DB::raw('y + h'));
    }

    /**
     * Scope to get active widgets for a specific dashboard.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAvailableFor(Builder $query, Dashboard $dashboard): void
    {
        $query
            ->where('dashboard_id', $dashboard->id)
            ->where('is_active', true)
            ->orderBy('section_slug')
            ->orderBy('y')
            ->orderBy('x');
    }
}
