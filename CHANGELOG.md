# Changelog

All notable changes to `filament-dynamic-dashboard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
- English and French translations
