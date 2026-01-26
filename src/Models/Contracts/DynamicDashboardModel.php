<?php

namespace MDDev\DynamicDashboard\Models\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for the Dashboard Eloquent model.
 *
 * Implementing classes must define a Laravel local scope `available`.
 *
 * @mixin Model
 *
 * @method static self|null find($id)
 * @method static Builder<self> available(?string $pageClass = null)
 *
 * @see https://laravel.com/docs/12.x/eloquent#local-scopes
 */
interface DynamicDashboardModel
{
    /**
     * Get the dashboard primary key.
     */
    public function getId(): int;

    /**
     * Get the dashboard display name.
     */
    public function getName(): string;

    /**
     * Get the dashboard description (HTML allowed).
     */
    public function getDescription(): ?string;

    /**
     * Get filter visibility settings.
     *
     * When $filterName is provided, returns whether that specific filter should be displayed.
     * When omitted, returns the full visibility map.
     *
     * @return bool|array<string>
     */
    public function getDisplayFilters(?string $filterName = null): array|bool;

    /**
     * Get the default filter values for this dashboard.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array;

    /**
     * Whether the dashboard is locked (widgets cannot be added/removed/reordered).
     */
    public function isLocked(): bool;
}
