<?php

namespace MDDev\DynamicDashboard\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Eloquent model representing a dashboard grid layout.
 *
 * Grids define the overall column structure and contain blocks where widgets are placed.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $columns
 * @property bool $is_default
 */
class DashboardGrid extends Model
{

    protected $table = 'dashboard_grids';

    /**
     * The attributes that are mass assignable
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'columns',
        'is_default',
    ];

    /**
     * Default attribute values
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'columns' => 12,
    ];

    /**
     * The attributes that should be cast
     *
     * @var array<string, string>
     */
    protected $casts = [
        'columns' => 'integer',
        'is_default' => 'boolean',
    ];

    /**
     * Auto-generate slug and ensure single default on save.
     */
    protected static function booted(): void
    {
        static::creating(function (DashboardGrid $grid): void {
            if (empty($grid->slug)) {
                $grid->slug = static::generateUniqueSlug($grid->name);
            }
        });

        static::saving(function (DashboardGrid $grid): void {
            // Ensure only one default grid
            if ($grid->is_default && $grid->isDirty('is_default')) {
                static::query()
                    ->where('id', '!=', $grid->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Generate a unique slug from the name.
     */
    protected static function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get all blocks for this grid.
     *
     * @return HasMany<DashboardGridBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(DashboardGridBlock::class, 'dashboard_grid_id')->orderBy('ordering');
    }

    /**
     * Get root-level blocks for this grid.
     *
     * @return HasMany<DashboardGridBlock, $this>
     */
    public function rootBlocks(): HasMany
    {
        return $this->hasMany(DashboardGridBlock::class, 'dashboard_grid_id')
            ->whereNull('parent_id')
            ->orderBy('ordering');
    }

    /**
     * Get dashboards using this grid.
     *
     * @return HasMany<Dashboard, $this>
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class, 'dashboard_grid_id');
    }

    /**
     * Scope to get the default grid.
     */
    #[Scope]
    protected function default(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * Check if the grid is used by any dashboards.
     */
    public function isInUse(): bool
    {
        return $this->dashboards()->exists();
    }
}
