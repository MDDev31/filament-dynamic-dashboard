<?php

namespace MDDev\DynamicDashboard\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MDDev\DynamicDashboard\DynamicDashboardHelper;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardModel;

/**
 * Factory for the Dashboard model with states for locked, inactive, and custom settings.
 *
 * @extends Factory<DynamicDashboardModel>
 */
class DashboardFactory extends Factory
{
    public function __construct(mixed ...$arguments)
    {
        parent::__construct(...$arguments);
        $this->model = DynamicDashboardHelper::DashboardModel();
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'ordering' => fake()->numberBetween(0, 100),
            'columns' => 12,
            'is_active' => true,
            'is_locked' => false,
            'settings' => null,
            'filters' => null,
            'display_filters' => null,
        ];
    }

    /**
     * Set the dashboard as locked.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_locked' => true,
        ]);
    }

    /**
     * Set the dashboard as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Set specific ordering.
     */
    public function withOrdering(int $ordering): static
    {
        return $this->state(fn (array $attributes): array => [
            'ordering' => $ordering,
        ]);
    }

    /**
     * Set specific columns.
     */
    public function withColumns(int $columns): static
    {
        return $this->state(fn (array $attributes): array => [
            'columns' => $columns,
        ]);
    }

    /**
     * Set specific settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function withSettings(array $settings): static
    {
        return $this->state(fn (array $attributes): array => [
            'settings' => $settings,
        ]);
    }

    /**
     * Set specific filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function withFilters(array $filters): static
    {
        return $this->state(fn (array $attributes): array => [
            'filters' => $filters,
        ]);
    }

    /**
     * Set specific display filters.
     *
     * @param  array<string, bool>  $displayFilters
     */
    public function withDisplayFilters(array $displayFilters): static
    {
        return $this->state(fn (array $attributes): array => [
            'display_filters' => $displayFilters,
        ]);
    }
}
