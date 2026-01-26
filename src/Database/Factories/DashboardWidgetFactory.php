<?php

namespace MDDev\DynamicDashboard\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MDDev\DynamicDashboard\DynamicDashboardHelper;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardWidgetModel;

/**
 * Factory for the DashboardWidget model with states for type, settings, and dashboard association.
 *
 * @extends Factory<DynamicDashboardWidgetModel>
 */
class DashboardWidgetFactory extends Factory
{
    public function __construct(mixed ...$arguments)
    {
        parent::__construct(...$arguments);
        $this->model = DynamicDashboardHelper::WidgetModel();
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dashboard_id' => DynamicDashboardHelper::DashboardModel()::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'type' => 'App\\Filament\\Widgets\\RevenueWidget',
            'ordering' => fake()->numberBetween(0, 100),
            'columns' => 3,
            'is_active' => true,
            'display_title' => true,
            'settings' => [],
        ];
    }

    /**
     * Set the widget as inactive.
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
     * Set specific type.
     *
     * @param  class-string  $type
     */
    public function withType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
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
     * Associate with a specific dashboard.
     */
    public function forDashboard(int $dashboardId): static
    {
        return $this->state(fn (array $attributes): array => [
            'dashboard_id' => $dashboardId,
        ]);
    }
}
