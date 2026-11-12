<?php

namespace MDDev\DynamicDashboard\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Session;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;
use MDDev\DynamicDashboard\Templates\DashboardSection;
use MDDev\DynamicDashboard\Templates\DashboardTemplate;
use MDDev\DynamicDashboard\Templates\TemplateRegistry;
use Throwable;

/**
 * Abstract Filament page that renders a user-configurable dashboard.
 *
 * Extend this class and register it as a Filament page to get a dashboard
 * with switchable layouts, per-dashboard session filters, widget CRUD,
 * and optional Spatie role-based visibility.
 *
 * Key behaviours:
 * - Each dashboard keeps its own filter session (keyed by page + dashboard ID).
 * - `currentDashboard` is only a per-request cache: always read it through
 *   `getCurrentDashboard()`, which loads it on demand. It is null on dehydrate
 *   to avoid serialisation of a
 *   potentially deleted model; the ID is kept via Livewire #[Session].
 * - Available widgets are discovered from the current Filament panel.
 * - `canDisplay()` checks Spatie roles when the model supports them.
 */
abstract class DynamicDashboard extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    /**
     * Current dashboard ID, persisted in session via Livewire.
     *
     * Locked: it is only ever changed server-side (mount and the dashboard
     * selector), after `canDisplay()` has been checked. A client-side update
     * would otherwise bypass that check.
     */
    #[Locked]
    #[Session]
    public ?int $currentDashboardId = null;

    /**
     * Cached instance of the current dashboard (not persisted by Livewire).
     */
    public ?Dashboard $currentDashboard = null;

    /**
     * Whether the secondary filters area ("More filters") is open.
     *
     * It is ordinary Livewire state, so it travels with every request and a
     * re-render cannot lose it. The toggle flips it in the browser without a
     * request and the area's visibility is bound to it. The server forces it
     * to true while a secondary filter has a value, see
     * `openSecondaryFiltersWhenActive()`.
     */
    public bool $secondaryFiltersOpen = false;

    /**
     * Names of the secondary filters displayed on the current dashboard,
     * resolved once per request (null until then).
     *
     * @var list<string>|null
     */
    protected ?array $visibleSecondaryFilterNames = null;

    /**
     * Whether the filter bar is unfolded on a small screen.
     *
     * Below 768px the bar is reduced to one row (see
     * `collapseFiltersOnSmallScreens()`) and this flag reveals the filters.
     * Like `secondaryFiltersOpen`, it is flipped in the browser without a
     * request and survives re-renders. It starts closed on every page load
     * and has no effect on wider screens.
     */
    public bool $smallScreenFiltersOpen = false;

    /**
     * Names of all the filters displayed on the current dashboard, resolved
     * once per request (null until then).
     *
     * @var list<string>|null
     */
    protected ?array $visibleFilterNames = null;

    /**
     * Define custom form components for editing default filter values.
     * Return a keyed array ['filterName' => Component].
     * Filters not in this array will use the original component from getDashboardFilters().
     *
     * @return array<string, Field>
     */
    public static function getDefaultFilterSchema(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->initializeCurrentDashboard();

        // After initializing, check if this specific dashboard has session filters
        // getFiltersSessionKey() now includes dashboard ID, so each dashboard has its own session
        $sessionKey = $this->getFiltersSessionKey();

        if (! session()->has($sessionKey)) {
            // First time viewing this dashboard (or after switch) - apply defaults
            $this->applyDefaultFilters();
        }
        // Otherwise, Filament's HasFilters trait (mountHasFilters) will load from session
    }

    /**
     * Initialize the current dashboard on mount.
     */
    protected function initializeCurrentDashboard(): void
    {
        $displayable = $this->getDisplayableDashboards();

        // Check if the stored dashboard is still displayable
        if ($this->currentDashboardId && ! $displayable->firstWhere('id', $this->currentDashboardId)) {
            $this->currentDashboardId = null;
        }

        // If no dashboard selected, select the first displayable
        if (! $this->currentDashboardId) {
            $this->currentDashboardId = $displayable->first()?->id;
        }

        // If dashboards exist but none are displayable, deny access
        if (! $this->currentDashboardId && $this->getAvailableDashboards()->exists()) {
            abort(403);
        }

        $this->loadCurrentDashboard();
    }

    /**
     * Get available dashboards filtered by canDisplay authorization.
     *
     * @return \Illuminate\Support\Collection<int, Dashboard>
     */
    protected function getDisplayableDashboards(): \Illuminate\Support\Collection
    {
        $query = $this->getAvailableDashboards();

        if (config('filament-dynamic-dashboard.use_spatie_permissions')) {
            // The relation lives on DashboardWithRoles, the model in use when this option is on.
            // @phpstan-ignore larastan.relationExistence
            $query->with('roles');
        }

        return $query->get()->filter(
            fn (Dashboard $dashboard) => static::canDisplay($dashboard)
        );
    }

    /**
     * Get all available dashboards for selection.
     *
     * @return Builder<Dashboard>
     */
    public function getAvailableDashboards(): Builder
    {
        return DashboardModelHelper::model()::query()->available(static::class);
    }

    /**
     * Determine if the current user can view this dashboard.
     *
     * A personal dashboard is visible only to its creator — even editors
     * cannot bypass this. For global dashboards, editors always have access
     * and Spatie roles are checked when the model supports them, falling
     * back to the page-level `canAccess()`.
     */
    public static function canDisplay(Dashboard $dashboard): bool
    {
        $userId = auth()->id();

        // Without a user there is no creator to match: a personal dashboard
        // whose creator is gone (`created_by` null) must not match "nobody".
        if ($dashboard->is_personal && ($userId === null || $dashboard->created_by !== $userId)) {
            return false;
        }

        if (static::canEdit()) {
            return true;
        }

        // `roles` only exists on DashboardWithRoles (spatie/laravel-permission,
        // an optional dependency), hence the method_exists() guard.
        // @phpstan-ignore property.notFound
        if (method_exists($dashboard, 'roles') && $dashboard->roles->isNotEmpty()) {
            $user = auth()->user();

            // @phpstan-ignore property.notFound
            if (! $user || ! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole($dashboard->roles)) {
                return false;
            }
        }

        return static::canAccess();
    }

    /**
     * Determine if the current user can edit dashboards and widgets.
     * Override in subclasses to restrict editing.
     */
    public static function canEdit(): bool
    {
        return true;
    }

    /**
     * Determine if the widgets of the current dashboard can be modified:
     * the user can edit, a dashboard is selected and it is not locked.
     */
    protected function canEditCurrentDashboardWidgets(): bool
    {
        if (! static::canEdit()) {
            return false;
        }

        $dashboard = $this->getCurrentDashboard();

        return $dashboard !== null && ! $dashboard->is_locked;
    }

    /**
     * Find a widget by ID within the current dashboard.
     *
     * Returns null when the ID is missing or belongs to another dashboard, so
     * a forged widget ID can never reach a dashboard the user is not viewing.
     */
    protected function findCurrentDashboardWidget(mixed $widgetId): ?DashboardWidget
    {
        if (! $this->currentDashboardId || ! is_numeric($widgetId)) {
            return null;
        }

        return DashboardWidget::query()
            ->where('dashboard_id', $this->currentDashboardId)
            ->find((int) $widgetId);
    }

    /**
     * Whether to show loading indicators on widgets.
     * Override in subclasses to disable globally.
     */
    public static function showWidgetLoader(): bool
    {
        return true;
    }

    /**
     * Whether the filter bar folds into a single row on small screens.
     *
     * Below 768px the bar then only shows a "Filters" button, with the number
     * of filters that have a value, next to the reset button; the button
     * reveals the filters. Wider screens are not affected.
     * Override in subclasses and return false to always show the filters.
     */
    public static function collapseFiltersOnSmallScreens(): bool
    {
        return true;
    }

    /**
     * Load the current dashboard instance from the database.
     */
    protected function loadCurrentDashboard(): void
    {
        $this->currentDashboard = $this->currentDashboardId
            ? $this->getAvailableDashboards()->find($this->currentDashboardId)
            : null;
    }

    /**
     * Get dashboard-specific session key for filters.
     * This ensures each dashboard has its own filter session.
     */
    public function getFiltersSessionKey(): string
    {
        $pageKey = md5(static::class);
        $dashboardId = $this->currentDashboardId ?? 'default';

        return $pageKey.'_dashboard_'.$dashboardId.'_filters';
    }

    /**
     * Apply default filters (used by reset and dashboard switch).
     */
    protected function applyDefaultFilters(): void
    {
        $defaults = $this->getResolvedDefaultFilters();
        $this->filters = $defaults ?? [];

        $this->getFiltersForm()->fill($this->filters);

        // Persist to session
        if ($this->persistsFiltersInSession()) {
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }

    /**
     * Get resolved default filters from the current dashboard.
     *
     * @return array<string, mixed>|null
     */
    protected function getResolvedDefaultFilters(): ?array
    {
        $dashboard = $this->getCurrentDashboard();

        if (! $dashboard) {
            return null;
        }

        $defaults = $dashboard->filters ?? [];

        if (empty($defaults)) {
            return null;
        }

        return static::resolveFilterDefaults($defaults);
    }

    /**
     * Convert stored default filter values to actual filter values.
     * Called when applying defaults on first load or reset.
     *
     * @param  array<string, mixed>  $defaults  The stored default values
     * @return array<string, mixed> The resolved filter values
     */
    public static function resolveFilterDefaults(array $defaults): array
    {
        return $defaults;
    }

    /**
     * Clear the cached dashboard before Livewire serializes the state.
     * This prevents 404 errors when a dashboard is deleted.
     *
     * Also the last point of every request at which the secondary filters
     * area can be forced open, once the filters have their final values.
     */
    public function dehydrate(): void
    {
        $this->openSecondaryFiltersWhenActive();

        $this->currentDashboard = null;
    }

    /**
     * Build the main content schema for the dashboard page.
     *
     * Combines the filters form (if filters are defined) and the widgets grid.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...(static::hasFilters() ? [$this->getFiltersFormContentComponent()] : []),
                $this->getWidgetsContentComponent(),
            ]);
    }

    /**
     * Check if page has filters defined.
     */
    public static function hasFilters(): bool
    {
        return count(static::getDashboardFilters()) > 0;
    }

    /**
     * Override in child class to provide filters.
     *
     * @return array<Field>
     */
    public static function getDashboardFilters(): array
    {
        return [];
    }

    /**
     * Override in child class to move some filters behind a "More filters" toggle.
     *
     * Return the names of the filters from `getDashboardFilters()` that are
     * secondary, either as a flat list:
     *
     *     ['brand', 'supplier']
     *
     * or grouped under a heading:
     *
     *     ['Customer' => ['prescriber', 'age_bracket'], 'Product' => ['brand', 'supplier']]
     *
     * Every other filter stays in the main row. Names that match no filter are
     * ignored. Secondary filters remain ordinary filters everywhere else:
     * default values, per-dashboard visibility, session and `pageFilters`.
     *
     * @return array<int|string, string|array<int, string>>
     */
    public static function getSecondaryDashboardFilters(): array
    {
        return [];
    }

    /**
     * Normalise `getSecondaryDashboardFilters()` to a list of groups.
     *
     * A flat list becomes one group without heading. A name listed more than
     * once is kept where it appears first.
     *
     * @return list<array{heading: string|null, names: list<string>}>
     */
    protected static function getSecondaryDashboardFilterGroups(): array
    {
        $groups = [];
        $ungrouped = [];
        $seen = [];

        foreach (static::getSecondaryDashboardFilters() as $heading => $names) {
            $isGroup = is_array($names);
            $collected = [];

            foreach ($isGroup ? $names : [$names] as $name) {
                if (! is_string($name) || isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
                $collected[] = $name;
            }

            if (! $isGroup) {
                $ungrouped = [...$ungrouped, ...$collected];

                continue;
            }

            $groups[] = [
                'heading' => is_string($heading) ? $heading : null,
                'names' => $collected,
            ];
        }

        if ($ungrouped !== []) {
            array_unshift($groups, ['heading' => null, 'names' => $ungrouped]);
        }

        return $groups;
    }

    /**
     * Whether the current dashboard displays the given filter.
     *
     * @param  array<string, bool>  $displayFilters  The dashboard's visibility map; empty means "show everything".
     */
    protected static function isFilterDisplayed(array $displayFilters, string $filterName): bool
    {
        return empty($displayFilters) || ($displayFilters[$filterName] ?? true);
    }

    /**
     * Names of the secondary filters displayed on the current dashboard.
     *
     * `filtersForm()` normally fills the cache while it builds the bar; this
     * resolves it on its own when the form was not built.
     *
     * @return list<string>
     */
    protected function getVisibleSecondaryFilterNames(): array
    {
        if ($this->visibleSecondaryFilterNames !== null) {
            return $this->visibleSecondaryFilterNames;
        }

        $groups = static::getSecondaryDashboardFilterGroups();
        $dashboard = $this->getCurrentDashboard();

        if ($groups === [] || ! $dashboard) {
            return $this->visibleSecondaryFilterNames = [];
        }

        $displayFilters = $dashboard->getDisplayFilters();
        $knownNames = array_map(fn (Field $field): string => $field->getName(), static::getDashboardFilters());
        $names = [];

        foreach ($groups as $group) {
            foreach ($group['names'] as $name) {
                if (in_array($name, $knownNames, true) && static::isFilterDisplayed($displayFilters, $name)) {
                    $names[] = $name;
                }
            }
        }

        return $this->visibleSecondaryFilterNames = $names;
    }

    /**
     * Number of displayed secondary filters that currently have a value.
     *
     * A filter hidden on the current dashboard is not counted: it is not in
     * the "More filters" area, so opening the area would not reveal it.
     */
    protected function countActiveSecondaryFilters(): int
    {
        $count = 0;

        foreach ($this->getVisibleSecondaryFilterNames() as $name) {
            if (static::isFilterValueFilled(data_get($this->filters, $name))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Names of all the filters displayed on the current dashboard, primary
     * and secondary.
     *
     * `filtersForm()` normally fills the cache while it builds the bar; this
     * resolves it on its own when the form was not built.
     *
     * @return list<string>
     */
    protected function getVisibleFilterNames(): array
    {
        if ($this->visibleFilterNames !== null) {
            return $this->visibleFilterNames;
        }

        $dashboard = $this->getCurrentDashboard();

        if (! static::hasFilters() || ! $dashboard) {
            return $this->visibleFilterNames = [];
        }

        $displayFilters = $dashboard->getDisplayFilters();
        $names = [];

        foreach (static::getDashboardFilters() as $field) {
            if (static::isFilterDisplayed($displayFilters, $field->getName())) {
                $names[] = $field->getName();
            }
        }

        return $this->visibleFilterNames = $names;
    }

    /**
     * Number of displayed filters, primary and secondary, that currently
     * have a value. A default value counts like one the user picked.
     */
    protected function countActiveFilters(): int
    {
        $count = 0;

        foreach ($this->getVisibleFilterNames() as $name) {
            if (static::isFilterValueFilled(data_get($this->filters, $name))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether a filter state counts as "has a value".
     *
     * Empty strings, null and `false` (an unchecked toggle) do not; an array
     * does when at least one of its items does (multiple select, date range).
     */
    protected static function isFilterValueFilled(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (static::isFilterValueFilled($item)) {
                    return true;
                }
            }

            return false;
        }

        return $value !== false && filled($value);
    }

    /**
     * Keep the secondary filters area open while one of them has a value, so
     * an active filter is never hidden behind the toggle.
     *
     * Runs at the end of every request: on first load, after defaults are
     * applied or reset, after a dashboard switch, and after any live update.
     * It never closes the area; only the user does that, once the filters are
     * empty again.
     */
    protected function openSecondaryFiltersWhenActive(): void
    {
        if ($this->countActiveSecondaryFilters() > 0) {
            $this->secondaryFiltersOpen = true;
        }
    }

    /**
     * Get the embedded schema component for the filters form.
     *
     * This component is rendered above the widgets grid when filters are defined.
     */
    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
    }

    /**
     * Build and return the widgets content component.
     *
     * Renders the dashboard canvas as a single Blade view (`livewire.dashboard-grid`).
     * Each section becomes its own GridStack instance; widgets are projected to plain
     * arrays and the view renders each Livewire widget directly via `@livewire`.
     */
    public function getWidgetsContentComponent(): Component
    {
        $dashboard = $this->getCurrentDashboard();
        $template = $dashboard?->template
            ?? app(TemplateRegistry::class)->default();

        $canEdit = static::canEdit();
        $canDrag = $canEdit && ! ($dashboard?->is_locked ?? false);

        return ViewComponent::make('filament-dynamic-dashboard::livewire.dashboard-grid')
            // viewData is a Closure so it is evaluated at render time, not when
            // this component is built. Livewire builds the page schema before it
            // applies an incoming filter update, so a static array would capture a
            // stale `$this->filters` (one update behind); the Closure reads it
            // after the update, keeping `pageFilters` current for every widget.
            ->viewData(fn (): array => [
                'template' => $template,
                'widgetsBySection' => $this->buildWidgetsViewData($template),
                // Mirrors standard Filament: every widget is mounted with the page's
                // current filters under `pageFilters`, so widgets using
                // InteractsWithPageFilters get the active values.
                'pageFilters' => $this->filters ?? [],
                // canEdit = user permission (decides whether action chrome ever renders).
                // canDrag = canEdit AND ! is_locked (decides GridStack static mode and
                // the `is-readonly` canvas class that hides the action chrome via CSS).
                'canEdit' => $canEdit,
                'canDrag' => $canDrag,
            ]);
    }

    /**
     * Project the current dashboard's widgets into a `[section_slug => array<widget data>]`
     * structure consumed by the canvas Blade view.
     *
     * Widgets whose `section_slug` is unknown to the template are reassigned to the
     * first section at render time. The DB is not touched until the next save.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function buildWidgetsViewData(DashboardTemplate $template): array
    {
        $widgets = $this->getCurrentDashboardWidgets() ?? collect();
        $sectionSlugs = array_map(fn ($s) => $s->slug, $template->sections);
        $firstSlug = $template->sections[0]->slug;

        $bySection = [];

        foreach ($widgets as $model) {
            if (! $this->isWidgetAvailableForDashboard($model->type)) {
                continue;
            }

            $type = $model->type;

            if (! class_exists($type) || ! is_subclass_of($type, DynamicWidget::class)) {
                continue;
            }

            if (! $type::canView()) {
                continue;
            }

            $slug = in_array($model->section_slug, $sectionSlugs, true)
                ? $model->section_slug
                : $firstSlug;

            $bySection[$slug][] = [
                'id' => $model->id,
                'type' => $type,
                'name' => $model->name,
                'displayTitle' => $model->display_title,
                'settings' => $model->settings ?? [],
                'x' => $model->x,
                'y' => $model->y,
                'w' => $model->w,
                'h' => $model->h,
                'minW' => $type::getDynamicDashboardMinWidth(),
                'maxW' => $type::getDynamicDashboardMaxWidth(),
                'minH' => $type::getDynamicDashboardMinHeight(),
                'maxH' => $type::getDynamicDashboardMaxHeight(),
                'showLoader' => method_exists($type, 'showLoader')
                    ? ($type::showLoader() ?? static::showWidgetLoader())
                    : static::showWidgetLoader(),
            ];
        }

        return $bySection;
    }

    /**
     * Get widgets for the current dashboard.
     */
    public function getCurrentDashboardWidgets(): ?Collection
    {
        $dashboard = $this->getCurrentDashboard();

        if (! $dashboard) {
            return null;
        }

        return DashboardWidget::query()
            ->availableFor($dashboard)
            ->get();
    }

    /**
     * Check if a widget is available for the current dashboard page.
     *
     * @param  class-string<DynamicWidget>  $widgetClass
     */
    protected function isWidgetAvailableForDashboard(string $widgetClass): bool
    {
        // A stored type can outlive its class, or end up naming a class that
        // is no longer a dynamic widget: check before calling anything on it.
        if (! class_exists($widgetClass) || ! is_subclass_of($widgetClass, DynamicWidget::class) || ! $widgetClass::canView()) {
            return false;
        }
        // Check if the widget has the availableForDashboard method
        if (method_exists($widgetClass, 'availableForDashboard')) {
            $allowedDashboards = $widgetClass::availableForDashboard();
            // Empty array means available for all dashboards
            if (empty($allowedDashboards)) {
                return true;
            }

            // Check if current dashboard page class is in the allowed list
            return in_array(static::class, $allowedDashboards, true);
        }

        return true; // No restriction, available for all
    }

    /**
     * Build the form schema for creating/editing a widget.
     *
     * The form includes:
     * - Name input with display title toggle
     * - Widget type selector (from discovered DynamicWidget classes)
     * - Dynamic settings section (specific to the selected widget type)
     *
     * Size and position are NOT in the form — width/height come from the widget
     * class's static methods, and (x, y, section) are managed by GridStack on
     * the canvas. The section is only asked for when a widget is created on
     * a template that has more than one.
     */
    protected function getWidgetFormSchema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        // Column 1: Widget Information
                        Grid::make()
                            ->schema([
                                Hidden::make('widget_id'),
                                Flex::make([
                                    TextInput::make('name')
                                        ->label(__('filament-dynamic-dashboard::dashboard.widget_name'))
                                        ->required()
                                        ->maxLength(255)
                                        ->grow(),
                                    Toggle::make('display_title')
                                        ->hiddenLabel()
                                        ->hintIcon(Heroicon::OutlinedEye)
                                        ->hintIconTooltip(__('filament-dynamic-dashboard::dashboard.display_title'))
                                        ->default(true)
                                        ->grow(false),
                                ])->verticallyAlignEnd()->gap(false),

                                Select::make('type')
                                    ->label(__('filament-dynamic-dashboard::dashboard.widget_type'))
                                    ->options(fn (): array => $this->getAvailableWidgetOptions())
                                    ->placeholder(__('filament-dynamic-dashboard::dashboard.widget_type_placeholder'))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Select $component): void {
                                        // load the default values
                                        $component->getRootContainer()
                                            ->getComponent('widgetSettings')
                                            ?->getChildSchema()
                                            ?->fill();
                                    }),

                                Select::make('section_slug')
                                    ->label(__('filament-dynamic-dashboard::dashboard.section'))
                                    ->options(fn (): array => $this->getTemplateSectionOptions())
                                    ->default(fn (): ?string => $this->getCurrentDashboard()?->template->sections[0]->slug)
                                    // Section is asked for at creation only — once the widget exists, cross-section
                                    // moves happen via drag-and-drop. `widget_id` is the Hidden field filled by the
                                    // edit action's mountUsing, so its presence distinguishes edit from create.
                                    ->visible(fn (Get $get): bool => $this->templateHasMultipleSections() && empty($get('widget_id')))
                                    ->selectablePlaceholder(false)
                                    ->required(fn (Get $get): bool => empty($get('widget_id'))),
                            ])
                            ->columns(1)
                            ->columnSpan(1),

                        // Column 2: Widget Settings
                        Section::make(__('filament-dynamic-dashboard::dashboard.widget_settings'))
                            ->key('widgetSettings')
                            ->schema(fn (Get $get): array => $this->getWidgetSettingsSchema($get('type')))
                            ->visible(fn (Get $get): bool => $get('type') !== null && $this->hasWidgetSettings($get('type')))
                            ->columnSpan(1),
                    ]),
            ]);
    }

    /**
     * Get section options for the section selector in the widget form.
     *
     * Keyed by slug; value is the localized section name, falling back to
     * the slug itself when the section has no header.
     *
     * @return array<string, string>
     */
    protected function getTemplateSectionOptions(): array
    {
        $template = $this->getCurrentDashboard()?->template
            ?? app(TemplateRegistry::class)->default();

        $options = [];
        foreach ($template->sections as $section) {
            $options[$section->slug] = $section->name() ?? $section->slug;
        }

        return $options;
    }

    /**
     * Whether the current dashboard's template defines more than one section.
     *
     * Drives the visibility of the section Select in the widget form.
     */
    protected function templateHasMultipleSections(): bool
    {
        $template = $this->getCurrentDashboard()?->template
            ?? app(TemplateRegistry::class)->default();

        return count($template->sections) > 1;
    }

    /**
     * Get widget options for the type selector dropdown.
     *
     * @return array<string, string> Widget class => label pairs
     */
    protected function getAvailableWidgetOptions(): array
    {
        $widgets = [];

        foreach ($this->discoverDynamicWidgets() as $widgetClass) {
            $widgets[$widgetClass] = $widgetClass::getWidgetLabel();
        }

        asort($widgets);

        return $widgets;
    }

    /**
     * Discover all DynamicWidget classes registered in the current Filament panel.
     *
     * Only returns widgets that:
     * - Implement the DynamicWidget contract
     * - Are available for this specific dashboard page (via availableForDashboard())
     *
     * @return array<class-string<DynamicWidget>>
     */
    protected function discoverDynamicWidgets(): array
    {
        $widgets = [];
        $panel = filament()->getCurrentPanel();

        if ($panel) {
            foreach ($panel->getWidgets() as $widgetClass) {
                if (is_subclass_of($widgetClass, DynamicWidget::class)) {
                    // Check if widget is available for this dashboard page
                    if ($this->isWidgetAvailableForDashboard($widgetClass)) {
                        $widgets[] = $widgetClass;
                    }
                }
            }
        }

        return $widgets;
    }

    /**
     * @param  class-string<DynamicWidget>|null  $type
     * @return array<Component>
     */
    protected function getWidgetSettingsSchema(?string $type): array
    {
        if ($type === null || ! class_exists($type) || ! is_subclass_of($type, DynamicWidget::class)) {
            return [];
        }

        return [Group::make()
            ->statePath('settings')
            ->schema($type::getSettingsFormSchema())];
    }

    /**
     * @param  class-string<DynamicWidget>|null  $type
     */
    protected function hasWidgetSettings(?string $type): bool
    {
        if ($type === null || ! class_exists($type) || ! is_subclass_of($type, DynamicWidget::class)) {
            return false;
        }

        return count($type::getSettingsFormSchema()) > 0;
    }

    /**
     * Reset filters to defaults.
     */
    public function resetFilters(): void
    {
        $this->applyDefaultFilters();
    }

    /**
     * Build the filters form schema.
     *
     * Creates a section with the primary filter fields and a reset button.
     * Filters listed by `getSecondaryDashboardFilters()` go to a separate area
     * below, opened by a "More filters" control placed next to the reset button.
     * Filter visibility is controlled by the dashboard's display_filters setting.
     */
    public function filtersForm(Schema $schema): Schema
    {
        $dashboard = $this->getCurrentDashboard();

        if (static::hasFilters() && $dashboard) {
            $displayFilters = $dashboard->getDisplayFilters();
            $fields = static::getDashboardFilters();
            $fieldsByName = [];
            $visibleNames = [];
            foreach ($fields as $field) {
                $fieldName = $field->getName();
                $isVisible = static::isFilterDisplayed($displayFilters, $fieldName);
                $field->visible($isVisible);
                $fieldsByName[$fieldName] = $field;
                if ($isVisible) {
                    $visibleNames[$fieldName] = true;
                }
            }
            $fieldsVisible = count($visibleNames);
            $this->visibleFilterNames = array_keys($visibleNames);

            // Split the secondary filters off, group by group. A page that
            // declares none keeps every field in the main row, as before.
            $secondaryGroups = [];
            $secondaryNames = [];
            $visibleSecondaryNames = [];
            foreach (static::getSecondaryDashboardFilterGroups() as $group) {
                $groupFields = [];
                $groupVisible = 0;
                foreach ($group['names'] as $name) {
                    if (! isset($fieldsByName[$name])) {
                        continue;
                    }

                    $groupFields[] = $fieldsByName[$name];
                    $secondaryNames[$name] = true;

                    if (isset($visibleNames[$name])) {
                        $visibleSecondaryNames[] = $name;
                        $groupVisible++;
                    }
                }

                if ($groupFields !== []) {
                    $secondaryGroups[] = [
                        'heading' => $group['heading'],
                        'fields' => $groupFields,
                        'visible' => $groupVisible,
                    ];
                }
            }
            $this->visibleSecondaryFilterNames = $visibleSecondaryNames;

            $primaryFields = array_values(array_filter(
                $fields,
                fn (Field $field): bool => ! isset($secondaryNames[$field->getName()]),
            ));
            $primaryVisible = $fieldsVisible - count($visibleSecondaryNames);

            // Actions - grow(false) keeps them at minimal width. The toggle is
            // only there when a secondary filter is displayed on this dashboard.
            // An end-aligned row is displayed in reverse order, so listing the
            // reset action first keeps it at the far end, where it always was.
            // The last one, at the other end, only exists on small screens.
            $collapsesOnSmallScreens = static::collapseFiltersOnSmallScreens();

            $barActions = Actions::make([
                Action::make('resetFilters')
                    ->tooltip(__('filament-dynamic-dashboard::dashboard.reset_filters'))
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action('resetFilters'),
                ...($visibleSecondaryNames !== [] ? [$this->getSecondaryFiltersToggleAction()] : []),
                ...($collapsesOnSmallScreens ? [$this->getSmallScreenFiltersToggleAction()] : []),
            ])->alignEnd()->grow(false);

            // The classes below are only hooks for the small-screen rules of
            // the package stylesheet; `dd-filters-open` follows the Livewire
            // flag in the browser. None of them changes a wider screen.
            $schema->components([
                Section::make()
                    ->extraAttributes($collapsesOnSmallScreens ? [
                        'class' => 'dd-filters',
                        'x-bind:class' => '{ \'dd-filters-open\': $wire.smallScreenFiltersOpen }',
                    ] : [])
                    ->schema([
                        Flex::make([
                            // set a minimum of 4 columns for filter
                            Grid::make((max($primaryVisible, 4)))
                                ->schema($primaryFields)
                                ->grow(),
                            $barActions,
                        ])
                            ->verticallyAlignCenter()
                            ->extraAttributes(['class' => 'dd-filters-bar']),
                        ...($secondaryGroups !== []
                            ? [$this->getSecondaryFiltersComponent($secondaryGroups, $visibleSecondaryNames !== [])]
                            : []),
                    ])
                    ->columnSpanFull()
                    ->visible($fieldsVisible > 0)
                    ->compact(),
            ]);
        }

        return $schema;
    }

    /**
     * Build the "More filters" control shown next to the reset button.
     *
     * It flips `secondaryFiltersOpen` in the browser, without a request. While
     * a secondary filter has a value it is disabled: the area has to stay open
     * then, and its label carries the number of those filters.
     */
    protected function getSecondaryFiltersToggleAction(): Action
    {
        return Action::make('toggleSecondaryFilters')
            ->label(fn (): string => $this->getSecondaryFiltersToggleLabel())
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->disabled(fn (): bool => $this->countActiveSecondaryFilters() > 0)
            ->alpineClickHandler('$wire.secondaryFiltersOpen = ! $wire.secondaryFiltersOpen')
            ->extraAttributes([
                'class' => 'dd-filters-more',
                'x-bind:aria-expanded' => '$wire.secondaryFiltersOpen ? \'true\' : \'false\'',
            ]);
    }

    /**
     * Build the "Filters" control that unfolds the bar on a small screen.
     *
     * It flips `smallScreenFiltersOpen` in the browser, without a request,
     * and its label carries the number of filters that have a value.
     *
     * The button is hidden by an inline style and only the small-screen rules
     * of the package stylesheet reveal it. With a stylesheet that was not
     * republished after an update, it therefore stays hidden and the bar
     * simply shows every filter, as it did before.
     */
    protected function getSmallScreenFiltersToggleAction(): Action
    {
        return Action::make('toggleSmallScreenFilters')
            ->label(fn (): string => $this->getSmallScreenFiltersToggleLabel())
            ->icon(Heroicon::OutlinedFunnel)
            ->color('gray')
            ->alpineClickHandler('$wire.smallScreenFiltersOpen = ! $wire.smallScreenFiltersOpen')
            ->extraAttributes([
                'class' => 'dd-filters-toggle',
                'style' => 'display: none',
                'x-bind:aria-expanded' => '$wire.smallScreenFiltersOpen ? \'true\' : \'false\'',
            ]);
    }

    /**
     * Label of the "Filters" control, with the number of filters that have a
     * value when there is at least one.
     */
    protected function getSmallScreenFiltersToggleLabel(): string
    {
        $count = $this->countActiveFilters();

        return $count > 0
            ? __('filament-dynamic-dashboard::dashboard.filters_count', ['count' => $count])
            : __('filament-dynamic-dashboard::dashboard.filters');
    }

    /**
     * Label of the "More filters" control, with the number of secondary
     * filters that have a value when there is at least one.
     */
    protected function getSecondaryFiltersToggleLabel(): string
    {
        $count = $this->countActiveSecondaryFilters();

        return $count > 0
            ? __('filament-dynamic-dashboard::dashboard.more_filters_count', ['count' => $count])
            : __('filament-dynamic-dashboard::dashboard.more_filters');
    }

    /**
     * Build the secondary filters area rendered below the main row.
     *
     * The fields stay regular, server-visible form components — so their state,
     * defaults and `pageFilters` behave like any other filter — and the area is
     * only hidden in the browser, bound to `secondaryFiltersOpen`.
     *
     * @param  list<array{heading: string|null, fields: list<Field>, visible: int}>  $groups
     * @param  bool  $isDisplayed  False when the current dashboard displays none of the secondary filters.
     */
    protected function getSecondaryFiltersComponent(array $groups, bool $isDisplayed): Component
    {
        $components = [];

        foreach ($groups as $group) {
            // A fixed, responsive column count: the area can hold many filters.
            $grid = Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])
                ->schema($group['fields']);

            $components[] = Group::make([
                ...($group['heading'] !== null
                    ? [Text::make($group['heading'])->weight(FontWeight::Medium)->color('gray')]
                    : []),
                $grid,
            ])
                ->columns(1)
                ->visible($group['visible'] > 0);
        }

        return Group::make($components)
            ->key('secondaryFilters')
            ->extraAttributes(['class' => 'dd-filters-secondary'])
            ->columns(1)
            ->columnSpanFull()
            ->visible($isDisplayed)
            ->visibleJs('$wire.secondaryFiltersOpen');
    }

    /**
     * Get the page heading (displays the current dashboard name).
     */
    public function getHeading(): string|Htmlable|null
    {
        return $this->getCurrentDashboard()?->name ?? parent::getHeading();
    }

    /**
     * Get the page subheading (displays the current dashboard description as HTML).
     */
    public function getSubheading(): string|Htmlable|null
    {
        $description = $this->getCurrentDashboard()?->description;

        if (blank($description)) {
            return parent::getSubheading();
        }

        // The description is user-authored rich text: never output it raw.
        return Html::make(Str::sanitizeHtml($description));
    }

    /**
     * Handle dashboard list changed event from the DashboardManager component.
     *
     * Reloads the cached dashboard, then dispatches a JS event so Alpine can
     * flip GridStack's static mode and toggle the `is-readonly` canvas class
     * — the `.grid-stack` divs carry `wire:ignore`, so a Livewire morph alone
     * won't reach them.
     *
     * The page header is not always refreshed here: while the manager
     * slideover is open Filament 5 only re-renders the action modals, so the
     * Add Widget button follows `is_locked` once the slideover is closed. Its
     * action is refused on the server in the meantime.
     */
    #[On('dashboard-list-changed')]
    public function onDashboardListChanged(): void
    {
        $this->loadCurrentDashboard();

        $this->dispatch(
            'dynamic-dashboard:editable-changed',
            editable: static::canEdit() && ! ($this->currentDashboard?->is_locked ?? false),
        );
    }

    /**
     * Get the header actions for the dashboard page.
     *
     * @return array<Action|ActionGroup> Contains add widget button and dashboard selector dropdown
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->getAddWidgetAction(),
            $this->getDashboardSelectorActionGroup(),
        ];
    }

    /**
     * Create the "Add Widget" action button.
     *
     * Opens a modal form to create a new widget for the current dashboard.
     * Only visible when user can edit and dashboard is not locked.
     */
    protected function getAddWidgetAction(): Action
    {
        return Action::make('addWidget')
            ->label(__('filament-dynamic-dashboard::dashboard.add_widget'))
            ->icon(Heroicon::OutlinedPlus)
            ->size(Size::Small)
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.add_widget'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('filament-dynamic-dashboard::dashboard.add_button'))
            ->modalFooterActionsAlignment(Alignment::End)
            ->schema(fn (Schema $schema): Schema => $this->getWidgetFormSchema($schema))
            ->visible(fn (): bool => $this->canEditCurrentDashboardWidgets())
            ->action(function (array $data): void {
                $this->createWidget($data);
            });
    }

    /**
     * Create a new widget from form data.
     *
     * Width/height come from the widget class's static defaults; the widget is
     * placed in the chosen section (the first one of the current template by
     * default), on the first row below every existing widget.
     *
     * @param  array<string, mixed>  $data  Form data containing name, type, display_title, settings.
     */
    protected function createWidget(array $data): void
    {
        abort_unless($this->canEditCurrentDashboardWidgets(), 403);

        /** @var class-string<DynamicWidget> $type */
        $type = $data['type'];

        $template = $this->getCurrentDashboard()?->template
            ?? app(TemplateRegistry::class)->default();
        $sectionSlug = $data['section_slug'] ?? $template->sections[0]->slug;

        DashboardWidget::create([
            'dashboard_id' => $this->currentDashboardId,
            'name' => $data['name'],
            'type' => $type,
            'section_slug' => $sectionSlug,
            'x' => 0,
            'y' => DashboardWidget::nextFreeRow($this->currentDashboardId, $sectionSlug),
            'w' => $type::getDynamicDashboardDefaultWidth(),
            'h' => $type::getDynamicDashboardDefaultHeight(),
            'display_title' => $data['display_title'] ?? true,
            'settings' => $data['settings'] ?? [],
        ]);

        $this->redirectToDashboard();
    }

    /**
     * Create the dashboard selector dropdown action group.
     *
     * Displays a dropdown with:
     * - Global dashboards first (no icon)
     * - A visual separator (nested non-dropdown ActionGroup)
     * - Personal dashboards next (each prefixed with a user icon)
     * - "Manage" option to open the dashboard manager slideover (if user can edit)
     *
     * The selected dashboard always shows the green check icon, even when it's
     * personal — selection state takes priority over the personal indicator.
     */
    protected function getDashboardSelectorActionGroup(): ActionGroup
    {
        $dashboards = $this->getDisplayableDashboards();
        $currentDashboard = $this->getCurrentDashboard();

        $globalActions = [];
        $personalActions = [];

        foreach ($dashboards as $dashboard) {
            $isCurrent = $dashboard->id === $this->currentDashboardId;

            $action = Action::make('selectDashboard_'.$dashboard->id)
                ->label($dashboard->name)
                ->icon(match (true) {
                    $isCurrent => Heroicon::OutlinedCheck,
                    $dashboard->is_personal => Heroicon::OutlinedUser,
                    default => null,
                })
                ->color($isCurrent ? Color::Green : null)
                ->action(function () use ($dashboard): void {
                    $this->currentDashboardId = $dashboard->id;
                    $this->redirectToDashboard();
                });

            if ($dashboard->is_personal) {
                $personalActions[] = $action;
            } else {
                $globalActions[] = $action;
            }
        }

        // Add manage action with separator
        $manageAction = Action::make('manageDashboards')
            ->label(__('filament-dynamic-dashboard::dashboard.manage'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color(Color::Blue)
            ->slideOver()
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.manage'))
            ->modalContent(fn (): View => view('filament-dynamic-dashboard::livewire.dashboard-manager-modal', [
                'pageClass' => static::class,
                'currentDashboardId' => $this->currentDashboardId,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->visible(static::canEdit());

        // Each nested non-dropdown ActionGroup renders as its own visual section,
        // giving the global/personal separator for free. Empty groups are filtered
        // out so users without personal dashboards don't see an empty section.
        $groups = array_filter([
            $globalActions !== [] ? ActionGroup::make($globalActions)->dropdown(false) : null,
            $personalActions !== [] ? ActionGroup::make($personalActions)->dropdown(false) : null,
            $manageAction,
        ]);

        return ActionGroup::make($groups)
            ->label($currentDashboard?->name ?? __('filament-dynamic-dashboard::dashboard.select_dashboard'))
            ->icon(Heroicon::OutlinedViewColumns)
            ->color('gray')
            ->dropdownWidth(Width::ExtraSmall)
            ->button()
            ->dropdownPlacement('bottom-end');
    }

    /**
     * Reload the dashboard page after a change the canvas cannot apply in
     * place (widget added, edited or deleted, dashboard switched).
     *
     * Follows the panel's configuration like Filament's own pages do: a
     * Livewire navigation when the panel runs in SPA mode, a regular redirect
     * otherwise.
     */
    protected function redirectToDashboard(): void
    {
        $url = static::getUrl();

        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    /**
     * Get the currently active dashboard.
     */
    public function getCurrentDashboard(): ?Dashboard
    {
        if ($this->currentDashboard === null) {
            $this->loadCurrentDashboard();
        }

        return $this->currentDashboard;
    }

    /**
     * Page-level Filament Action for editing a widget by ID.
     *
     * Mounted from the Blade wrapper via `$wire.mountAction('editWidget', { widget: <id> })`.
     * The widget id is carried via a Hidden field in the form so the submit handler
     * knows what to update. The widget is always resolved within the current
     * dashboard, and the action is unavailable while the dashboard is locked.
     */
    public function editWidgetAction(): Action
    {
        return Action::make('editWidget')
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.edit_widget'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalFooterActionsAlignment(Alignment::End)
            ->visible(fn (): bool => $this->canEditCurrentDashboardWidgets())
            ->mountUsing(function (Schema $schema, array $arguments): void {
                $widget = $this->findCurrentDashboardWidget($arguments['widget'] ?? null);

                if (! $widget) {
                    return;
                }

                // section_slug is intentionally NOT filled — the section field is
                // hidden in edit mode. Cross-section moves are drag-only.
                $schema->fill([
                    'widget_id' => $widget->id,
                    'name' => $widget->name,
                    'type' => $widget->type,
                    'display_title' => $widget->display_title,
                    'settings' => $widget->settings,
                ]);
            })
            ->schema(fn (Schema $schema): Schema => $this->getWidgetFormSchema($schema))
            ->action(function (array $data): void {
                abort_unless($this->canEditCurrentDashboardWidgets(), 403);

                $widget = $this->findCurrentDashboardWidget($data['widget_id'] ?? null);
                $widget?->update([
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'display_title' => $data['display_title'] ?? true,
                    'settings' => $data['settings'] ?? [],
                ]);

                $this->redirectToDashboard();
            });
    }

    /**
     * Page-level Filament Action for deleting a widget by ID.
     *
     * Mounted from the Blade wrapper via `$wire.mountAction('deleteWidget', { widget: <id> })`.
     * The widget is always resolved within the current dashboard, and the
     * action is unavailable while the dashboard is locked.
     */
    public function deleteWidgetAction(): Action
    {
        return Action::make('deleteWidget')
            ->requiresConfirmation()
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete_widget'))
            ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_widget_confirmation'))
            ->visible(fn (): bool => $this->canEditCurrentDashboardWidgets())
            ->action(function (array $arguments): void {
                abort_unless($this->canEditCurrentDashboardWidgets(), 403);

                $widget = $this->findCurrentDashboardWidget($arguments['widget'] ?? null);
                $widget?->delete();

                $this->redirectToDashboard();
            });
    }

    /**
     * Persist widget positions after a GridStack drag, resize, or cross-section drop.
     *
     * Called from Alpine via `$wire.call('persistLayout', [...])`. Each item is
     * `{id, section, x, y, w, h}`. Updates are scoped to the current dashboard so
     * a malicious payload can't move another dashboard's widgets.
     *
     * Items with non-numeric coordinates, a negative position or an empty size
     * are skipped. A section slug unknown to the current template is stored as
     * the template's first section — the same fallback the canvas applies at
     * render time — so the database never keeps a stale slug.
     *
     * Marked `#[Renderless]` so the call hits the DB but skips Livewire's
     * re-render — without this, the post-call morph would clobber GridStack's
     * runtime DOM state and visually reset every widget.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws Throwable
     */
    #[Renderless]
    public function persistLayout(array $items): void
    {
        // Same rule as adding, editing and deleting a widget: the dashboard
        // must still be available to this user, and unlocked.
        abort_unless($this->canEditCurrentDashboardWidgets(), 403);

        $template = $this->getCurrentDashboard()?->template
            ?? app(TemplateRegistry::class)->default();
        $sectionSlugs = array_map(fn (DashboardSection $section): string => $section->slug, $template->sections);

        DB::transaction(function () use ($items, $sectionSlugs): void {
            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['id'], $item['section'], $item['x'], $item['y'], $item['w'], $item['h'])) {
                    continue;
                }

                if (! is_numeric($item['id']) || ! is_string($item['section'])) {
                    continue;
                }

                foreach (['x', 'y', 'w', 'h'] as $coordinate) {
                    if (! is_numeric($item[$coordinate])) {
                        continue 2;
                    }
                }

                [$x, $y, $w, $h] = [(int) $item['x'], (int) $item['y'], (int) $item['w'], (int) $item['h']];

                if ($x < 0 || $y < 0 || $w < 1 || $h < 1) {
                    continue;
                }

                DashboardWidget::query()
                    ->where('id', (int) $item['id'])
                    ->where('dashboard_id', $this->currentDashboardId)
                    ->update([
                        'section_slug' => in_array($item['section'], $sectionSlugs, true)
                            ? $item['section']
                            : $sectionSlugs[0],
                        'x' => $x,
                        'y' => $y,
                        'w' => $w,
                        'h' => $h,
                    ]);
            }
        });
    }
}
