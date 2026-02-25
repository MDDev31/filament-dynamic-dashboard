<?php

namespace MDDev\DynamicDashboard\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Slider\Enums\PipsMode;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardGrid;
use MDDev\DynamicDashboard\Models\DashboardGridBlock;
use MDDev\DynamicDashboard\Models\DashboardWidget;

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
     * Editors always have access. Otherwise, Spatie roles are checked when
     * the model supports them, falling back to the page-level `canAccess()`.
     */
    public static function canDisplay(Dashboard $dashboard): bool
    {
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
            ? $this->getAvailableDashboards()
                ->with('grid.rootBlocks.children.children.children')
                ->find($this->currentDashboardId)
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
     * This method:
     * 1. Retrieves all widgets for the current dashboard
     * 2. Filters out unavailable or unauthorized widgets
     * 3. Groups widgets by their assigned grid block
     * 4. Wraps each widget with edit/delete actions (if user can edit)
     * 5. Returns the complete grid layout with nested blocks
     */
    public function getWidgetsContentComponent(): Component
    {
        $widgetModels = $this->getCurrentDashboardWidgets() ?? collect();

        // Filter and prepare widgets in a single pass
        $validWidgets = $widgetModels
            ->filter(fn(DashboardWidget $model) => $this->isWidgetAvailableForDashboard($model->type))
            ->map(fn(DashboardWidget $model) => [
                'model' => $model,
                'config' => $model->getWidget(),
            ])
            ->filter(fn(array $widgetData) => $widgetData['config'] !== null)
            ->filter(fn(array $widgetData) => $widgetData['config']->widget::canView())
            ->values();

        // Get configs for getWidgetsSchemaComponents
        $configs = $validWidgets->pluck('config')->all();

        // Get the Livewire components from parent method
        $livewireComponents = $this->getWidgetsSchemaComponents($configs);

        // Wrap widgets AND group by block in a single pass
        $widgetsByBlock = [];
        foreach ($validWidgets as $index => $pair) {
            $wrappedWidget = $this->wrapWidget($pair['model'], $livewireComponents[$index]);
            $block = $pair['model']->effective_grid_block;
            $blockSlug = $block?->slug;

            if ($blockSlug !== null) {
                if (!isset($widgetsByBlock[$blockSlug])) {
                    $widgetsByBlock[$blockSlug] = [];
                }
                $widgetsByBlock[$blockSlug][] = $wrappedWidget;
            }
        }
        return $this->widgetsGrid($widgetsByBlock);
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
            ->with(['gridBlock', 'dashboard.grid'])
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
     * Wrap a widget with a header containing name and action buttons.
     */
    protected function wrapWidget(DashboardWidget $widgetModel, Component $widgetComponent): Component
    {
        $widgetId = $widgetModel->id;
        $name = $widgetModel->name;
        $displayTitle = $widgetModel->display_title;
        $widgetClass = $widgetModel->type;

        $showLoader = method_exists($widgetClass, 'showLoader')
            ? $widgetClass::showLoader() ?? static::showWidgetLoader()
            : static::showWidgetLoader();

        return ViewComponent::make('filament-dynamic-dashboard::schemas.widget-wrapper')
            ->key('widget-wrapper-'.$widgetId)
            ->viewData([
                'name' => $name,
                'displayTitle' => $displayTitle,
                'canEdit' => static::canEdit(),
                'isLocked' => $this->currentDashboard?->is_locked ?? false,
                'showLoader' => $showLoader,
            ])
            ->schema([
                // Actions (first child - rendered in hover container)
                Actions::make([
                    Action::make('editWidget_'.$widgetId)
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->iconButton()
                        ->size(Size::ExtraSmall)
                        ->color('gray')
                        ->tooltip(__('filament-dynamic-dashboard::dashboard.edit_widget'))
                        ->modalHeading(__('filament-dynamic-dashboard::dashboard.edit_widget'))
                        ->modalWidth(Width::FourExtraLarge)
                        ->fillForm(fn(): array => [
                            'widget_id' => $widgetId,
                            'name' => $widgetModel->name,
                            'type' => $widgetModel->type,
                            'columns' => $widgetModel->columns,
                            'display_title' => $widgetModel->display_title,
                            'dashboard_grid_block_id' => $widgetModel->effective_grid_block?->id,
                            'ordering_after' => $this->getPreviousWidgetId($widgetModel),
                            'settings' => $widgetModel->settings,
                        ])
                        ->schema(fn(Schema $schema): Schema => $this->getWidgetFormSchema($schema))
                        ->action(function (array $data) use ($widgetId): void {
                            abort_unless(static::canEdit(), 403);
                            $widget = DashboardWidget::find($widgetId);
                            $blockId = $data['dashboard_grid_block_id'] ?? $widget->effective_grid_block?->id;
                            $ordering = $this->calculateOrdering($blockId, $data['ordering_after'] ?? null);
                            $widget?->update([
                                'name' => $data['name'],
                                'type' => $data['type'],
                                'columns' => $data['columns'] ?? config('filament-dynamic-dashboard.widget_columns', 3),
                                'display_title' => $data['display_title'] ?? true,
                                'dashboard_grid_block_id' => $blockId,
                                'ordering' => $ordering,
                                'settings' => $data['settings'] ?? [],
                            ]);
                            $this->redirect(static::getUrl(), navigate: true);
                        }),
                    Action::make('deleteWidget_'.$widgetId)
                        ->icon(Heroicon::OutlinedTrash)
                        ->iconButton()
                        ->size(Size::ExtraSmall)
                        ->color('danger')
                        ->tooltip(__('filament-dynamic-dashboard::dashboard.delete_widget'))
                        ->requiresConfirmation()
                        ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete_widget'))
                        ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_widget_confirmation'))
                        ->action(function () use ($widgetId): void {
                            abort_unless(static::canEdit(), 403);
                            $widget = DashboardWidget::find($widgetId);
                            $widget?->delete();
                            $this->redirect(static::getUrl(), navigate: true);
                        }),
                ]),
                // The widget itself (second child - rendered in content area)
                $widgetComponent,
            ])
            ->columnSpan($widgetModel->columns);
    }

    /**
     * Get the number of columns for the dashboard grid layout.
     *
     * @return int|array<string, int> Column count or responsive breakpoint configuration
     */
    public function getColumns(): int|array
    {
        return config('filament-dynamic-dashboard.dashboard_columns');
    }

    /**
     * Build the form schema for creating/editing a widget.
     *
     * The form includes:
     * - Name input with display title toggle
     * - Widget type selector (from discovered DynamicWidget classes)
     * - Column span slider (1-12)
     * - Block position selector (if multiple blocks exist)
     * - Ordering selector (position relative to other widgets)
     * - Dynamic settings section (specific to the selected widget type)
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

                                Slider::make('columns')
                                    ->label(__('filament-dynamic-dashboard::dashboard.widget_size'))
                                    ->range(minValue: 1, maxValue: 12)
                                    ->step(1)
                                    ->default(3)
                                    ->pips(PipsMode::Values, density: 10)
                                    ->pipsValues([1, 3, 6, 9, 12])
                                    ->pipsFormatter(RawJs::make("({1: 'XS', 3: 'S', 6: 'M', 9: 'L', 12: 'XL'})[\$value] || \$value"))
                                    ->required(),

                                Grid::make()->schema([
                                    Select::make('dashboard_grid_block_id')
                                        ->label(__('filament-dynamic-dashboard::dashboard.widget_position'))
                                        ->options(fn(): array => $this->getBlockOptions())
                                        ->selectablePlaceholder(false)
                                        //->visible(fn(): bool => $this->hasMultipleBlocks())
                                        ->live()
                                        ->afterStateUpdated(fn(Set $set) => $set('ordering_after', null)),

                                    Select::make('ordering_after')
                                        ->label(__('filament-dynamic-dashboard::dashboard.widget_order'))
                                        ->options(fn(Get $get): array => $this->getOrderingOptions(
                                            $get('dashboard_grid_block_id'),
                                            $get('widget_id')
                                        ))
                                        //->visible(fn(Get $get): bool =>
                                        //    $this->hasOtherWidgetsInBlock($get('dashboard_grid_block_id'), $get('widget_id')))
                                        ->default(-1)
                                        ->selectablePlaceholder(false),
                                ])->dense(),
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
     * Get block options for the widget form select.
     *
     * @return array<int, string>
     */
    protected function getBlockOptions(): array
    {
        $grid = $this->currentDashboard?->effective_grid;

        if (!$grid) {
            return [];
        }

        $options = [];
        $this->buildBlockOptionsRecursive($grid->rootBlocks, $options, 0);

        return $options;
    }

    /**
     * Build block options recursively with indentation for nesting.
     *
     * @param  Collection<int, DashboardGridBlock>  $blocks
     * @param  array<int, string>  $options
     */
    protected function buildBlockOptionsRecursive(Collection $blocks, array &$options, int $depth): void
    {
        $indent = str_repeat('— ', $depth);

        foreach ($blocks as $block) {
            $options[$block->id] = $indent.$block->name;

            if ($block->children->isNotEmpty()) {
                $this->buildBlockOptionsRecursive($block->children, $options, $depth + 1);
            }
        }
    }

    /**
     * Check if the grid has multiple blocks configured.
     */
    protected function hasMultipleBlocks(?DashboardGrid $grid = null): bool
    {
        if (!$grid) {
            $grid = $this->currentDashboard?->effective_grid;
        }

        if (!$grid) {
            return false;
        }

        return $grid->blocks()->count() > 1;
    }

    /**
     * Get ordering options for a specific block.
     *
     * @return array<int, string>
     */
    protected function getOrderingOptions(?int $blockId, ?int $excludeWidgetId = null): array
    {
        $widgets = DashboardWidget::query()->forDashboardAndBlock(
            $this->currentDashboardId,
            $blockId??$this->getCurrentDashboard()?->effective_grid?->rootBlocks?->first()?->id,
            $excludeWidgetId
        )->get();

        // Start with "First position" option
        $options = [
            0 => __('filament-dynamic-dashboard::dashboard.order_first_position'),
        ];

        // Add "After [widget name]" options
        foreach ($widgets as $widget) {
            $options[$widget->id] = __('filament-dynamic-dashboard::dashboard.order_after', ['name' => $widget->name]);
        }

        // End with "Last position" option
        $options[-1] = __('filament-dynamic-dashboard::dashboard.order_last_position');

        return $options;
    }

    /**
     * Check if block has other widgets (to determine if ordering select should be visible).
     */
    protected function hasOtherWidgetsInBlock(?int $blockId, ?int $excludeWidgetId = null): bool
    {
        return DashboardWidget::query()->forDashboardAndBlock(
            $this->currentDashboardId,
            $blockId??$this->getCurrentDashboard()?->effective_grid?->rootBlocks?->first()?->id,
            $excludeWidgetId
        )->exists();
    }

    /**
     * Calculate the ordering value based on the selected "ordering_after" widget.
     */
    protected function calculateOrdering(?int $blockId, ?int $orderingAfterWidgetId): int
    {
        // First position (0 = "First position" option)
        if ($orderingAfterWidgetId === 0) {
            return 1;
        }

        // Last position (-1 = "Last position" option, or null for backwards compatibility)
        if ($orderingAfterWidgetId === -1 || $orderingAfterWidgetId === null) {
            return DashboardWidget::maxOrderingInBlock($this->currentDashboardId, $blockId) + 1;
        }

        // After specific widget
        $afterWidget = DashboardWidget::find($orderingAfterWidgetId);
        if (!$afterWidget) {
            return 1;
        }

        return $afterWidget->ordering + 1;
    }

    /**
     * Get the widget that comes before the given widget in the same block.
     * Returns 0 (first position) if the widget is first in its block.
     */
    protected function getPreviousWidgetId(DashboardWidget $widget): int
    {
        $previousWidget = DashboardWidget::previousInBlock(
            $widget->dashboard_id,
            $widget->dashboard_grid_block_id,
            $widget->ordering
        );

        // Return 0 for "first position" if no previous widget
        return $previousWidget?->id ?? 0;
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
     * Build the widget grid layout using pre-grouped widgets.
     *
     * @param  array<string, array<Component>>  $widgetsByBlock  Widgets already grouped by block slug
     */
    public function widgetsGrid(array $widgetsByBlock): Component
    {
        $grid = $this->currentDashboard?->effective_grid;

        if (!$grid) {
            // Backward compatible fallback - flatten and use simple grid
            $allWidgets = [];
            foreach ($widgetsByBlock as $widgets) {
                $allWidgets = array_merge($allWidgets, $widgets);
            }
            return Grid::make($this->getColumns())->schema($allWidgets);
        }
        // Reassign widgets with non-existent blocks to the first root block
        $rootBlocks = $grid->rootBlocks;
        $firstBlockSlug = $rootBlocks->first()?->slug;

        if ($firstBlockSlug !== null) {
            $validSlugs = $this->getAllBlockSlugs($rootBlocks);

            foreach ($widgetsByBlock as $slug => $widgets) {
                if (!in_array($slug, $validSlugs, true)) {
                    // Move orphaned widgets to first block
                    if (!isset($widgetsByBlock[$firstBlockSlug])) {
                        $widgetsByBlock[$firstBlockSlug] = [];
                    }
                    $widgetsByBlock[$firstBlockSlug] = array_merge(
                        $widgetsByBlock[$firstBlockSlug],
                        $widgets
                    );
                    unset($widgetsByBlock[$slug]);
                }
            }
        }

        // Build nested block structure recursively
        $blockComponents = $this->buildBlockComponents($rootBlocks, $widgetsByBlock);

        return Grid::make($this->getColumns())->schema($blockComponents)->dense();
    }

    /**
     * Build component tree for blocks recursively.
     *
     * @param  Collection<int, DashboardGridBlock>  $blocks
     * @param  array<int, array<Component>>  $widgetsByBlock
     * @return array<Component>
     */
    protected function buildBlockComponents(Collection $blocks, array $widgetsByBlock): array
    {
        $components = [];

        foreach ($blocks as $block) {
            $blockSlug = $block->slug;
            $children = $block->children;
            $blockWidgets = $widgetsByBlock[$blockSlug] ?? [];

            // Build schema: widgets first, then children
            $blockSchema = [];

            // Add widgets to schema (they appear above/before children)
            if (!empty($blockWidgets)) {
                $blockSchema = array_merge($blockSchema, $blockWidgets);
            }

            // Add child block components to schema (they appear below/after widgets)
            if ($children->isNotEmpty()) {
                $childComponents = $this->buildBlockComponents($children, $widgetsByBlock);
                $blockSchema = array_merge($blockSchema, $childComponents);
            }

            // Create the block's Grid component
            if (!empty($blockSchema)) {
                // Block has content (widgets and/or children)
                $components[] = Grid::make($block->columns)
                    ->schema($blockSchema)
                    ->columnSpan($block->columns);
            } else {
                // Empty block - respect display_empty setting
                $components[] = Grid::make($block->columns)
                    ->schema([])
                    ->columnSpan($block->columns)
                    ->visible($block->display_empty);
            }
        }

        return $components;
    }

    /**
     * Get all block slugs from a grid (including nested blocks).
     *
     * @param  Collection<int, DashboardGridBlock>  $blocks
     * @return array<string>
     */
    protected function getAllBlockSlugs(Collection $blocks): array
    {
        $slugs = [];
        foreach ($blocks as $block) {
            $slugs[] = $block->slug;
            if ($block->children->isNotEmpty()) {
                $slugs = array_merge($slugs, $this->getAllBlockSlugs($block->children));
            }
        }
        return $slugs;
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
     * Handle dashboard list changed event from DashboardManager component.
     */
    #[On('dashboard-list-changed')]
    public function onDashboardListChanged(): void
    {
        // Reload dashboard data to update heading/subheadin
        $this->loadCurrentDashboard();
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
     * Calculates the ordering based on the selected position and persists the widget.
     *
     * @param  array<string, mixed>  $data  Form data containing name, type, columns, settings, etc.
     */
    protected function createWidget(array $data): void
    {
        $blockId = $data['dashboard_grid_block_id'] ?? $this->getCurrentDashboard()?->effective_grid?->rootBlocks?->first()->id;
        $ordering = $this->calculateOrdering($blockId, $data['ordering_after'] ?? null);

        DashboardWidget::create([
            'dashboard_id' => $this->currentDashboardId,
            'dashboard_grid_block_id' => $blockId,
            'name' => $data['name'],
            'type' => $data['type'],
            'columns' => $data['columns'] ?? config('filament-dynamic-dashboard.widget_columns', 3),
            'display_title' => $data['display_title'] ?? true,
            'ordering' => $ordering,
            'settings' => $data['settings'] ?? [],
        ]);

        $this->redirect(static::getUrl(), navigate: true);
    }

    /**
     * Create the dashboard selector dropdown action group.
     *
     * Displays a dropdown with:
     * - All displayable dashboards (with checkmark on current)
     * - "Manage" option to open the dashboard manager slideover (if user can edit)
     */
    protected function getDashboardSelectorActionGroup(): ActionGroup
    {
        $dashboards = $this->getDisplayableDashboards();
        $currentDashboard = $this->getCurrentDashboard();

        $actions = [];
        // Add an action for each dashboard
        foreach ($dashboards as $dashboard) {
            $actions[] = Action::make('selectDashboard_'.$dashboard->id)
                ->label($dashboard->name)
                ->icon($dashboard->id === $this->currentDashboardId ? Heroicon::OutlinedCheck : null)
                ->color($dashboard->id === $this->currentDashboardId ? Color::Green : null)
                ->action(function () use ($dashboard): void {
                    $this->currentDashboardId = $dashboard->id;
                    $this->redirect(static::getUrl(), navigate: true);
                });
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

        return ActionGroup::make([
            ActionGroup::make($actions)->dropdown(false),
            $manageAction,
        ])
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
}
