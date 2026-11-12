<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use MDDev\DynamicDashboard\Pages\DynamicDashboard;

/**
 * Dashboard page with seven filters. Each test decides which of them are
 * secondary through the static `$secondary` property.
 */
class SecondaryFiltersPage extends DynamicDashboard
{
    protected static ?string $slug = 'secondary-filters';

    /**
     * What `getSecondaryDashboardFilters()` returns. Reset before every test.
     *
     * @var array<int|string, string|array<int, string>>
     */
    public static array $secondary = [];

    /**
     * What `collapseFiltersOnSmallScreens()` returns. Reset before every test.
     */
    public static bool $collapsesOnSmallScreens = true;

    /** @inheritDoc */
    public static function getDashboardFilters(): array
    {
        return [
            TextInput::make('keyword'),
            TextInput::make('period'),
            TextInput::make('brand'),
            TextInput::make('supplier'),
            TextInput::make('prescriber'),
            Select::make('age_bracket')
                ->multiple()
                ->options(['young' => 'Under 30', 'middle' => '30 to 60', 'senior' => 'Over 60']),
            Toggle::make('in_stock'),
        ];
    }

    /** @inheritDoc */
    public static function getSecondaryDashboardFilters(): array
    {
        return static::$secondary;
    }

    /** @inheritDoc */
    public static function collapseFiltersOnSmallScreens(): bool
    {
        return static::$collapsesOnSmallScreens;
    }
}
