# Changelog

All notable changes to `filament-dynamic-dashboard` will be documented in this file.

## [1.2] - 2026-11-12

Secondary filters, a single-column layout for small screens, Laravel 11 support, a round of access-control and stability fixes, and a test suite. No database change.

### Upgrade notes

- Run `php artisan filament:assets` and `php artisan view:clear` after updating.
- Laravel 11.28 is now the minimum version.
- In a panel without SPA mode, the page now does a regular page load after a widget is added, edited or deleted or the dashboard is switched, and the canvas assets are only loaded on dashboard pages. See **Changed**.
- If you published the package's views, update your copies of `livewire/dashboard-grid.blade.php` and `schemas/widget-wrapper.blade.php` from the package. Without the new `@assets` block of the first one, the canvas scripts are no longer loaded outside SPA mode.
- `currentDashboardId` can no longer be set from the browser. Custom JavaScript that wrote it through `$wire` has to trigger the dashboard selector instead.
- The `available()` / `availableFor()` model scopes are now the public methods `scopeAvailable()` / `scopeAvailableFor()`. Calls are unchanged, but a subclass that overrode the former protected methods must rename its override.

### Added

- **Secondary filters.** Override the new `DynamicDashboard::getSecondaryDashboardFilters()` to keep the main filters on the bar and put the others behind a **More filters** toggle, as a flat list of filter names or grouped under headings. The toggle shows how many secondary filters have a value, the area opens instantly (no request), keeps its state across re-renders, and stays open while one of its filters has a value. Secondary filters remain ordinary filters for default values, per-dashboard visibility, the session, reset and `pageFilters`. A page that does not override the method renders exactly as before. New translation keys `more_filters` and `more_filters_count` in every locale.
- **Small-screen layout.** Below 768px, whatever the template, sections stack and every widget takes one full-width row, in reading order. Each widget is at least as tall as it was designed and grows with its content. Widgets cannot be dragged or resized in that layout and nothing is saved, so the grid layout is never altered from a phone; widening the window brings the grid back without a reload.
- Locale aliases `pt_BR` and `zh_CN`, which reuse the `pt` and `zh` translations. Applications set to those locales previously fell back to English.
- `Dashboard::visibleToCurrentUser()` query scope (global dashboards plus the current user's personal ones).
- `DashboardWidget::nextFreeRow()` — first free row of a dashboard section.
- `DynamicDashboard::redirectToDashboard()` — the reload performed after a change the canvas cannot apply in place; override it to choose between a Livewire navigation and a regular redirect.
- `CanvasJs` / `CanvasCss` asset classes, which decide per panel whether the canvas assets are loaded on request.
- GridStack's licence files next to the vendored bundle in `resources/dist`.
- Test suite (Pest), PHPStan/Larastan (level 5), a Pint configuration, a GitHub Actions workflow and `.gitattributes`.

### Changed

- Minimum Laravel version is now 11.28 (the lowest version Filament 4 supports); `illuminate/contracts` constraint updated accordingly.
- After a widget is added, edited or deleted, or the dashboard is switched, the page reloads the way the panel is configured, like Filament's own pages: a Livewire navigation in SPA mode, a regular page load otherwise (it was always a Livewire navigation).
- In a panel without SPA mode, GridStack and the canvas CSS/JS are only added to dashboard pages instead of every panel page. Nothing changes in SPA mode.
- The page reads the current dashboard through `getCurrentDashboard()` everywhere, instead of relying on it having been loaded earlier in the request.
- The `TemplateRegistry` singleton is registered in `packageRegistered()`.
- `spatie/laravel-permission` is listed under `suggest` in `composer.json`.
- On devices without hover (touch screens) the widget icons (drag, edit, delete) are always visible instead of appearing on hover.
- The page subheading falls back to the page's own subheading when the dashboard has no description, instead of rendering an empty element.
- README: upgrade notes for 1.2, the ApexCharts sizing advice (`chart.height: 'auto'`), and corrections where it no longer matched the code (number of shipped presets, default widget size, what a section's `name` is used for, what stays visible on a locked dashboard).

### Deprecated

- `DashboardWidget::getWidget()` — no longer used by the package; it will be removed in the next major version.

### Removed

- The unused `templateColumns` option of the `dashboardGrid` Alpine component.

### Fixed

- Laravel 11 compatibility: the `available()` / `availableFor()` scopes used the `#[Scope]` attribute, which only exists from Laravel 12. They are now classic `scopeAvailable()` / `scopeAvailableFor()` methods — call sites are unchanged.
- After a dashboard was unlocked live from the manager, drags were not saved until the page was reloaded. On a dashboard loaded unlocked, the dragged widget never finished its move: the canvas kept its GridStack instances in Alpine's reactive data, which hands them back wrapped in proxies; they are now always read from the elements GridStack attaches them to. On a dashboard loaded locked, the handlers that save a move were never attached; they are now attached whatever the state at load.
- A stored widget type naming a class that is not a dynamic widget any more no longer breaks the page; the widget is skipped, like one whose class is gone.
- Widgets are keyed by their id (`key('widget-'.$id)`); the key was passed in a position the `@livewire` directive ignores. Livewire derived a key from the widget's position instead, so after a widget was dragged to another section the next page re-render mounted it a second time on the server.
- The canvas component was initialised twice (`x-init="init()"` on top of Alpine's own `init()` call), registering every listener twice.
- The `dynamic-dashboard:editable-changed` listener, the per-widget Livewire `commit` hook and the GridStack instances are now released when the canvas leaves the page. They used to pile up on the window with every `wire:navigate` visit.
- New widgets, and widgets moved by a template change, are placed on the first row below the lowest edge (`y + h`) of the widgets already in the section. They were placed at `max(y) + 1`, which overlapped any widget taller than one row.
- A dashboard's `template` attribute was cached on the model instance, so a second `template_key` change on the same instance still resolved the previous template and skipped the move of orphan widgets.
- `persistLayout()` skips items with non-numeric coordinates, a negative position or an empty size, and stores a section slug unknown to the current template as the template's first section (the fallback already applied at render time) instead of saving the stale slug.
- `AsWidgetSettings` no longer passes `null` to `json_decode()` when a widget's `settings` column is `NULL` (PHP deprecation), always returns an array, no longer instantiates the widget class to read its casts, and no longer fails when the model was loaded without its `type` column.
- Soft-deleting a user no longer hard-deletes their personal dashboards; they are removed when the user is force-deleted. The listener is registered on the event dispatcher, so the user model is no longer booted on every request.
- `upgrade_dynamic_dashboard_tables_to_v2` migration: foreign keys and indexes are now checked with `Schema::getForeignKeys()` / `Schema::hasIndex()` instead of `try`/`catch` blocks that either never caught anything (inside Blueprint closures) or left a failed statement behind (which aborts the migration transaction on PostgreSQL). Only affects installs that have not published the migration yet.
- The dashboard manager counted the available dashboards up to four times per table row; the count is now computed once per request.
- Translations: the 21 locales other than `en` / `fr` were missing `template`, `section`, `drag`, `personal` and `personal_help`, and still carried the removed v1 grid/block keys. All 23 locales now share the same keys.

### Security

- Widget **edit** and **delete** actions now resolve the widget inside the current dashboard only. A forged widget ID could previously reach any dashboard, including another user's personal one.
- Adding, editing and deleting a widget are refused on the server while the dashboard is locked, not only hidden in the page. Saving the layout (`persistLayout()`) now follows the same rule as those actions and is also refused once the dashboard is no longer available to the user (deactivated, or turned into someone else's personal dashboard).
- Without an authenticated user, personal dashboards are never visible. A personal dashboard whose creator no longer exists (`created_by` null) used to match "no user".
- The dashboard manager table no longer lists other users' personal dashboards, so they can't be edited, toggled, reordered or deleted from it (this is the behaviour the README already described).
- `currentDashboardId` on the page, and `pageClass` / `currentDashboardId` on the manager, are now `#[Locked]`. A client-side update could previously switch to a dashboard without going through `canDisplay()`.
- The dashboard description is sanitized before being rendered as the page subheading.

## [1.0.2] - 2026-05-14

### Fixed
- Page filters were not reaching widgets after the GridStack rewrite. Widgets using Filament's `InteractsWithPageFilters` trait now receive the active filters at mount and on every live filter-bar change (edit or reset).

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
