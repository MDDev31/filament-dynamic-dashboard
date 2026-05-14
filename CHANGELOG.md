# Changelog

All notable changes to `filament-dynamic-dashboard` will be documented in this file.

## [1.0.2] - 2026-05-14

### Fixed
- Page filters no longer reached widgets after the GridStack rewrite — the page's active filters are now passed into every widget at mount (`pageFilters`)

### Added
- `InteractsWithDashboardFilters` trait — wraps Filament's `InteractsWithPageFilters` and keeps `$this->pageFilters` in sync with live filter-bar changes through the GridStack `wire:ignore` region

## [1.0.1] - 2026-05-14

### Changed
- Template options in the dashboard create/edit form are now sorted alphabetically by name, with the configured default template always shown first

## [1.0.0] - 2026-05-14

A full rewrite of the layout, persistence, and rendering pipeline. Layouts are now JSON files, the canvas runs on GridStack, widget sizes live on the widget class, and the host app no longer needs Tailwind/Vite setup. See the Upgrading from v1.x section of the README before running `php artisan migrate`.

### ⚠ Breaking changes

- DB-backed grids/blocks removed. `dashboard_grids` and `dashboard_grid_blocks` tables are dropped; `dashboards.dashboard_grid_id` is removed; `dashboard_widgets.columns`, `ordering`, `dashboard_grid_block_id` are removed.
- Schema additions: `dashboards.template_key`, `dashboards.is_personal`, `dashboards.created_by`, `dashboard_widgets.section_slug` / `x` / `y` / `w` / `h`. The `upgrade_dynamic_dashboard_tables_to_v2` migration handles the conversion (intentionally not reversible — back up first); the new personal-dashboard columns are folded into the same migration so the upgrade path stays a single step.
- `DynamicWidget` interface gains six static size methods (`getDynamicDashboardDefaultWidth`, `…DefaultHeight`, `…MinWidth`, `…MaxWidth`, `…MinHeight`, `…MaxHeight`). Add `use HasSizeDefaults;` for a one-line fix on existing widgets.
- `DynamicDashboard::getColumns()` and `widgetsGrid()` methods removed — layout now driven by the dashboard's `template_key` and a JSON template.
- Widget form's **Size** (Slider 1–12), **Position**, and **Order** fields removed — width/height come from the widget class, `(x, y, section)` come from GridStack.
- Asset pipeline changed: run `php artisan filament:assets` to publish the GridStack + dashboard bundle. The Tailwind `@source` directive previously required in `theme.css` is no longer needed.
- Config keys `dashboard_columns` and `widget_columns` removed (replaced by `template_paths` and `default_template`).

### Added

- **Drag-and-resize canvas** powered by https://gridstackjs.com/ 12.6.0 (vendored). Drag widgets within a section, across sections, or resize from the bottom-right corner; layouts persist in a single round-trip.
- **JSON layout templates** scanned from `config('filament-dynamic-dashboard.template_paths')` and the package's preset directory. 8 shipped presets — Standard, Split, Trio, Quad, Sidebar, Report, Showcase, KPI — each with an SVG thumbnail next to its JSON.
- `DashboardTemplate` and `DashboardSection` value objects; `TemplateRegistry` service (`all()`, `enabled()`, `find()`, `default()`, `previewSvg()`).
- `HasSizeDefaults` and `HasEmptySettings` traits — drop-in defaults for the contract's size + settings methods.
- Config keys: `template_paths`, `default_template`, `disabled_templates` (hide presets from the manager's selector without breaking existing dashboards).
- `dynamic-dashboard:widget-resized` Livewire event — widget classes can react via `#[On(...)]` to re-render their content after a resize. The package also broadcasts a native `window.resize` event on every GridStack `resizestop`/`dragstop`, so ApexCharts, Chart.js, ECharts, and Plotly auto-fit out of the box.
- Cross-section drag-and-drop: dropping a widget into another section updates its `section_slug` + `(x, y)` atomically.
- Drop-zone visual affordance: peer sections light up while a widget is being dragged, source section highlighted more strongly.
- Eager widget migration when a dashboard's `template_key` changes: widgets in orphan sections move to the new template's first section.
- Section selector in the **Add Widget** modal, shown only when the chosen template has more than one section.
- Live **Locked** toggle: flipping the toggle in the manager updates the canvas in place — slideover stays open, no page reload.
- Page-level `editWidget` and `deleteWidget` Filament actions mounted from Blade via `$wire.mountAction()`.
- **Personal dashboards** — a dashboard can be marked personal so only its creator sees it (in the picker, the manager table, and via direct URL). `canDisplay()` enforces ownership at the top; editors cannot bypass. The creator is captured on `creating` via `auth()->id()` and a `saving` hook backfills legacy globals when they're flipped to personal.
- Picker dropdown groups dashboards into **Global** and **Personal** sections with a visual separator; personal entries carry an `OutlinedUser` icon, the current dashboard still wins the green check.
- Dashboard manager **Name** column shows a small user icon (with tooltip) on personal rows; the `Duplicate` action re-assigns `created_by` to the duplicator via the model's `creating` hook so a copied personal dashboard belongs to the right user.
- Config key `default_personal` (bool, default `false`) — controls whether the **Personal dashboard** toggle is on by default in the create form.

### Changed

- Layout is now driven entirely by the dashboard's `template_key` + a JSON template file.
- Per-widget size declared on the widget class via static methods (or the `HasSizeDefaults` trait), not on the DB row.

### Removed

- `DashboardGrid`, `DashboardGridBlock` Eloquent models.
- Grids tab + grid preview view in the manager slideover.
- `DynamicDashboard::getColumns()`, `widgetsGrid()`, and the seven block/ordering helper methods.
- Vite / Tailwind `@source` setup instructions (no longer needed; assets are served by Filament).

## [0.4.4] - 2026-03-31

### Added
- Laravel 13 compatibility

## [0.4.3] - 2026-03-14

### Fixed
- Prevent unnecessary widget refresh when opening add/edit widget modal or dashboard manager slideover
- Use stable Livewire keys on widget components to avoid remounting on parent re-render
- Replace `uniqid()` key on DashboardManager to prevent component remounting on every render

## [0.4.2] - 2026-02-25

### Fixed
- Add eager loading to prevent `LazyLoadingViolationException` when Laravel strict mode is enabled (`Model::shouldBeStrict()`)

## [0.4.1] - 2026-02-19

### Added
- New translation key `edit` for dashboard editing across all 23 languages

### Changed
- Widget type options in the add/edit form are now sorted alphabetically by label

## [0.4] - 2026-02-17

### Fixed
- Fix migration error issue

### Added
- Turkish language support (`tr`)
- New translation key for dashboard heading modal
- Configurable widget loading indicator via `showWidgetLoader()` on dashboard and optional `showLoader()` per widget
- Widget settings form fields are now grouped under a `settings` state path for cleaner data handling

## [0.3.3] - 2026-02-10

### Fixed
- Temporarily disable dynamic display on widgets position and ordering

## [0.3.2] - 2026-02-03

### Fixed
- Correct a bug to display correctly the ordering widget field on a grid with only one block

## [0.3.1] - 2026-02-03

### Fixed
- Correct a bug for Spatie Permission integration

## [0.3] - 2026-01-30

### Added
- Dashboard can have now a specific grid. 
- New interface to build a grid template
- Add the Livewire 4 compatibility

## [0.2.3] - 2026-01-30

### Added
- Widget access to metadata via `$dynamicDashboardWidgetId` and `$dynamicDashboardWidgetTitle` properties

## [0.2.2] - 2026-01-30

### Added
- `widgetsGrid()` method to customize the dashboard grid layout

## [0.2.1] - 2026-01-29

### Fixed
- Add a unique key for the widget wrapper

## [0.2.0] - 2026-01-29

### Fixed
- Update the widget wrapper to avoid Livewire bad request

### Added
- Placeholder translation to select a widget

## [0.1.1] - 2026-01-28

### Fixed
- Correct a bug when no dashboard exists

## [0.1.0] - 2026-01-27

### Added
- Initial release
- Dynamic dashboard page (`DynamicDashboard`) extending Filament `Page`
- `DynamicWidget` interface for user-configurable widgets
- Dashboard manager slideover (CRUD, reorder, duplicate)
- Per-dashboard filters with session isolation, visibility toggles, and default values
- Custom default filter schema (`getDefaultFilterSchema()`) and resolver (`resolveFilterDefaults()`)
- Widget settings with automatic casting (primitives, BackedEnum, array of enums)
- Locked dashboard mode to prevent widget modifications
- Spatie Permission integration for role-based dashboard visibility
- Customizable models via config (`models.dashboard`, `models.widget`)
- 22 languages translations
