<?php

namespace MDDev\DynamicDashboard\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Eloquent model representing a block within a dashboard grid.
 *
 * Blocks can be nested up to 3 levels deep and contain widgets.
 *
 * @property int $id
 * @property int $dashboard_grid_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property int $columns
 * @property bool $display_empty
 * @property int $ordering
 * @property-read int $depth
 */
class DashboardGridBlock extends Model
{

    protected $table = 'dashboard_grid_blocks';

    /**
     * The attributes that are mass assignable
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'dashboard_grid_id',
        'parent_id',
        'name',
        'columns',
        'display_empty',
        'ordering',
    ];

    /**
     * Default attribute values
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'columns' => 6,
    ];

    /**
     * The attributes that should be cast
     *
     * @var array<string, string>
     */
    protected $casts = [
        'dashboard_grid_id' => 'integer',
        'parent_id' => 'integer',
        'columns' => 'integer',
        'ordering' => 'integer',
    ];

    /**
     * Auto-generate slug, assign ordering, and prevent deletion with widgets.
     */
    protected static function booted(): void
    {
        static::creating(function (DashboardGridBlock $block): void {
            // Inherit dashboard_grid_id from parent block if not set (for nested blocks)
            if ($block->dashboard_grid_id === null && $block->parent_id !== null) {
                $parent = static::find($block->parent_id);
                if ($parent) {
                    $block->dashboard_grid_id = $parent->dashboard_grid_id;
                }
            }

            if (empty($block->slug) && $block->dashboard_grid_id !== null) {
                $block->slug = static::generateUniqueSlugForGrid($block->name, $block->dashboard_grid_id);
            }

            if ($block->ordering === null) {
                $block->ordering = (static::query()
                        ->where('dashboard_grid_id', $block->dashboard_grid_id)
                        ->where('parent_id', $block->parent_id)
                        ->max('ordering') ?? -1) + 1;
            }
        });

        static::deleting(function (DashboardGridBlock $block): bool {
            // Prevent deletion if widgets are linked
            if ($block->hasWidgets()) {
                return false;
            }

            return true;
        });
    }

    /**
     * Generate a unique slug within the grid.
     */
    protected static function generateUniqueSlugForGrid(string $name, int $gridId): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (static::query()
            ->where('dashboard_grid_id', $gridId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if any widgets are linked to this block.
     */
    public function hasWidgets(): bool
    {
        return $this->widgets()->exists();
    }

    /**
     * Get widgets assigned to this block.
     *
     * @return HasMany<DashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class, 'dashboard_grid_block_id');
    }

    /**
     * Get the parent grid.
     *
     * @return BelongsTo<DashboardGrid, $this>
     */
    public function grid(): BelongsTo
    {
        return $this->belongsTo(DashboardGrid::class, 'dashboard_grid_id');
    }

    /**
     * Get the parent block if nested.
     *
     * @return BelongsTo<DashboardGridBlock, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DashboardGridBlock::class, 'parent_id');
    }

    /**
     * Get child blocks.
     *
     * @return HasMany<DashboardGridBlock, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DashboardGridBlock::class, 'parent_id')->orderBy('ordering');
    }

    /**
     * Scope to filter blocks by grid.
     */
    #[Scope]
    protected function forGrid(Builder $query, DashboardGrid|int $grid): void
    {
        $gridId = $grid instanceof DashboardGrid ? $grid->id : $grid;
        $query->where('dashboard_grid_id', $gridId);
    }

    /**
     * Scope to get root-level blocks only.
     */
    #[Scope]
    protected function rootLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Get the nesting depth (0 = root, 1 = first child level, 2 = max).
     */
    protected function depth(): Attribute
    {
        return Attribute::make(
            get: function () {
                $depth = 0;
                $block = $this;

                while ($block->parent_id !== null && $depth < 3) {
                    $block->loadMissing('parent');
                    $block = $block->parent;
                    $depth++;
                }

                return $depth;
            }
        );
    }
}
