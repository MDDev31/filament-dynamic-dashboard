# Changelog

All notable changes to `filament-dynamic-dashboard` will be documented in this file.

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
