<?php

namespace MDDev\DynamicDashboard\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
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
use Filament\Support\RawJs;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;
use MDDev\DynamicDashboard\DynamicDashboardHelper;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardModel;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardWidgetModel;

/**
 * Abstract Filament page that renders a user-configurable dashboard.
 *
 * Extend this class and register it as a Filament page to get a dashboard
 * with switchable layouts, per-dashboard session filters, widget CRUD,
 * and optional Spatie role-based visibility.
 *
 * Key behaviours:
 * - Each dashboard keeps its own filter session (keyed by page + dashboard ID).
 * - `currentDashboard` is nulled on dehydrate to avoid serialisation of a
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
    public ?DynamicDashboardModel $currentDashboard = null;

    /**
     * Widget ID being edited/deleted (for action context).
     */
    public ?int $actionWidgetId = null;

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
            $this->currentDashboardId = $displayable->first()?->getId();
        }

        // If dashboards exist but none are displayable, deny access
        if (! $this->currentDashboardId && $this->getAvailableDashboards()->exists()) {
            abort(403);
        }

        $this->loadCurrentDashboard();
    }

    /**
     * Get all available dashboards for selection.
     */
    public function getAvailableDashboards(): Builder
    {
        return DynamicDashboardHelper::DashboardModel()::available(static::class);
    }

    /**
     * Get available dashboards filtered by canDisplay authorization.
     *
     * @return \Illuminate\Support\Collection<int, DynamicDashboardModel>
     */
    protected function getDisplayableDashboards(): \Illuminate\Support\Collection
    {
        return $this->getAvailableDashboards()->get()->filter(
            fn (DynamicDashboardModel $dashboard) => static::canDisplay($dashboard)
        );
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
     * Clear the cached dashboard before Livewire serializes the state.
     * This prevents 404 errors when a dashboard is deleted.
     */
    public function dehydrate(): void
    {
        $this->currentDashboard = null;
    }

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
     * Get resolved default filters from the current dashboard.
     *
     * @return array<string, mixed>|null
     */
    protected function getResolvedDefaultFilters(): ?array
    {
        if (! $this->currentDashboard) {
            return null;
        }

        $defaults = $this->currentDashboard->getFilters() ?? [];

        if (empty($defaults)) {
            return null;
        }

        return static::resolveFilterDefaults($defaults);
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
     * Reset filters to defaults.
     */
    public function resetFilters(): void
    {
        $this->applyDefaultFilters();
    }

    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
    }

    public function getWidgetsContentComponent(): Component
    {
        $widgetModels = $this->getCurrentDashboardWidgets() ?? collect();

        $wrappedWidgets = $widgetModels->map(function (DynamicDashboardWidgetModel $widgetModel) {
            // check if the widget can be displayed
            if (! $this->isWidgetAvailableForDashboard($widgetModel->getType())) {
                return null;
            }

            $widgetConfig = $widgetModel->getWidget();
            if (! $widgetConfig) {
                return null;
            }

            return $this->wrapWidget($widgetModel, $widgetConfig);
        })->filter()->all();

        return Grid::make($this->getColumns())->schema($wrappedWidgets)->dense();
    }

    /**
     * Wrap a widget with a header containing name and action buttons.
     */
    protected function wrapWidget(DynamicDashboardWidgetModel $widgetModel, mixed $widgetConfig): Component
    {
        $widgetId = $widgetModel->getId();
        $name = $widgetModel->getName();
        $displayTitle = $widgetModel->getDisplayTitle();

        return ViewComponent::make('filament-dynamic-dashboard::schemas.widget-wrapper')
            ->viewData([
                'name' => $name,
                'displayTitle' => $displayTitle,
                'canEdit' => static::canEdit(),
                'isLocked' => $this->currentDashboard?->isLocked() ?? false,
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
                        ->fillForm(fn (): array => [
                            'name' => $widgetModel->getName(),
                            'type' => $widgetModel->getType(),
                            'columns' => $widgetModel->getColumns(),
                            'display_title' => $widgetModel->getDisplayTitle(),
                            'settings' => $widgetModel->getSettings(),
                        ])
                        ->schema(fn (Schema $schema): Schema => $this->getAddWidgetFormSchema($schema))
                        ->action(function (array $data) use ($widgetId): void {
                            abort_unless(static::canEdit(), 403);
                            $widget = DynamicDashboardHelper::WidgetModel()::find($widgetId);
                            $widget?->update([
                                'name' => $data['name'],
                                'type' => $data['type'],
                                'columns' => $data['columns'] ?? config('filament-dynamic-dashboard.widget_columns', 3),
                                'display_title' => $data['display_title'] ?? true,
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
                            $widget = DynamicDashboardHelper::WidgetModel()::find($widgetId);
                            $widget?->delete();
                            $this->redirect(static::getUrl(), navigate: true);
                        }),
                ]),
                // The widget itself (remaining children - rendered in content area)
                ...$this->getWidgetsSchemaComponents([$widgetConfig]),
            ])
            ->columnSpan($widgetModel->getColumns());
    }

    /**
     * Get widgets for the current dashboard.
     *
     * @return null|Collection<DynamicDashboardWidgetModel>
     */
    public function getCurrentDashboardWidgets(): ?Collection
    {

        if (! $this->currentDashboard) {
            return null;
        }

        return DynamicDashboardHelper::WidgetModel()::availableFor($this->currentDashboard)->get();
    }

    public function getColumns(): int|array
    {
        return config('filament-dynamic-dashboard.dashboard_columns');
    }

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

    public function getHeading(): string|Htmlable|null
    {
        return $this->currentDashboard?->getName() ?? parent::getHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return Html::make($this->currentDashboard?->getDescription() ?? parent::getSubheading());
    }

    /**
     * Open edit widget modal.
     */
    public function openEditWidgetModal(int $widgetId): void
    {
        $this->actionWidgetId = $widgetId;
        $this->mountAction('editWidget');
    }

    /**
     * Open delete widget confirmation.
     */
    public function openDeleteWidgetModal(int $widgetId): void
    {
        $this->actionWidgetId = $widgetId;
        $this->mountAction('deleteWidget');
    }

    public function editWidgetAction(): Action
    {
        return Action::make('editWidget')
            ->icon(Heroicon::PencilSquare)
            ->iconButton()
            ->size('xs')
            ->color('gray')
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.edit_widget'))
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(function (): array {
                $widget = DynamicDashboardHelper::WidgetModel()::find($this->actionWidgetId);

                return [
                    'name' => $widget?->getName(),
                    'type' => $widget?->getType(),
                    'columns' => $widget?->getColumns(),
                    'settings' => $widget?->getSettings(),
                ];
            })
            ->schema(fn (Schema $schema): Schema => $this->getAddWidgetFormSchema($schema))
            ->action(function (array $data): void {
                $widget = DynamicDashboardHelper::WidgetModel()::find($this->actionWidgetId);
                $widget?->update([
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'columns' => $data['columns'] ?? config('filament-dynamic-dashboard.widget_columns', 3),
                    'settings' => $data['settings'] ?? [],
                ]);
                $this->redirect(static::getUrl(), navigate: true);
            });
    }

    public function deleteWidgetAction(): Action
    {
        return Action::make('deleteWidget')
            ->icon(Heroicon::Trash)
            ->iconButton()
            ->size('xs')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete_widget'))
            ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_widget_confirmation'))
            ->action(function (): void {
                $widget = DynamicDashboardHelper::WidgetModel()::find($this->actionWidgetId);
                $widget?->delete();
                $this->redirect(static::getUrl(), navigate: true);
            });
    }

    /**
     * Handle dashboard list changed event from DashboardManager component.
     */
    #[On('dashboard-list-changed')]
    public function onDashboardListChanged(): void
    {
        // Reload dashboard data to update heading/subheading
        $this->loadCurrentDashboard();
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->getAddWidgetAction(),
            $this->getDashboardSelectorActionGroup(),
        ];
    }

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
            ->schema(fn (Schema $schema): Schema => $this->getAddWidgetFormSchema($schema))
            ->visible(fn (): bool => $this->currentDashboardId && static::canEdit() && ! $this->currentDashboard?->isLocked())
            ->action(function (array $data): void {
                $this->createWidget($data);
            });
    }

    protected function getAddWidgetFormSchema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        // Column 1: Widget Information
                        Grid::make()
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('filament-dynamic-dashboard::dashboard.widget_name'))
                                    ->required()
                                    ->maxLength(255),

                                Select::make('type')
                                    ->label(__('filament-dynamic-dashboard::dashboard.widget_type'))
                                    ->options(fn (): array => $this->getAvailableWidgetOptions())
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

                                Toggle::make('display_title')
                                    ->label(__('filament-dynamic-dashboard::dashboard.display_title'))
                                    ->default(true),
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
     * @return array<string, string>
     */
    protected function getAvailableWidgetOptions(): array
    {
        $widgets = [];

        foreach ($this->discoverDynamicWidgets() as $widgetClass) {
            $widgets[$widgetClass] = $widgetClass::getWidgetLabel();
        }

        return $widgets;
    }

    /**
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

        return $type::getSettingsFormSchema();
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
     * @param  array<string, mixed>  $data
     */
    protected function createWidget(array $data): void
    {
        DynamicDashboardHelper::WidgetModel()::create([
            'dashboard_id' => $this->currentDashboardId,
            'name' => $data['name'],
            'type' => $data['type'],
            'columns' => $data['columns'] ?? config('filament-dynamic-dashboard.widget_columns', 3),
            'display_title' => $data['display_title'] ?? true,
            'settings' => $data['settings'] ?? [],
        ]);

        $this->redirect(static::getUrl(), navigate: true);
    }

    protected function getDashboardSelectorActionGroup(): ActionGroup
    {
        $dashboards = $this->getDisplayableDashboards();
        $currentDashboard = $this->getCurrentDashboard();

        $actions = [];
        // Add an action for each dashboard
        foreach ($dashboards as $dashboard) {
            $actions[] = Action::make('selectDashboard_'.$dashboard->getId())
                ->label($dashboard->getName())
                ->icon($dashboard->getId() === $this->currentDashboardId ? Heroicon::OutlinedCheck : null)
                ->color($dashboard->getId() === $this->currentDashboardId ? Color::Green : null)
                ->action(function () use ($dashboard): void {
                    $this->currentDashboardId = $dashboard->getId();
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
            ->modalContent(fn (): View => view('filament-dynamic-dashboard::livewire.dashboard-manager-modal', [
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
            ->label($currentDashboard?->getName() ?? __('filament-dynamic-dashboard::dashboard.select_dashboard'))
            ->icon(Heroicon::OutlinedViewColumns)
            ->color('gray')
            ->dropdownWidth(Width::ExtraSmall)
            ->button()
            ->dropdownPlacement('bottom-end');
    }

    /**
     * Get the currently active dashboard.
     */
    public function getCurrentDashboard(): ?DynamicDashboardModel
    {
        if ($this->currentDashboard === null) {
            $this->loadCurrentDashboard();
        }

        return $this->currentDashboard;
    }

    /**
     * Check if a widget is available for the current dashboard page.
     *
     * @param  class-string<DynamicWidget>  $widgetClass
     */
    protected function isWidgetAvailableForDashboard(string $widgetClass): bool
    {
        if (! class_exists($widgetClass) || ! $widgetClass::canView()) {
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
     * Determine if the current user can view this dashboard.
     *
     * Editors always have access. Otherwise, Spatie roles are checked when
     * the model supports them, falling back to the page-level `canAccess()`.
     */
    public static function canDisplay(DynamicDashboardModel $dashboard): bool
    {
        if (static::canEdit()) {
            return true;
        }

        if (method_exists($dashboard, 'roles') && $dashboard->roles->isNotEmpty()) {
            $user = auth()->user();

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
}
