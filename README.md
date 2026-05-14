# Filament Dynamic Dashboard

End-user-configurable dashboards for Filament v4/5 — drag, resize, and move widgets across named sections, with layouts defined as plain JSON files.

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![Filament 4/5](https://img.shields.io/badge/Filament-4%20%7C%205-orange)](https://filamentphp.com/)
[![Laravel 10/11/12](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-red)](https://laravel.com/)
[![License MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE.md)

## Introduction

Filament Dynamic Dashboard turns Filament's static widget grid into a **fluid, end-user-configurable** dashboard system. Drag widgets anywhere on the canvas, resize them from any corner, move them between named sections, and the layout persists in a single round-trip. 

Widgets carry size constraints declared on the class itself, so a chart can lock its height while still letting users resize width; container resizes propagate to chart libraries automatically. 

Per-dashboard filters, default filter values, per-filter visibility, and optional Spatie role-based access all work out of the box.

Built for Filament v4+, Laravel 10+, and powered by [GridStack.js](https://gridstackjs.com/) under the hood.

**Key capabilities**

- Drag-and-drop widget moves within a section and across sections.
- Corner-handle resize on both axes, constrained by static methods on the widget class.
- JSON layout templates with named sections, per-section column count, row span, and row height.
- 8 shipped layout presets — Standard, Split, Trio, Quad, Sidebar, Report, Showcase, KPI. Add your own as JSON files.
- Multiple dashboards per page, each with its own filter state, default values, and visibility toggles.
- Widget settings stored as JSON, hydrated as typed properties (primitives, BackedEnums, arrays of enums).
- Lock a dashboard to make it read-only.
- Personal dashboards — mark a dashboard as personal so only its creator sees it; globals stay shared as before. Picker groups them with a visual separator and a user icon.
- Optional Spatie Permission integration for role-based dashboard visibility.

## Requirements

- PHP >= 8.3
- Filament >= 4.1.10 (Filament 5 supported)
- Laravel 10/11/12/13 
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

Publish the JavaScript and CSS (GridStack + this package's own bundle):

```bash
php artisan filament:assets
```

Filament's panel layout injects them automatically — no `<link>` or `<script>` tags to add yourself.

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-config
```

Optionally publish translations:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-translations
```

## Upgrading from version under 1.x

From v1.x replaces the database-backed grids/blocks with JSON layout templates and adds drag-and-resize via GridStack. Follow these steps in order:

1. **Pull the new migration stub.**

   ```bash
   php artisan vendor:publish --tag=filament-dynamic-dashboard-migrations
   ```

   Your existing `create_dynamic_dashboard_tables` migration is left untouched. A new `upgrade_dynamic_dashboard_tables_to_v2` migration is added next to it.

2. **Back up your database.** The upgrade is intentionally **not reversible** — the `down()` step throws on purpose. Take a snapshot before continuing.

3. **Run the migration.**

   ```bash
   php artisan migrate
   ```

   The upgrade migration:

   - Adds `template_key` on `dashboards` and `section_slug`, `x`, `y`, `w`, `h` on `dashboard_widgets`.
   - Copies existing widget data: every widget gets `section_slug = 'main'`, `w = old columns`, `y = old ordering`, `h = 1`, `x = 0`. GridStack compacts the layout on first render.
   - Every dashboard gets `template_key = 'flat-12'` (the default single-section layout). Pick a different one in the manager afterwards if you want.
   - Drops the obsolete `dashboard_grid_id` foreign key on `dashboards`, the `columns`, `ordering`, `dashboard_grid_block_id` columns on `dashboard_widgets`, and the `dashboard_grids` and `dashboard_grid_blocks` tables.
   - Adds `dashboards.is_personal` (boolean, default `false`) and `dashboards.created_by` (nullable foreign key to your users table, resolved from `config('auth.providers.users.model')`). Existing rows stay global (`is_personal = false`, `created_by = null`) until you flip them in the manager. See [Personal dashboards](#personal-dashboards).

4. **Publish the new assets.**

   ```bash
   php artisan filament:assets
   ```

5. **Update your widget classes.** v2 adds 6 static size methods to the `DynamicWidget` contract (`getDynamicDashboardDefaultWidth`, …`Min/MaxHeight`). The fastest fix is to add `use HasSizeDefaults;` to every widget class — sensible defaults are provided and you only override the axes you constrain. See [Helper traits](#helper-traits).

6. **Clear caches.**

   ```bash
   php artisan view:clear
   php artisan cache:clear
   ```

What survives the upgrade: dashboard names, descriptions, page assignments, filters, default filter values, widget names, types, settings, display_title, active/locked flags. What's intentionally lost: nested block hierarchies, per-instance widget sizes (now declared on the class), per-widget ordering selectors (drag instead).

## Quick Start

A minimal dashboard page and a minimal widget:

```php
namespace App\Filament\Pages;

use MDDev\DynamicDashboard\Pages\DynamicDashboard;

class Dashboard extends DynamicDashboard
{
    //
}
```

```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use MDDev\DynamicDashboard\Concerns\HasEmptySettings;
use MDDev\DynamicDashboard\Concerns\HasSizeDefaults;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

class SimpleStatsWidget extends StatsOverviewWidget implements DynamicWidget
{
    use InteractsWithPageFilters;
    use HasEmptySettings;
    use HasSizeDefaults;

    public static function getWidgetLabel(): string
    {
        return 'Simple Stats';
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Users', 1234),
            Stat::make('Sessions', 5678),
        ];
    }
}
```

Register the widget on your Filament panel as you would any other widget, visit the dashboard page, click **Widget**, pick *Simple Stats*, and drag it around. Done.

## Creating a Dashboard Page

Extend `DynamicDashboard`. All standard Filament `Page` features (navigation icon, slug, group, etc.) remain available. Layout (parent column count, sections) is driven by the dashboard's `template_key` — see [Layout Templates](#layout-templates-json).

### Overridable methods

| Method                     | Signature      | Purpose                                                                       |
|----------------------------|----------------|-------------------------------------------------------------------------------|
| `getDashboardFilters()`    | `static array` | Return Filament `Field` components shown in the filter bar.                   |
| `getDefaultFilterSchema()` | `static array` | Return custom fields for editing default filter values (keyed by filter name). |
| `resolveFilterDefaults()`  | `static array` | Transform stored defaults into actual filter values at apply time.            |
| `canEdit()`                | `static bool`  | Whether the current user can add/edit/delete widgets and manage dashboards.   |
| `canDisplay()`             | `static bool`  | Whether the current user can view a given dashboard.                          |
| `showWidgetLoader()`       | `static bool`  | Whether widgets show a loading overlay during their own Livewire commits.     |

## Creating a Dynamic Widget

Any Filament widget can become a dynamic widget by implementing the `DynamicWidget` interface. The contract has three identity methods and six size methods:

| Method                                            | Returns                 | Purpose                                                                |
|---------------------------------------------------|-------------------------|------------------------------------------------------------------------|
| `getWidgetLabel()`                                | `string`                | Display name shown in the widget type selector.                        |
| `getSettingsFormSchema()`                         | `array<Component>`      | Filament form components for widget-specific settings.                 |
| `getSettingsCasts()`                              | `array<string, string>` | Cast definitions for settings values (primitives, BackedEnums, arrays). |
| `getDynamicDashboardDefaultWidth()`               | `int`                   | Width (columns) of new instances of this widget.                       |
| `getDynamicDashboardDefaultHeight()`              | `int`                   | Height (rows) of new instances of this widget.                         |
| `getDynamicDashboardMinWidth()` / `…MaxWidth()`   | `int`                   | Width resize range. Set both equal to lock width.                      |
| `getDynamicDashboardMinHeight()` / `…MaxHeight()` | `int`                   | Height resize range. Set both equal to lock height.                    |

> **Why the prefix?** Filament chart widgets define an instance method `getMaxHeight(): ?string`. PHP forbids a child class from overriding an inherited instance method with a `static` one of the same name, so the `getDynamicDashboard…` prefix avoids the collision.

### Helper traits

| Trait                                              | Provides                                                                                              |
|----------------------------------------------------|--------------------------------------------------------------------------------------------------------|
| `MDDev\DynamicDashboard\Concerns\HasSizeDefaults`  | Sensible defaults for all six size methods (default 4×1, min 1×1, max 12×12). Override only what you constrain. |
| `MDDev\DynamicDashboard\Concerns\HasEmptySettings` | Empty `getSettingsFormSchema()` and `getSettingsCasts()` for widgets without configurable settings.   |

### Simple widget (no settings, default size)

```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use MDDev\DynamicDashboard\Concerns\HasEmptySettings;
use MDDev\DynamicDashboard\Concerns\HasSizeDefaults;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

class SimpleStatsWidget extends StatsOverviewWidget implements DynamicWidget
{
    use InteractsWithPageFilters;
    use HasEmptySettings;
    use HasSizeDefaults;

    public static function getWidgetLabel(): string
    {
        return 'Simple Stats';
    }

    protected function getStats(): array
    {
        // Access page filters via $this->pageFilters['country'] etc.
        return [/* ... */];
    }
}
```

### Custom size constraints

Override only the axes you want to constrain — the trait keeps the rest as defaults:

```php
class StatsBoardWidget extends StatsOverviewWidget implements DynamicWidget
{
    use HasEmptySettings;
    use HasSizeDefaults;

    public static function getDynamicDashboardDefaultWidth(): int  { return 6; }
    public static function getDynamicDashboardMinWidth(): int      { return 4; }   // never narrower than 4
    public static function getDynamicDashboardMinHeight(): int     { return 1; }
    public static function getDynamicDashboardMaxHeight(): int     { return 1; }   // height locked at 1 row
}
```

### Widget with settings

```php
namespace App\Filament\Widgets;

use App\Enums\ResultTypeEnum;
use App\Enums\GroupingEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use MDDev\DynamicDashboard\Concerns\HasSizeDefaults;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

class SalesChartWidget extends ApexChartWidget implements DynamicWidget
{
    use InteractsWithPageFilters;
    use HasSizeDefaults;

    public ResultTypeEnum $resultType = ResultTypeEnum::GrossRevenue;
    public GroupingEnum $groupBy = GroupingEnum::Channel;
    public ?int $limit = 5;

    public static function getWidgetLabel(): string
    {
        return 'Sales Chart';
    }

    /** @return array<Component> */
    public static function getSettingsFormSchema(): array
    {
        return [
            Select::make('resultType')
                ->label('Result type')
                ->options(ResultTypeEnum::class)
                ->required()
                ->default(ResultTypeEnum::GrossRevenue->value),

            Select::make('groupBy')
                ->label('Group by')
                ->options(GroupingEnum::class)
                ->required()
                ->default(GroupingEnum::Channel->value),

            TextInput::make('limit')
                ->label('Limit')
                ->numeric()
                ->required()
                ->default(5),
        ];
    }

    /** @return array<string, string|array{0: string, 1: class-string}> */
    public static function getSettingsCasts(): array
    {
        return [
            'resultType' => ResultTypeEnum::class,
            'groupBy'    => GroupingEnum::class,
            'limit'      => 'int',
        ];
    }

    protected function getOptions(): array
    {
        // $this->resultType, $this->groupBy, $this->limit are already cast.
        // $this->pageFilters contains the dashboard's filter values.
        return [/* ... */];
    }
}
```

### How settings work

Three pieces are linked by a shared key name:

| Piece                                                   | Role                                                             |
|---------------------------------------------------------|------------------------------------------------------------------|
| `public ResultTypeEnum $resultType`                     | Livewire property that receives the value at render time.        |
| `Select::make('resultType')` in `getSettingsFormSchema()` | Form field the admin fills in (stored as JSON in the DB).        |
| `'resultType' => ResultTypeEnum::class` in `getSettingsCasts()` | Type-cast rule applied when reading the JSON back.        |

**The key name must be identical across all three.** The form field name becomes the JSON key, which is then cast and injected into the matching public property.

Hydration flow:

```
Admin saves form
  → settings stored as JSON  {"resultType": "gross_revenue", "limit": 5}
  → on render, AsWidgetSettings cast applies getSettingsCasts()
  → cast values spread into Widget::make(['resultType' => ResultTypeEnum::GrossRevenue, 'limit' => 5, …])
  → Livewire hydrates public properties $this->resultType, $this->limit
```

Give every public property a default value — if a setting hasn't been saved yet, that default is used.

### Settings casts

| Cast                       | Example                                       | Description                                |
|----------------------------|-----------------------------------------------|--------------------------------------------|
| `'int'`, `'integer'`       | `'limit' => 'int'`                            | Cast to integer.                           |
| `'float'`, `'double'`      | `'ratio' => 'float'`                          | Cast to float.                             |
| `'string'`                 | `'label' => 'string'`                         | Cast to string.                            |
| `'bool'`, `'boolean'`      | `'enabled' => 'bool'`                         | Cast to boolean.                           |
| `MyEnum::class`            | `'type' => ResultTypeEnum::class`             | Cast to a `BackedEnum` via `tryFrom()`.    |
| `['array', MyEnum::class]` | `'types' => ['array', ResultTypeEnum::class]` | Cast each element of an array to an enum.  |

### Restricting a widget to specific pages

Implement the optional `availableForDashboard()` method on your widget:

```php
public static function availableForDashboard(): array
{
    return [
        \App\Filament\Pages\Dashboard::class,
        // listed dashboard pages only
    ];
}
```

An empty array (or omitting the method entirely) makes the widget available on every dynamic dashboard.

### Widget visibility

Filament's `canView()` is respected automatically. If it returns `false`, the widget is hidden from the type selector and not rendered.

### Widget metadata

Each widget can declare two optional public properties to receive its own id and title from the dashboard:

```php
class MyWidget extends StatsOverviewWidget implements DynamicWidget
{
    public int $dynamicDashboardWidgetId;
    public string $dynamicDashboardWidgetTitle;

    protected function getStats(): array
    {
        // use $this->dynamicDashboardWidgetId / Title
        return [/* ... */];
    }
}
```

Declare only the ones you need.

### Loading indicator

Every widget shows a loading overlay while its own Livewire component is committing. Toggle globally with `showWidgetLoader()` on the dashboard page, or per widget with an optional static `showLoader()` method:

```php
class HeavyChartWidget extends ApexChartWidget implements DynamicWidget
{
    public static function showLoader(): ?bool
    {
        return false;
    }
}
```

Resolution order: per-widget `showLoader()` if it returns a non-null boolean; otherwise the dashboard's `showWidgetLoader()` (default `true`).

### Resize-aware widgets

When a user drags a widget's resize handle, GridStack updates the container size — but chart libraries render their canvas at mount-time dimensions and don't know the box has changed. On every `resizestop` and `dragstop`, this package broadcasts two signals:

1. A native `window.resize` event — ApexCharts, Chart.js, ECharts, Plotly all listen for this and re-fit themselves automatically.
2. A Livewire event `dynamic-dashboard:widget-resized` with the resized widget's id — for widgets that need explicit control.

For the auto-resize to actually use the new size, the widget's own chart options must use **responsive sizing**:

**ApexCharts** — set `chart.height: '100%'` in `getOptions()`:

```php
protected function getOptions(): array
{
    return [
        'chart' => [
            'type' => 'bar',
            'height' => '100%',
        ],
        // ...
    ];
}
```

**Chart.js** (Filament native `ChartWidget`) — disable aspect-ratio locking:

```php
protected function getOptions(): ?array
{
    return [
        'responsive' => true,
        'maintainAspectRatio' => false,
        // ...
    ];
}
```

**Custom rendering / lazy loads** — listen to the Livewire event:

```php
use Livewire\Attributes\On;

class MyWidget extends Widget implements DynamicWidget
{
    use HasSizeDefaults;

    public int $dynamicDashboardWidgetId;

    #[On('dynamic-dashboard:widget-resized')]
    public function onResized(int $id): void
    {
        if ($id !== $this->dynamicDashboardWidgetId) {
            return;
        }

        // Re-render, reload data, dispatch a custom JS event to a canvas, etc.
    }
}
```

## Layout Templates (JSON)

Templates are plain JSON files on disk. Each declares a parent column count and an ordered list of **sections** — named zones that host widgets. The package ships 10 presets and a dashboard references one via its `template_key`.

### Template file format

A template is a JSON file in any directory listed by `config('filament-dynamic-dashboard.template_paths')`. The package's own preset directory is always loaded first; later paths override earlier ones by `key`, so app-level templates can replace shipped ones.

Single-section ("flat") template — one big canvas, no visible section header:

```json
{
  "key": "flat-12",
  "name": "filament-dynamic-dashboard::templates.flat_12.name",
  "description": "filament-dynamic-dashboard::templates.flat_12.description",
  "columns": 12,
  "sections": [
    {
      "slug": "main",
      "name": null,
      "columns": 12,
      "row_height": 80
    }
  ]
}
```

Multi-section template — each section has its own column count, GridStack row height, and optional visible header. `row_span` lets a section occupy several parent rows so asymmetric layouts fall out of CSS Grid auto-flow:

```json
{
  "key": "2-left-1-right",
  "name": "filament-dynamic-dashboard::templates.two_left_one_right.name",
  "description": "filament-dynamic-dashboard::templates.two_left_one_right.description",
  "columns": 12,
  "sections": [
    { "slug": "top-left",    "name": "filament-dynamic-dashboard::templates.two_left_one_right.top_left",    "columns": 6, "row_span": 1, "row_height": 80 },
    { "slug": "right",       "name": "filament-dynamic-dashboard::templates.two_left_one_right.right",       "columns": 6, "row_span": 2, "row_height": 80 },
    { "slug": "bottom-left", "name": "filament-dynamic-dashboard::templates.two_left_one_right.bottom_left", "columns": 6, "row_span": 1, "row_height": 80 }
  ]
}
```

### Template fields

| Field         | Type           | Notes                                                                                  |
|---------------|----------------|----------------------------------------------------------------------------------------|
| `key`         | string         | Unique identifier. Stored on the dashboard as `template_key`.                          |
| `name`        | string         | Translation key used by `__()` for the template's display name.                        |
| `description` | string         | Translation key used by `__()` (helper text in the template select).                   |
| `columns`     | int (1–24)     | Parent CSS Grid column count.                                                          |
| `sections[]`  | array          | 1 or more sections; rendered in source order via CSS Grid auto-flow.                   |

### Section fields

| Field        | Type                | Notes                                                                                  |
|--------------|---------------------|----------------------------------------------------------------------------------------|
| `slug`       | string              | Unique inside the template. Stored on widgets as `section_slug`.                       |
| `name`       | string or `null`    | Translation key for the visible header. `null` ⇒ no header.                            |
| `columns`    | int (1–parent)      | Both the section's column-span in the parent grid AND its inner GridStack columns.     |
| `row_span`   | int (≥1, default 1) | Number of parent rows the section spans. Use for asymmetric layouts.                   |
| `row_height` | int (20–500)        | Inner GridStack `cellHeight` in pixels.                                                |

### Translation keys

`name` and `description` on the template and `name` on each section are **Laravel translation keys**, not display strings. Lookup happens at render time via `__()`, so locale switches work without reloading templates. Missing keys fall back to the raw key string — useful as a "you forgot to translate this" signal.

Shipped translations live at `resources/lang/{locale}/templates.php`:

```php
return [
    'flat_12' => [
        'name'        => 'Standard 12-column',
        'description' => '12 columns, 80px row. Sensible default for most dashboards.',
    ],
    'two_left_one_right' => [
        'name'        => 'Two-left + tall right',
        'description' => 'Two stacked sections on the left and one full-height section on the right.',
        'top_left'    => 'Top left',
        'right'       => 'Right (tall)',
        'bottom_left' => 'Bottom left',
    ],
    // …
];
```

For your own templates, drop the JSON in your configured path and ship the matching translation strings in your app's `lang/*/<file>.php`.

### Shipped presets

| Key                    | Display name | Sections                                                                  |
|------------------------|--------------|---------------------------------------------------------------------------|
| `flat-12`              | Standard     | 1 — `main` (12c)                                                          |
| `2-columns`            | Split        | `left` (6) + `right` (6)                                                  |
| `3-columns`            | Trio         | `left` (4) + `middle` (4) + `right` (4)                                   |
| `4-cells-2-rows`       | Quad         | `top-left` (6) + `top-right` (6) + `bottom-left` (6) + `bottom-right` (6) |
| `sidebar-main`         | Sidebar      | `sidebar` (4) + `main` (8)                                                |
| `header-2cols-footer`  | Report       | `header` (12) + `left` (6) + `right` (6) + `footer` (12)                  |
| `2-left-1-right`       | Showcase     | `top-left` (6) + `right` (6, `row_span` 2) + `bottom-left` (6)            |
| `kpi-strip-chart`      | KPI          | `kpi` (12) + `chart` (12)                                                 |

Each preset also ships an SVG thumbnail alongside its JSON (same filename, `.svg` extension) — useful if you want to render a visual preview in your own UI via `app(TemplateRegistry::class)->previewSvg($key)`.

### Custom paths and disabling templates

Add your own template directories via `template_paths`:

```php
// config/filament-dynamic-dashboard.php
return [
    'template_paths' => [
        resource_path('dashboard-templates'),
        base_path('custom/layouts'),
    ],
    // ...
];
```

To hide some shipped templates from the manager's selector — without breaking dashboards that already reference them — use `disabled_templates`:

```php
'disabled_templates' => [
    'flat-24-dense',
    'kpi-strip-chart',
],
```

This is UI-only: `TemplateRegistry::find()` and `default()` still resolve disabled keys for already-existing dashboards.

### Fallback behavior

- `template_key = null` or unknown ⇒ the model resolves `config('filament-dynamic-dashboard.default_template')` (default `'flat-12'`); if even that's missing, a hardcoded 12-column / 80-px single-section template is used so the page always renders.
- A widget whose `section_slug` doesn't exist in the current template is rendered in the **first section** — never lost.
- When a dashboard's `template_key` changes, widgets in now-removed sections are **eagerly migrated** to the new template's first section (stacked at the bottom). The DB is kept in sync.

## Drag, Resize, and Cross-Section Moves

The dashboard renders as a CSS Grid of sections; each section is its own GridStack instance and all are linked, so users can drag widgets within a section AND between sections. Resize handles live at the bottom-right corner of each widget.

| Interaction              | What persists                                            |
|--------------------------|-----------------------------------------------------------|
| Drag within section      | `x`, `y` on the widget.                                   |
| Drag to another section  | `section_slug`, plus new `x`, `y` at the drop target.     |
| Resize from the corner   | `w` and/or `h`, clamped by the widget class's min/max.    |
| Page refresh             | GridStack reads `gs-x/y/w/h` attributes back from HTML.   |

During a drag, every peer section's grid is highlighted with a soft blue background and dashed outline; the section that owns the dragged widget is highlighted more strongly. After the drop (or a cancel), highlights disappear immediately.

### Locked dashboards and read-only users

When a dashboard's `is_locked` toggle is on, or the current user's `canEdit()` returns `false`:

- Every section's GridStack initialises with `staticGrid: true` — no drag, no resize, no drop targets.
- The widget hover cluster (drag handle, edit, delete) is hidden.
- The **Add Widget** button and **Manage dashboards** entry disappear from the page header.

The widgets still render normally, so non-editors see a clean read-only view.

## Managing Filters

### Defining filters

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

### Per-dashboard filter session

Each dashboard stores its filters independently in the session (keyed by page class + dashboard id). Switching dashboards restores the last-used filters for that dashboard.

### Per-dashboard filter visibility

Admins toggle which filters are visible for each dashboard from the **Visible filters** tab in the dashboard manager.

### Per-dashboard default values

Default filter values are stored in the dashboard's `filters` JSON column. They're applied on first visit and when the user clicks the reset button.

### Custom default-value fields

Override `getDefaultFilterSchema()` to provide alternative field types for editing defaults — for example a relative period selector instead of a date picker:

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

### Resolving defaults at apply time

Override `resolveFilterDefaults()` to transform stored defaults into actual filter values:

```php
public static function resolveFilterDefaults(array $defaults): array
{
    if (! empty($defaults['period']) && is_string($defaults['period'])) {
        $defaults['period'] = match ($defaults['period']) {
            'this_month'   => now()->startOfMonth()->format('Y-m-d') . ' - ' . now()->format('Y-m-d'),
            'last_30_days' => now()->subDays(29)->format('Y-m-d') . ' - ' . now()->format('Y-m-d'),
            default        => $defaults['period'],
        };
    }

    return $defaults;
}
```

### Accessing filters in widgets

Use Filament's `InteractsWithPageFilters` trait:

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

### Resetting filters

The filter bar includes a reset button. Clicking it calls `resetFilters()` on the page, which re-applies the dashboard's stored defaults (or clears filters if none are configured).

## Dashboard User Interface

### Dashboard selector

A dropdown button in the page header lets users switch between dashboards. The current dashboard is highlighted with a check icon. A **Manage dashboards** entry — visible to editors — opens the management slideover.

When the user has both global and personal dashboards visible, the dropdown groups them: globals first, then a visual separator, then personal entries — each prefixed with a user icon. The current dashboard always wins the check icon, even when it's personal.

### Add Widget

The **Add Widget** button (visible to editors on unlocked dashboards) opens a modal with:

- **Title** — display name for the widget.
- **Display title** — toggle to show or hide the floating title badge above the widget.
- **Widget Type** — dropdown of every available `DynamicWidget` implementation.
- **Section** — which template section the widget lands in. Only shown when the template has more than one section; defaults to the first section.
- **Widget Settings** — dynamic form section showing the selected widget's `getSettingsFormSchema()`.

Size and position are deliberately absent — width and height come from the widget class's static methods, and `(x, y)` are managed visually by GridStack on the canvas. New widgets land at the bottom of the chosen section at the widget class's default size.

### Widget wrapper

Each widget is wrapped with chrome that adds:

- A floating title badge above the widget (when **Display title** is on).
- A top-right hover cluster with three icons: **drag handle** (the move target — GridStack picks up the drag here), **edit**, **delete**.
- A bottom-right GridStack resize handle.
- A loading overlay while the inner Livewire widget is committing (toggleable, see [Loading indicator](#loading-indicator)).

### Dashboard Manager slideover

A reorderable table of all dashboards. Personal dashboards display a small user icon in the **Name** column; other users' personal dashboards are not listed at all — the manager only shows globals plus the viewer's own personals.

- **Active** toggle — enable or disable a dashboard. The last active dashboard and the currently viewed dashboard cannot be deactivated.
- **Locked** toggle — flip a dashboard to read-only for everyone (no drag, resize, or widget add/edit/delete).
- **Edit** action opens a tabbed modal:
  - **General** — name, description, **Template** selector (populated from every JSON template the registry discovered, minus any disabled in config), **Personal dashboard** toggle (default from `default_personal` config), Spatie roles when enabled.
  - **Visible filters** — toggles per filter field.
  - **Default values** — set default filter values per filter.
- **Duplicate** action deep-copies the dashboard with every widget, preserving each widget's `section_slug` and `(x, y, w, h)`.
- **Delete** action — protected against deleting the last active dashboard or the one currently viewed.

There is no template-management tab — templates are JSON files, edited on disk (or just shipped as presets).

### Safety guards

- Cannot deactivate or delete the last remaining active dashboard.
- Cannot delete the currently viewed dashboard.
- Locked dashboards disable drag/resize and hide the add/edit/delete widget buttons.

## Permissions & Authorization

### canEdit()

Override `canEdit()` to restrict who can manage dashboards and widgets. When it returns `false`, the **Add Widget** button, the widget edit/delete icons, and the **Manage dashboards** entry are all hidden.

```php
public static function canEdit(): bool
{
    return auth()->user()?->hasRole('admin') ?? false;
}
```

### canDisplay()

Override `canDisplay()` to control per-dashboard visibility. The default logic is:

1. Editors (`canEdit() === true`) always see every dashboard.
2. If the dashboard model has Spatie roles, check `$user->hasAnyRole($dashboard->roles)`.
3. Fall back to the page-level `canAccess()`.

```php
public static function canDisplay(Dashboard $dashboard): bool
{
    if ($dashboard->name === 'Internal') {
        return auth()->user()?->is_staff ?? false;
    }

    return parent::canDisplay($dashboard);
}
```

### Spatie Permission integration

1. Set `use_spatie_permissions` to `true` in the config.
2. The `DashboardWithRoles` model is automatically swapped in (it extends `Dashboard` and adds the `HasRoles` trait).
3. A **Roles** multi-select appears in the dashboard manager form.
4. `canDisplay()` checks `$user->hasAnyRole($dashboard->roles)` whenever roles are assigned.

### Personal dashboards

Toggle **Personal dashboard** in the dashboard edit form to mark a dashboard as personal. Personal dashboards are scoped to their creator — even users for whom `canEdit()` returns `true` cannot see another user's personal dashboard in the picker, the manager table, or via a direct URL (`canDisplay()` short-circuits to `false` for non-owners). Globals continue to follow the existing `canDisplay()` / Spatie role logic.

Each dashboard also persists a `created_by` column (nullable foreign key to your users table, resolved from `config('auth.providers.users.model')`). The creator is captured automatically on `creating`; the **Duplicate** action re-assigns the new copy to the user who duplicated it.

When a user is deleted, the package's `User::deleting` hook deletes that user's personal dashboards. Global dashboards they created keep their row and have `created_by` set to null (audit residue rather than data loss).

Set the default for new dashboards with:

```php
// config/filament-dynamic-dashboard.php
'default_personal' => false, // true to make new dashboards personal by default
```

## Configuration

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-config
```

| Key                      | Type     | Default                                       | Description                                                                              |
|--------------------------|----------|-----------------------------------------------|------------------------------------------------------------------------------------------|
| `template_paths`         | `array`  | `[resource_path('dashboard-templates')]`      | Extra directories scanned for layout JSON templates. The package's preset dir is always loaded first; user paths override on matching `key`. |
| `default_template`       | `string` | `'flat-12'`                                   | Template `key` used when a dashboard has none set, and as fallback when a referenced key is missing. |
| `disabled_templates`     | `array`  | `[]`                                          | Template keys to hide from the manager's selector. Dashboards already pointing at a disabled template keep rendering — UI-only filter. |
| `use_spatie_permissions` | `bool`   | `false`                                       | Enable Spatie role integration.                                                          |
| `default_personal`       | `bool`   | `false`                                       | When `true`, the **Personal dashboard** toggle in the create form defaults to on. Existing dashboards are unaffected. |

## Translations

Supported languages: **English** (`en`), **French** (`fr`), **Spanish** (`es`), **Portuguese** (`pt`), **German** (`de`), **Russian** (`ru`), **Chinese** (`zh`), **Bulgarian** (`bg`), **Croatian** (`hr`), **Danish** (`da`), **Estonian** (`et`), **Finnish** (`fi`), **Greek** (`el`), **Hungarian** (`hu`), **Italian** (`it`), **Dutch** (`nl`), **Polish** (`pl`), **Romanian** (`ro`), **Swedish** (`sv`), **Czech** (`cs`), **Japanese** (`ja`), **Arabic** (`ar`), **Turkish** (`tr`).

Publish translations to customize them:

```bash
php artisan vendor:publish --tag=filament-dynamic-dashboard-translations
```

All UI translation keys are namespaced under `filament-dynamic-dashboard::dashboard.*`; template name/description keys are under `filament-dynamic-dashboard::templates.*`.

## Changelog

See [CHANGELOG.md](https://github.com/MDDev31/filament-dynamic-dashboard/blob/master/CHANGELOG.md) for release notes.

## Credits

- The [Filament](https://filamentphp.com/) core team for the framework.
- [GridStack.js](https://gridstackjs.com/) — the drag-and-drop / resize engine that makes the canvas tick.
- [filament-apex-charts](https://github.com/leandrocfe/filament-apex-charts) by [Leandro Ferreira](https://github.com/leandrocfe) — the spark behind this plugin.

## License

The MIT License (MIT). See [LICENSE.md](https://github.com/MDDev31/filament-dynamic-dashboard/blob/master/LICENSE.md) for details.
