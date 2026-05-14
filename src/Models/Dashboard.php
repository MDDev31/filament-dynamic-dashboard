<?php

namespace MDDev\DynamicDashboard\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MDDev\DynamicDashboard\Templates\DashboardTemplate;
use MDDev\DynamicDashboard\Templates\TemplateRegistry;

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
 * @property bool $is_personal
 * @property int|null $created_by
 * @property int $ordering
 * @property array $settings
 * @property array $filters
 * @property array $display_filters
 * @property string|null $template_key
 * @property-read DashboardTemplate $template
 * @property-read int $columns
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
        'is_personal',
        'created_by',
        'ordering',
        'settings',
        'filters',
        'display_filters',
        'template_key',
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
        'is_personal' => 'boolean',
        'created_by' => 'integer',
        'ordering' => 'integer',
        'settings' => 'array',
        'filters' => 'array',
        'display_filters' => 'array',
        'template_key' => 'string',
    ];

    /**
     * Auto-assign ordering on creation so new dashboards appear last.
     * Capture the creator's user id so personal dashboards have an owner.
     * Eagerly migrate orphan widgets when the template changes.
     */
    protected static function booted(): void
    {
        static::creating(function (Dashboard $dashboard): void {
            if ($dashboard->ordering === null) {
                $dashboard->ordering = (static::query()->max('ordering') ?? 0) + 1;
            }

            if ($dashboard->created_by === null) {
                $dashboard->created_by = auth()->id();
            }
        });

        // Flipping a legacy global (created_by=null) to personal would make it
        // invisible to everyone, since `available()` matches personal rows by
        // creator id. Backfill from the acting user so the toggle is safe.
        static::saving(function (Dashboard $dashboard): void {
            if ($dashboard->is_personal && $dashboard->created_by === null) {
                $dashboard->created_by = auth()->id();
            }
        });

        // When a dashboard's template changes, widgets that lived in sections
        // that no longer exist in the new template get moved to the first
        // section of the new template, stacked at the bottom (x=0, y=maxY+1).
        // This keeps the DB in sync with what the user sees — without it the
        // render-time fallback would mask stale section_slug values.
        static::updated(function (Dashboard $dashboard): void {
            if (! $dashboard->wasChanged('template_key')) {
                return;
            }

            $template = $dashboard->template;
            $validSlugs = array_map(fn ($section) => $section->slug, $template->sections);
            $firstSlug = $template->sections[0]->slug;

            $orphan = $dashboard->widgets()
                ->whereNotIn('section_slug', $validSlugs)
                ->get();

            if ($orphan->isEmpty()) {
                return;
            }

            $nextY = ($dashboard->widgets()
                ->where('section_slug', $firstSlug)
                ->max('y') ?? -1) + 1;

            foreach ($orphan as $widget) {
                $widget->update([
                    'section_slug' => $firstSlug,
                    'x' => 0,
                    'y' => $nextY,
                ]);
                $nextY++;
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
        return $this->hasMany(DashboardWidget::class, 'dashboard_id');
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
            return $this->display_filters[$filterName] ?? true;
        }

        return $this->display_filters ?? [];
    }

    /**
     * Resolve the layout template referenced by `template_key`, falling
     * back to the configured default when null or unknown.
     */
    protected function template(): Attribute
    {
        return Attribute::make(
            get: fn (): DashboardTemplate => app(TemplateRegistry::class)->find($this->template_key)
                ?? app(TemplateRegistry::class)->default(),
        );
    }

    /**
     * Number of parent-grid columns. Read-only — value lives in the JSON template.
     * Per-section row height is read from `$this->template->section($slug)->rowHeight`.
     */
    protected function columns(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->template->columns,
        );
    }

    /**
     * Scope to get available dashboards for a given page.
     *
     * Personal dashboards are scoped to their creator: a row with `is_personal=true`
     * only appears when its `created_by` matches the current authenticated user
     * (no admin override — visibility is per-user even for editors).
     */
    #[Scope]
    protected function available(Builder $query, ?string $pageClass = null): void
    {
        $userId = auth()->id();

        $query->where('is_active', true)
            ->where(function (Builder $query) use ($userId): void {
                $query->where('is_personal', false)
                    ->orWhere('created_by', $userId);
            })
            ->orderBy('ordering');

        if ($pageClass) {
            $query->where(function (Builder $query) use ($pageClass): void {
                $query->where('page', $pageClass)
                    ->orWhereNull('page');
            });
        }
    }
}
