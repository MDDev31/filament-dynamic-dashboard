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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Session;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardWidget;
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
 * - `currentDashboard` is null on dehydrate to avoid serialisation of a
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
     */
    #[Session]
    public ?int $currentDashboardId = null;

    /**
     * Cached instance of the current dashboard (not persisted by Livewire).
     */
    public ?Dashboard $currentDashboard = null;

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

        if (!session()->has($sessionKey)) {
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
        if ($this->currentDashboardId && !$displayable->firstWhere('id', $this->currentDashboardId)) {
            $this->currentDashboardId = null;
        }

        // If no dashboard selected, select the first displayable
        if (!$this->currentDashboardId) {
            $this->currentDashboardId = $displayable->first()?->id;
        }

        // If dashboards exist but none are displayable, deny access
        if (!$this->currentDashboardId && $this->getAvailableDashboards()->exists()) {
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
            $query->with('roles');
        }

        return $query->get()->filter(
            fn(Dashboard $dashboard) => static::canDisplay($dashboard)
        );
    }

    /**
     * Get all available dashboards for selection.
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
        if ($dashboard->is_personal && $dashboard->created_by !== auth()->id()) {
            return false;
        }

        if (static::canEdit()) {
            return true;
        }

        if (method_exists($dashboard, 'roles') && $dashboard->roles->isNotEmpty()) {
            $user = auth()->user();

            if (!$user || !method_exists($user, 'hasAnyRole') || !$user->hasAnyRole($dashboard->roles)) {
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
     * Whether to show loading indicators on widgets.
     * Override in subclasses to disable globally.
     */
    public static function showWidgetLoader(): bool
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

        if (method_exists($this, 'getFiltersForm')) {
            $this->getFiltersForm()->fill($this->filters);
        }

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
        if (!$this->currentDashboard) {
            return null;
        }

        $defaults = $this->currentDashboard->filters ?? [];

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
     */
    public function dehydrate(): void
    {
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
        $template = $this->getCurrentDashboard()?->template
            ?? app(TemplateRegistry::class)->default();

        $canEdit = static::canEdit();
        $canDrag = $canEdit && ! ($this->currentDashboard?->is_locked ?? false);

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

        if (!$this->currentDashboard) {
            return null;
        }

        return DashboardWidget::query()
            ->availableFor($this->currentDashboard)
            ->get();
    }

    /**
     * Check if a widget is available for the current dashboard page.
     *
     * @param  class-string<DynamicWidget>  $widgetClass
     */
    protected function isWidgetAvailableForDashboard(string $widgetClass): bool
    {
        if (!class_exists($widgetClass) || !$widgetClass::canView()) {
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
     * the canvas. Phase 3 adds an optional section selector for multi-section
     * templates.
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
                                    ->options(fn(): array => $this->getAvailableWidgetOptions())
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
                                    ->options(fn(): array => $this->getTemplateSectionOptions())
                                    ->default(fn(): ?string => $this->getCurrentDashboard()?->template->sections[0]->slug ?? null)
                                    // Section is asked for at creation only — once the widget exists, cross-section
                                    // moves happen via drag-and-drop. `widget_id` is the Hidden field filled by the
                                    // edit action's mountUsing, so its presence distinguishes edit from create.
                                    ->visible(fn(Get $get): bool => $this->templateHasMultipleSections() && empty($get('widget_id')))
                                    ->selectablePlaceholder(false)
                                    ->required(fn(Get $get): bool => empty($get('widget_id'))),
                            ])
                            ->columns(1)
                            ->columnSpan(1),

                        // Column 2: Widget Settings
                        Section::make(__('filament-dynamic-dashboard::dashboard.widget_settings'))
                            ->key('widgetSettings')
                            ->schema(fn(Get $get): array => $this->getWidgetSettingsSchema($get('type')))
                            ->visible(fn(Get $get): bool => $get('type') !== null && $this->hasWidgetSettings($get('type')))
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
        if ($type === null || !class_exists($type) || !is_subclass_of($type, DynamicWidget::class)) {
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
        if ($type === null || !class_exists($type) || !is_subclass_of($type, DynamicWidget::class)) {
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
     * Creates a section with filter fields and a reset button.
     * Filter visibility is controlled by the dashboard's display_filters setting.
     */
    public function filtersForm(Schema $schema): Schema
    {
        if (static::hasFilters() && $this->currentDashboard) {
            $displayFilters = $this->currentDashboard->getDisplayFilters();
            $fields = static::getDashboardFilters();
            $fieldsVisible = 0;
            foreach ($fields as $field) {
                $fieldName = $field->getName();
                $isVisible = empty($displayFilters) || ($displayFilters[$fieldName] ?? true);
                $field->visible($isVisible);
                if ($isVisible) {
                    $fieldsVisible++;
                }
            }

            // Reset action - grow(false) keeps it at minimal width
            $resetAction = Actions::make([
                Action::make('resetFilters')
                    ->tooltip(__('filament-dynamic-dashboard::dashboard.reset_filters'))
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action('resetFilters'),
            ])->alignEnd()->grow(false);

            $schema->components([
                Section::make()
                    ->schema([
                        Flex::make([
                            // set a minimum of 4 columns for filter
                            Grid::make((max($fieldsVisible, 4)))
                                ->schema($fields)
                                ->grow(),
                            $resetAction,
                        ])->verticallyAlignCenter(),
                    ])
                    ->columnSpanFull()
                    ->visible($fieldsVisible > 0)
                    ->compact(),
            ]);
        }

        return $schema;
    }

    /**
     * Get the page heading (displays the current dashboard name).
     */
    public function getHeading(): string|Htmlable|null
    {
        return $this->currentDashboard?->name ?? parent::getHeading();
    }

    /**
     * Get the page subheading (displays the current dashboard description as HTML).
     */
    public function getSubheading(): string|Htmlable|null
    {
        return Html::make($this->currentDashboard?->description ?? parent::getSubheading());
    }

    /**
     * Handle dashboard list changed event from the DashboardManager component.
     *
     * Reloads the cached dashboard so canEdit/`is_locked`-driven UI bits
     * (heading, Add Widget button, Manage entry) re-evaluate on the morph,
     * then dispatches a JS event so Alpine can flip GridStack's static mode
     * and toggle the `is-readonly` canvas class — the `.grid-stack` divs
     * carry `wire:ignore`, so a Livewire morph alone won't reach them.
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
            ->schema(fn(Schema $schema): Schema => $this->getWidgetFormSchema($schema))
            ->visible(fn(): bool => $this->currentDashboardId && static::canEdit() && !$this->currentDashboard?->is_locked)
            ->action(function (array $data): void {
                $this->createWidget($data);
            });
    }

    /**
     * Create a new widget from form data.
     *
     * Width/height come from the widget class's static defaults; the widget is
     * placed in the first section of the current template at the bottom of
     * the existing widgets.
     *
     * @param  array<string, mixed>  $data  Form data containing name, type, display_title, settings.
     */
    protected function createWidget(array $data): void
    {
        abort_unless(static::canEdit(), 403);

        /** @var class-string<DynamicWidget> $type */
        $type = $data['type'];

        $template = $this->getCurrentDashboard()?->template
            ?? app(TemplateRegistry::class)->default();
        $sectionSlug = $data['section_slug'] ?? $template->sections[0]->slug;

        $maxY = DashboardWidget::query()
            ->where('dashboard_id', $this->currentDashboardId)
            ->where('section_slug', $sectionSlug)
            ->max('y') ?? -1;

        DashboardWidget::create([
            'dashboard_id' => $this->currentDashboardId,
            'name' => $data['name'],
            'type' => $type,
            'section_slug' => $sectionSlug,
            'x' => 0,
            'y' => $maxY + 1,
            'w' => $type::getDynamicDashboardDefaultWidth(),
            'h' => $type::getDynamicDashboardDefaultHeight(),
            'display_title' => $data['display_title'] ?? true,
            'settings' => $data['settings'] ?? [],
        ]);

        $this->redirect(static::getUrl(), navigate: true);
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
                    $this->redirect(static::getUrl(), navigate: true);
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
            ->modalContent(fn(): View => view('filament-dynamic-dashboard::livewire.dashboard-manager-modal', [
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
     * knows what to update.
     */
    public function editWidgetAction(): Action
    {
        return Action::make('editWidget')
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.edit_widget'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalFooterActionsAlignment(Alignment::End)
            ->mountUsing(function (Schema $schema, array $arguments): void {
                $widget = DashboardWidget::find($arguments['widget'] ?? null);

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
                abort_unless(static::canEdit(), 403);

                $widget = DashboardWidget::find($data['widget_id'] ?? null);
                $widget?->update([
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'display_title' => $data['display_title'] ?? true,
                    'settings' => $data['settings'] ?? [],
                ]);

                $this->redirect(static::getUrl(), navigate: true);
            });
    }

    /**
     * Page-level Filament Action for deleting a widget by ID.
     *
     * Mounted from the Blade wrapper via `$wire.mountAction('deleteWidget', { widget: <id> })`.
     */
    public function deleteWidgetAction(): Action
    {
        return Action::make('deleteWidget')
            ->requiresConfirmation()
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete_widget'))
            ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_widget_confirmation'))
            ->action(function (array $arguments): void {
                abort_unless(static::canEdit(), 403);

                $widget = DashboardWidget::find($arguments['widget'] ?? null);
                $widget?->delete();

                $this->redirect(static::getUrl(), navigate: true);
            });
    }

    /**
     * Persist widget positions after a GridStack drag, resize, or cross-section drop.
     *
     * Called from Alpine via `$wire.call('persistLayout', [...])`. Each item is
     * `{id, section, x, y, w, h}`. Updates are scoped to the current dashboard so
     * a malicious payload can't move another dashboard's widgets.
     *
     * Marked `#[Renderless]` so the call hits the DB but skips Livewire's
     * re-render — without this, the post-call morph would clobber GridStack's
     * runtime DOM state and visually reset every widget.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @throws Throwable
     */
    #[Renderless]
    public function persistLayout(array $items): void
    {
        abort_unless(
            static::canEdit() && ! ($this->getCurrentDashboard()?->is_locked ?? false),
            403
        );

        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                if (! isset($item['id'], $item['section'], $item['x'], $item['y'], $item['w'], $item['h'])) {
                    continue;
                }

                DashboardWidget::query()
                    ->where('id', (int) $item['id'])
                    ->where('dashboard_id', $this->currentDashboardId)
                    ->update([
                        'section_slug' => (string) $item['section'],
                        'x' => (int) $item['x'],
                        'y' => (int) $item['y'],
                        'w' => (int) $item['w'],
                        'h' => (int) $item['h'],
                    ]);
            }
        });
    }
}
