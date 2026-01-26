# Filament Dynamic Dashboard

User-configurable dashboards for Filament v4+.

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![Filament 4/5](https://img.shields.io/badge/Filament-4%20%7C%205-orange)](https://filamentphp.com/)
[![Laravel 10/11/12](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-red)](https://laravel.com/)
[![License MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE.md)

## Introduction

Filament Dynamic Dashboard lets end-users create, switch, and manage multiple dashboards directly from the Filament UI. Widgets are added, removed, and reordered per dashboard without any code changes. Each dashboard supports its own filters, default values, and per-filter visibility settings. Optional Spatie Permission integration provides role-based dashboard visibility out of the box.

## Requirements

- PHP >= 8.3
- Filament >= 4.0
- Laravel 10, 11, or 12
- (Optional) `spatie/laravel-permission` for role-based visibility

## Installation

Install via Composer:

```bash
composer require mddev31/filament-dynamic-dashboard
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-migrations
php artisan migrate
```

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-config
```

Optionally publish translations:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-translations
```

## Creating a Dashboard Page

Create a Filament page that extends `DynamicDashboard`. All standard Filament `Page` features (navigation icon, slug, group, etc.) remain available.

### Minimal Dashboard

```php
namespace App\Filament\Pages;

use MDDev\DynamicDashboard\Pages\DynamicDashboard;

class Dashboard extends DynamicDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $slug = '/';
}
```

### Overridable Methods

| Method                     | Signature      | Purpose                                                                       |
|----------------------------|----------------|-------------------------------------------------------------------------------|
| `getDashboardFilters()`    | `static array` | Return Filament `Field` components shown in the filter bar                    |
| `getDefaultFilterSchema()` | `static array` | Return custom fields for editing default filter values (keyed by filter name) |
| `resolveFilterDefaults()`  | `static array` | Transform stored defaults into actual filter values at apply time             |
| `getColumns()`             | `int\|array`   | Grid columns for the widget layout (defaults to config)                       |
| `canEdit()`                | `static bool`  | Whether the current user can add/edit/delete widgets and manage dashboards    |
| `canDisplay()`             | `static bool`  | Whether the current user can view a specific dashboard                        |

## Creating a Dynamic Widget

Any Filament Widget can become a dynamic widget by implementing the `DynamicWidget` interface. This requires three static methods:

| Method                    | Return Type             | Purpose                                                                |
|---------------------------|-------------------------|------------------------------------------------------------------------|
| `getWidgetLabel()`        | `string`                | Display name shown in the widget type selector                         |
| `getSettingsFormSchema()` | `array<Component>`      | Filament form components for widget-specific settings                  |
| `getSettingsCasts()`      | `array<string, string>` | Cast definitions for settings values (primitives, BackedEnums, arrays) |

### Simple Widget (no settings)

```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

class SimpleStatsWidget extends StatsOverviewWidget implements DynamicWidget
{
    use InteractsWithPageFilters;

    public static function getWidgetLabel(): string
    {
        return 'Simple Stats';
    }

    public static function getSettingsFormSchema(): array
    {
        return [];
    }

    public static function getSettingsCasts(): array
    {
        return [];
    }

    protected function getStats(): array
    {
        // Access page filters via $this->pageFilters['country'] etc.
        return [/* ... */];
    }
}
```

### Widget with Settings

```php
namespace App\Filament\Widgets;

use App\Enums\ResultTypeEnum;
use App\Enums\GroupingEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

class SalesChartWidget extends ApexChartWidget implements DynamicWidget
{
    use InteractsWithPageFilters;

    public ResultTypeEnum $resultType = ResultTypeEnum::GrossRevenue;
    public GroupingEnum $groupBy = GroupingEnum::Channel;
    public ?int $limit = 5;

    public static function getWidgetLabel(): string
    {
        return 'Sales Chart';
    }

    /**
     * @return array<Component>
     */
    public static function getSettingsFormSchema(): array
    {
        return [
            Select::make('settings.resultType')
                ->label('Result type')
                ->options(ResultTypeEnum::class)
                ->required()
                ->default(ResultTypeEnum::GrossRevenue->value),

            Select::make('settings.groupBy')
                ->label('Group by')
                ->options(GroupingEnum::class)
                ->required()
                ->default(GroupingEnum::Channel->value),

            TextInput::make('settings.limit')
                ->label('Limit')
                ->numeric()
                ->required()
                ->default(5),
        ];
    }

    /**
     * @return array<string, string|array{0: string, 1: class-string}>
     */
    public static function getSettingsCasts(): array
    {
        return [
            'resultType' => ResultTypeEnum::class,      // BackedEnum
            'groupBy'    => GroupingEnum::class,         // BackedEnum
            'limit'      => 'int',                      // Primitive
        ];
    }

    protected function getOptions(): array
    {
        // $this->resultType, $this->groupBy, $this->limit are cast automatically
        // $this->pageFilters contains dashboard filters
        return [/* ... */];
    }
}
```

### Settings Casts

The `getSettingsCasts()` method defines how stored JSON values are hydrated:

| Cast                       | Example                                       | Description                                     |
|----------------------------|-----------------------------------------------|-------------------------------------------------|
| `'int'`, `'integer'`       | `'limit' => 'int'`                            | Cast to integer                                 |
| `'float'`, `'double'`      | `'ratio' => 'float'`                          | Cast to float                                   |
| `'string'`                 | `'label' => 'string'`                         | Cast to string                                  |
| `'bool'`, `'boolean'`      | `'enabled' => 'bool'`                         | Cast to boolean                                 |
| `MyEnum::class`            | `'type' => ResultTypeEnum::class`             | Cast to a `BackedEnum` via `tryFrom()`          |
| `['array', MyEnum::class]` | `'types' => ['array', ResultTypeEnum::class]` | Cast each element of an array to a `BackedEnum` |

### Restricting a Widget to Specific Pages

Implement the optional `availableForDashboard()` method to limit which dashboard pages can use the widget:

```php
public static function availableForDashboard(): array
{
    return [
        \App\Filament\Pages\Dashboard::class,
        // Widget will only appear on these dashboard pages
    ];
}
```

An empty array (or omitting the method entirely) means the widget is available on all dynamic dashboards.

### Widget Visibility

Filament's `canView()` method is respected automatically. If `canView()` returns `false`, the widget is hidden from the type selector and not rendered on the dashboard.

## Managing Filters

### Defining Filters

Override `getDashboardFilters()` to return an array of Filament `Field` components:

```php
public static function getDashboardFilters(): array
{
    return [
        Select::make('country')
            ->label('Country')
            ->options(Country::pluck('name', 'id'))
            ->multiple()
            ->searchable(),

        DatePicker::make('start_date')
            ->label('Start date'),
    ];
}
```

### Per-Dashboard Filter Session

Each dashboard stores its filters independently in the session (keyed by page class and dashboard ID). Switching dashboards restores the last-used filters for that dashboard.

### Per-Dashboard Filter Visibility

Admins can toggle which filters are visible for each dashboard from the **Visible filters** tab in the dashboard manager.

### Per-Dashboard Default Values

Default filter values are stored in the dashboard's `filters` JSON column. They are applied on first visit or when the user clicks the reset button.

### Custom Default Value Fields

Override `getDefaultFilterSchema()` to provide alternative field types for editing defaults. For example, a relative date selector instead of an absolute date picker:

```php
public static function getDefaultFilterSchema(): array
{
    return [
        'period' => Select::make('period')
            ->label('Default period')
            ->options([
                'this_month'   => 'This month',
                'last_month'   => 'Last month',
                'last_7_days'  => 'Last 7 days',
                'last_30_days' => 'Last 30 days',
            ]),
    ];
}
```

Filters not present in this array fall back to their original component from `getDashboardFilters()`.

### Resolving Defaults at Apply Time

Override `resolveFilterDefaults()` to transform stored defaults into actual filter values:

```php
public static function resolveFilterDefaults(array $defaults): array
{
    if (!empty($defaults['period']) && is_string($defaults['period'])) {
        $defaults['period'] = match ($defaults['period']) {
            'this_month'   => now()->startOfMonth()->format('Y-m-d').' - '.now()->format('Y-m-d'),
            'last_30_days' => now()->subDays(29)->format('Y-m-d').' - '.now()->format('Y-m-d'),
            default        => $defaults['period'],
        };
    }

    return $defaults;
}
```

### Accessing Filters in Widgets

Widgets access page filters through Filament's `InteractsWithPageFilters` trait:

```php
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class MyWidget extends StatsOverviewWidget implements DynamicWidget
{
    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        $country = $this->pageFilters['country'] ?? null;
        // ...
    }
}
```

### Resetting Filters

The filter bar includes a reset button. Clicking it calls `resetFilters()`, which re-applies the dashboard's stored defaults (or clears filters if none are configured).

## Dashboard User Interface

### Dashboard Selector

A dropdown button in the page header lets users switch between dashboards. The current dashboard is highlighted with a check icon. An additional **Manage dashboards** entry (visible to editors) opens the management slideover.

### Add Widget

The **Add Widget** button (visible to editors on unlocked dashboards) opens a modal with:
- **Title** -- display name for the widget
- **Widget Type** -- dropdown of all available `DynamicWidget` implementations
- **Size** -- slider from 1 to 12 grid columns (XS to XL)
- **Display title** -- toggle to show/hide the title badge above the widget
- **Widget Settings** -- dynamic form section showing the selected widget's `getSettingsFormSchema()`

### Widget Wrapper

Each widget is wrapped with a hover overlay revealing edit and delete icon buttons. When **Display title** is enabled, a title badge is shown above the widget.

### Manage Dashboards (Slideover)

The slideover contains a reorderable table of all dashboards with:
- **Active** toggle -- enable/disable dashboards (cannot deactivate the last active or the current dashboard)
- **Locked** toggle -- prevent widget modifications on the dashboard
- **Edit** action -- opens a tabbed modal:
  - **General** -- name, rich-text description, roles (if Spatie is enabled)
  - **Widgets** -- reorderable list of widgets
  - **Visible filters** -- toggles per filter field
  - **Default values** -- set default filter values for this dashboard
- **Duplicate** action -- deep-copies the dashboard and all its widgets
- **Delete** action -- removes the dashboard (hidden for the current and last active dashboard)

### Safety Guards

- Cannot deactivate or delete the last remaining active dashboard
- Cannot delete the currently viewed dashboard
- Locked dashboards hide the add/edit/delete widget buttons

## Permissions & Authorization

### canEdit()

Override `canEdit()` to restrict who can manage dashboards and widgets. When `false`, the add widget button, widget edit/delete overlays, and the manage dashboards entry are hidden.

```php
public static function canEdit(): bool
{
    return auth()->user()?->hasRole('admin') ?? false;
}
```

### canDisplay()

Override `canDisplay()` to control per-dashboard visibility. The default logic is:
1. Editors (`canEdit() === true`) always see all dashboards
2. If the dashboard model has Spatie roles, check `user->hasAnyRole(dashboard->roles)`
3. Fall back to the page-level `canAccess()`

```php
public static function canDisplay(DynamicDashboardModel $dashboard): bool
{
    // Custom logic example
    if ($dashboard->getName() === 'Internal') {
        return auth()->user()?->is_staff ?? false;
    }

    return parent::canDisplay($dashboard);
}
```

### Spatie Permission Integration

1. Set `use_spatie_permissions` to `true` in the config (enabled by default)
2. The `DashboardWithRoles` model is automatically swapped in (adds the `HasRoles` trait)
3. A **Roles** multi-select appears in the dashboard manager form
4. `canDisplay()` checks `user->hasAnyRole(dashboard->roles)` when roles are assigned

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-config
```

| Key                      | Type     | Default                              | Description                                                     |
|--------------------------|----------|--------------------------------------|-----------------------------------------------------------------|
| `dashboard_columns`      | `array`  | `['sm' => 3, 'md' => 6, 'lg' => 12]` | Responsive grid breakpoints for the dashboard layout            |
| `widget_columns`         | `int`    | `3`                                  | Default grid column span for new widgets                        |
| `use_spatie_permissions` | `bool`   | `true`                               | Enable Spatie role integration (auto-swaps the Dashboard model) |
| `models.dashboard`       | `string` | `Dashboard::class`                   | Eloquent model implementing `DynamicDashboardModel`             |
| `models.widget`          | `string` | `DashboardWidget::class`             | Eloquent model implementing `DynamicDashboardWidgetModel`       |

Full config file:

```php
return [
    'dashboard_columns' => ['sm' => 3, 'md' => 6, 'lg' => 12],
    'widget_columns' => 3,
    'use_spatie_permissions' => true,
    'models' => [
        'dashboard' => \MDDev\DynamicDashboard\Models\Dashboard::class,
        'widget' => \MDDev\DynamicDashboard\Models\DashboardWidget::class,
    ],
];
```

## Customizing Models

Create a model implementing `DynamicDashboardModel` (or `DynamicDashboardWidgetModel`) and set it in the config:

```php
namespace App\Models;

use MDDev\DynamicDashboard\Models\Dashboard as BaseDashboard;

class CustomDashboard extends BaseDashboard
{
    // Add custom logic, scopes, relationships...
}
```

```php
// config/filament-dynamic-dashboard.php
'models' => [
    'dashboard' => \App\Models\CustomDashboard::class,
],
```

The helper `DynamicDashboardHelper::DashboardModel()` resolves the configured model class at runtime.

## Translations

Supported languages: **English** (`en`), **French** (`fr`).

Publish translations to customize them:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-translations
```

All translation keys are namespaced under `filament-dynamic-dashboard::dashboard.*`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## Credits
Special thanks to :
- All the Filament core Team.
- [filament-apex-charts](https://github.com/leandrocfe/filament-apex-charts) by [Leandro Ferreira](https://github.com/leandrocfe) to give me the idea to build this plugin

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
