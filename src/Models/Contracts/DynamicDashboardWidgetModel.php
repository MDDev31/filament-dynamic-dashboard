<?php

namespace MDDev\DynamicDashboard\Models\Contracts;

use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

/**
 * Contract for the DashboardWidget Eloquent model.
 *
 * Implementing classes must define a Laravel local scope `availableFor`.
 *
 * @mixin Model
 *
 * @method static self|null find($id)
 * @method static Builder<self> availableFor(DynamicDashboardModel $dashboard)
 *
 * @see https://laravel.com/docs/12.x/eloquent#local-scopes
 */
interface DynamicDashboardWidgetModel
{
    /**
     * Get the widget primary key.
     */
    public function getId(): int;

    /**
     * Get the widget display name.
     */
    public function getName(): string;

    /**
     * Get the widget Filament class name.
     *
     * @return class-string<DynamicWidget>
     */
    public function getType(): string;

    /**
     * Get the grid column span for this widget.
     */
    public function getColumns(): int;

    /**
     * Get the stored settings array passed to the widget at runtime.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array;

    /**
     * Whether the widget title should be displayed above it.
     */
    public function getDisplayTitle(): bool;

    /**
     * Build a WidgetConfiguration from the stored type and settings.
     */
    public function getWidget(): ?WidgetConfiguration;
}
