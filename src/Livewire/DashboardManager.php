<?php

namespace MDDev\DynamicDashboard\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component as LivewireComponent;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Models\DashboardGrid;
use MDDev\DynamicDashboard\Models\DashboardGridBlock;
use MDDev\DynamicDashboard\Pages\DynamicDashboard;

/**
 * Livewire component that provides the slideover UI for managing dashboards and grids
 * (CRUD, reorder, toggle active/locked, duplicate with widgets, widget reorder).
 */
class DashboardManager extends LivewireComponent implements HasActions, HasForms, HasTable
{
    use InteractsWithActions, InteractsWithForms, InteractsWithTable;

    /**
     * @var class-string<DynamicDashboard>|null
     */
    public ?string $pageClass = null;

    public ?int $currentDashboardId = null;

    #[Url]
    public string $activeManagerTab = 'dashboards';

    /**
     * Initialize the component with the dashboard page class and current dashboard ID.
     *
     * @param  class-string<DynamicDashboard>|null  $pageClass  The dashboard page class
     * @param  int|null  $currentDashboardId  The currently active dashboard ID
     */
    public function mount(?string $pageClass = null, ?int $currentDashboardId = null): void
    {
        $this->pageClass = $pageClass;
        $this->currentDashboardId = $currentDashboardId;

        abort_unless(
            $this->pageClass && is_subclass_of($this->pageClass, DynamicDashboard::class) && $this->pageClass::canEdit(),
            403
        );
    }

    /**
     * Switch between "dashboards" and "grids" tabs.
     *
     * Resets the table to refresh data for the new tab.
     */
    public function switchTab(string $tab): void
    {
        $this->activeManagerTab = $tab;
        $this->resetTable();
    }

    /**
     * Get the table configuration based on the active tab.
     *
     * Delegates to dashboardsTable() or gridsTable() depending on activeManagerTab.
     */
    public function table(Table $table): Table
    {
        if ($this->activeManagerTab === 'grids') {
            return $this->gridsTable($table);
        }

        return $this->dashboardsTable($table);
    }

    /**
     * Configure the dashboards table.
     *
     * Displays all dashboards with:
     * - Name column with grid name description
     * - Widget count badge
     * - Active/Locked toggle columns
     * - Edit, Duplicate, Delete row actions
     * - Create header action
     */
    protected function dashboardsTable(Table $table): Table
    {
        return $table
            ->query(DashboardModelHelper::model()::query())
            ->defaultSort('ordering')
            ->reorderable('ordering')
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-dynamic-dashboard::dashboard.name'))
                    ->weight(FontWeight::Bold)
                    ->description(fn (Dashboard $record): ?string => $record->effective_grid?->name)
                    ->searchable(),
                TextColumn::make('widgets_count')
                    ->label(__('filament-dynamic-dashboard::dashboard.widgets'))
                    ->counts('widgets')
                    ->badge(),
                ToggleColumn::make('is_active')
                    ->label(__('filament-dynamic-dashboard::dashboard.active'))
                    ->disabled(fn (Dashboard $record): bool => $this->isCurrentDashboard($record) || $this->isLastActiveDashboard($record))
                    ->tooltip(fn (Dashboard $record): ?string => $this->isCurrentDashboard($record)
                        ? __('filament-dynamic-dashboard::dashboard.cannot_deactivate_current')
                        : ($this->isLastActiveDashboard($record)
                            ? __('filament-dynamic-dashboard::dashboard.cannot_deactivate_last')
                            : null))
                    ->afterStateUpdated(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
                ToggleColumn::make('is_locked')
                    ->label(__('filament-dynamic-dashboard::dashboard.locked'))
                    ->afterStateUpdated(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modal()
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema($this->getDashboardFormSchema())
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
                // Duplicates the dashboard and deep-copies all its widgets
                ReplicateAction::make()
                    ->label(__('filament-dynamic-dashboard::dashboard.duplicate'))
                    ->modal(false)
                    ->excludeAttributes(['ordering'])
                    ->after(function (Dashboard $record, Dashboard $replica): void {
                        $replica->update(['name' => __('filament-dynamic-dashboard::dashboard.copy', ['name' => $replica->name])]);

                        foreach ($record->widgets as $widget) {
                            $replica->widgets()->create($widget->only(['name', 'description', 'type', 'ordering', 'columns', 'is_active', 'settings', 'dashboard_grid_block_id']));
                        }

                        $this->dispatch('dashboard-list-changed');
                    }),
                DeleteAction::make()
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete'))
                    ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_confirmation'))
                    ->visible(fn (Dashboard $record): bool => ! $this->isCurrentDashboard($record) && ! $this->isLastActiveDashboard($record))
                    ->tooltip(fn (Dashboard $record): ?string => $this->isCurrentDashboard($record)
                        ? __('filament-dynamic-dashboard::dashboard.cannot_delete_current')
                        : ($this->isLastActiveDashboard($record)
                            ? __('filament-dynamic-dashboard::dashboard.cannot_delete_last')
                            : null))
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('filament-dynamic-dashboard::dashboard.add_new'))
                    ->modal()
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.create'))
                    ->model(DashboardModelHelper::model())
                    ->schema($this->getDashboardFormSchema())
                    ->createAnother(false)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->mutateDataUsing(function (array $data): array {
                        $data['page'] = $this->pageClass;

                        return $data;
                    })
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
            ])
            ->paginated(false);
    }

    /**
     * Configure the grids table.
     *
     * Displays all grid layouts with:
     * - Name column
     * - Block count badge
     * - Default toggle column
     * - Preview, Edit, Duplicate, Delete row actions
     * - Create header action
     */
    protected function gridsTable(Table $table): Table
    {
        return $table
            ->query(DashboardGrid::query())
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-dynamic-dashboard::dashboard.grid_name'))
                    ->weight(FontWeight::Bold)
                    ->searchable(),
                TextColumn::make('blocks_count')
                    ->label(__('filament-dynamic-dashboard::dashboard.blocks_count'))
                    ->counts('blocks')
                    ->badge(),
                ToggleColumn::make('is_default')
                    ->label(__('filament-dynamic-dashboard::dashboard.default'))
                    ->disabled(fn (DashboardGrid $record): bool => $record->is_default)
                    ->afterStateUpdated(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('filament-dynamic-dashboard::dashboard.preview_grid'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading(fn (DashboardGrid $record): string => $record->name)
                    ->modalContent(fn (DashboardGrid $record): View => view(
                        'filament-dynamic-dashboard::grid-preview',
                        ['grid' => $record->load('rootBlocks.children.children')]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament-dynamic-dashboard::dashboard.close')),
                EditAction::make()
                    ->modal()
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.edit_grid'))
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema($this->getGridFormSchema())
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
                ReplicateAction::make()
                    ->label(__('filament-dynamic-dashboard::dashboard.duplicate'))
                    ->modal(false)
                    ->excludeAttributes(['slug', 'is_default'])
                    ->after(function (DashboardGrid $record, DashboardGrid $replica): void {
                        $replica->update(['name' => __('filament-dynamic-dashboard::dashboard.copy', ['name' => $replica->name])]);

                        // Deep copy blocks recursively
                        $this->duplicateBlocks($record->rootBlocks, $replica->id, null);

                        $this->dispatch('dashboard-list-changed');
                    }),
                DeleteAction::make()
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete_grid'))
                    ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_grid_confirmation'))
                    ->visible(fn (DashboardGrid $record): bool => ! $record->is_default && ! $record->isInUse())
                    ->tooltip(fn (DashboardGrid $record): ?string => $record->is_default
                        ? __('filament-dynamic-dashboard::dashboard.cannot_delete_default_grid')
                        : ($record->isInUse()
                            ? __('filament-dynamic-dashboard::dashboard.cannot_delete_grid_in_use')
                            : null))
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('filament-dynamic-dashboard::dashboard.add_new_grid'))
                    ->modal()
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.add_new_grid'))
                    ->model(DashboardGrid::class)
                    ->schema($this->getGridFormSchema())
                    ->createAnother(false)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
            ])
            ->paginated(false);
    }

    /**
     * Recursively duplicate blocks from source to target grid.
     *
     * Creates deep copies of all blocks maintaining the parent-child hierarchy.
     *
     * @param  Collection<int, DashboardGridBlock>  $blocks  Source blocks to duplicate
     * @param  int  $gridId  Target grid ID
     * @param  int|null  $parentId  Parent block ID for nested blocks (null for root level)
     */
    protected function duplicateBlocks(Collection $blocks, int $gridId, ?int $parentId): void
    {
        foreach ($blocks as $block) {
            $newBlock = DashboardGridBlock::create([
                'dashboard_grid_id' => $gridId,
                'parent_id' => $parentId,
                'name' => $block->name,
                'columns' => $block->columns,
                'display_empty' => $block->display_empty,
                'ordering' => $block->ordering,
            ]);

            if ($block->children->isNotEmpty()) {
                $this->duplicateBlocks($block->children, $gridId, $newBlock->id);
            }
        }
    }

    /**
     * Check if the dashboard is the currently selected one.
     */
    protected function isCurrentDashboard(Dashboard $record): bool
    {
        return $this->currentDashboardId === $record->id;
    }

    /**
     * Check if this is the last active dashboard for the current page.
     */
    protected function isLastActiveDashboard(Dashboard $record): bool
    {
        return $record->is_active && $this->getAvailableDashboards()->count() <= 1;
    }

    /**
     * Get all available dashboards for selection.
     */
    public function getAvailableDashboards(): Builder
    {
        return DashboardModelHelper::model()::query()->available($this->pageClass);
    }

    /**
     * Get the form schema for creating/editing a dashboard.
     *
     * Includes tabs for:
     * - General: name, description, grid selector, roles (if Spatie permissions enabled)
     * - Visible Filters: toggle visibility of each filter (if page has filters)
     * - Default Values: set default filter values (if page has filters)
     *
     * @return array<Component>
     */
    protected function getDashboardFormSchema(): array
    {
        $tabs = [
            Tab::make(__('filament-dynamic-dashboard::dashboard.tab_general'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('filament-dynamic-dashboard::dashboard.name'))
                        ->required()
                        ->maxLength(255),
                    RichEditor::make('description')
                        ->label(__('filament-dynamic-dashboard::dashboard.description'))
                        ->toolbarButtons([
                            'bold',
                            'italic',
                            'underline',
                            'link',
                            'bulletList',
                        ]),
                    // Grid selector - only visible when multiple grids exist
                    Select::make('dashboard_grid_id')
                        ->label(__('filament-dynamic-dashboard::dashboard.grid'))
                        ->relationship('grid', 'name')
                        ->selectablePlaceholder(false)
                        ->default(fn (): ?int => DashboardGrid::default()->first()?->id)
                        ->visible(fn (): bool => DashboardGrid::query()->count() > 1),
                    // Spatie roles selector — only rendered when the permission integration is enabled
                    ...(config('filament-dynamic-dashboard.use_spatie_permissions', false) ? [
                        Select::make('roles')
                            ->label(__('filament-dynamic-dashboard::dashboard.roles'))
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ] : []),
                ]),
        ];

        if ($this->pageHasDynamicFilters()) {
            $tabs[] = Tab::make(__('filament-dynamic-dashboard::dashboard.tab_visible_filters'))
                ->schema($this->getVisibleFiltersSchema());

            $tabs[] = Tab::make(__('filament-dynamic-dashboard::dashboard.tab_default_values'))
                ->schema($this->getDefaultValuesSchema());
        }

        return [
            Tabs::make('dashboard-settings')
                ->tabs($tabs)
                ->columnSpanFull(),
        ];
    }

    /**
     * Get the form schema for creating/editing a grid layout.
     *
     * Includes:
     * - Grid name input
     * - Nested repeater for root blocks (up to 3 levels deep)
     *
     * @return array<Component>
     */
    protected function getGridFormSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('filament-dynamic-dashboard::dashboard.grid_name'))
                ->required()
                ->maxLength(255),

            Hidden::make('columns')->default(12),

            Repeater::make('rootBlocks')
                ->hiddenLabel()
                ->relationship()
                ->schema($this->getBlockFormSchema(0))
                ->orderColumn('ordering')
                ->reorderable()
                ->collapsible()
                ->collapseAllAction(null)
                ->expandAllAction(null)
                ->compact()
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->defaultItems(1)
                ->required()
                ->addActionLabel(__('filament-dynamic-dashboard::dashboard.add_block'))
                ->addAction(fn ($action) => $action->color('info'))
                ->deleteAction(
                    fn ($action) => $action->hidden(fn (array $arguments, Repeater $component): bool => $this->blockHasWidgets($arguments, $component))
                ),
        ];
    }

    /**
     * Get the form schema for a block at a given depth.
     *
     * @return array<Component>
     */
    protected function getBlockFormSchema(int $depth): array
    {
        $columnOptions = [
            1 => __('filament-dynamic-dashboard::dashboard.column_1'),
            2 => __('filament-dynamic-dashboard::dashboard.column_2'),
            3 => __('filament-dynamic-dashboard::dashboard.column_3'),
            4 => __('filament-dynamic-dashboard::dashboard.column_4'),
            5 => __('filament-dynamic-dashboard::dashboard.column_5'),
            6 => __('filament-dynamic-dashboard::dashboard.column_6'),
            7 => __('filament-dynamic-dashboard::dashboard.column_7'),
            8 => __('filament-dynamic-dashboard::dashboard.column_8'),
            9 => __('filament-dynamic-dashboard::dashboard.column_9'),
            10 => __('filament-dynamic-dashboard::dashboard.column_10'),
            11 => __('filament-dynamic-dashboard::dashboard.column_11'),
            12 => __('filament-dynamic-dashboard::dashboard.column_12'),
        ];

        $schema = [
            Flex::make([
                TextInput::make('name')
                    ->hiddenLabel()
                    ->placeholder(__('filament-dynamic-dashboard::dashboard.block_name'))
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 500)
                    ->grow(),
                Select::make('columns')
                    ->hiddenLabel()
                    ->selectablePlaceholder(false)
                    ->options($columnOptions)
                    ->default(6)
                    ->required()
                    ->grow(false),
                Toggle::make('display_empty')
                    ->hiddenLabel()
                    ->default(false)
                    ->hintIcon(Heroicon::InformationCircle)
                    ->hintIconTooltip(__('filament-dynamic-dashboard::dashboard.display_empty'))
                    ->grow(false),
            ])->from('md'),
        ];

        // Allow child blocks up to depth 2 (3 levels total: 0, 1, 2)
        if ($depth < 2) {
            // Level 1 (depth 0 children) -> green (success), Level 2 (depth 1 children) -> red (danger)
            $childColor = $depth === 0 ? 'success' : 'danger';

            $schema[] = Repeater::make('children')
                ->hiddenLabel()
                ->relationship()
                ->schema($this->getBlockFormSchema($depth + 1))
                ->orderColumn('ordering')
                ->reorderable()
                ->collapsible()
                ->collapseAllAction(null)
                ->expandAllAction(null)
                ->compact()
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->defaultItems(0)
                ->addActionLabel(fn (Get $get): string => empty($get('name'))?
                   ''
                    : __('filament-dynamic-dashboard::dashboard.add_child_block', ['parent_block_name' => $get('name')]))
                ->addAction(fn ($action) => $action->color($childColor))
                ->addable(fn (Get $get): bool => ! empty($get('name')))
                ->deleteAction(
                    fn ($action) => $action->hidden(fn (array $arguments, Repeater $component): bool => $this->blockHasWidgets($arguments, $component))
                );
        }

        return $schema;
    }

    /**
     * Check if a block in a repeater has widgets linked to it.
     */
    protected function blockHasWidgets(array $arguments, Repeater $component): bool
    {
        $items = $component->getState();
        $uuid = $arguments['item'];

        if (! isset($items[$uuid]['id'])) {
            return false;
        }

        $block = DashboardGridBlock::find($items[$uuid]['id']);

        return $block?->hasWidgets() ?? false;
    }

    /**
     * Build the schema for the "Visible Filters" tab.
     *
     * @return array<Component>
     */
    protected function getVisibleFiltersSchema(): array
    {
        $filters = $this->getPageDynamicFilters();

        if (empty($filters)) {
            return [
                Text::make(__('filament-dynamic-dashboard::dashboard.no_filters_available')),
            ];
        }

        $toggles = [];
        /**
         * @var Field[] $filters
         */
        foreach ($filters as $filter) {
            $filterName = $filter->getName();
            $toggles[] = Toggle::make('display_filters.'.$filterName)
                ->label($filter->getLabel())
                ->formatStateUsing(function (?Dashboard $record) use ($filterName) {
                    // for filters visible by default if not set
                    if (! $record) {
                        return true;
                    }

                    return $record->getDisplayFilters($filterName);
                })
                ->default(true);
        }

        return [
            Grid::make()->schema($toggles),
        ];
    }

    /**
     * Get the dynamic filters from the page class.
     *
     * @return array<Component>
     */
    protected function getPageDynamicFilters(): array
    {
        if (! $this->pageHasDynamicFilters()) {
            return [];
        }

        return $this->pageClass::getDashboardFilters();
    }

    /**
     * Check if the page class has dynamic filters defined.
     */
    protected function pageHasDynamicFilters(): bool
    {
        if (! $this->pageClass || ! class_exists($this->pageClass)) {
            return false;
        }

        if (! is_subclass_of($this->pageClass, DynamicDashboard::class)) {
            return false;
        }

        return $this->pageClass::hasFilters();
    }

    /**
     * Build the schema for the "Default Values" tab.
     *
     * @return array<Component>
     */
    protected function getDefaultValuesSchema(): array
    {
        if (! $this->pageHasDynamicFilters()) {
            return [
                Text::make(__('filament-dynamic-dashboard::dashboard.no_filters_available')),
            ];
        }

        // Get original filters and custom default components
        $originalFilters = $this->pageClass::getDashboardFilters();
        $customComponents = $this->pageClass::getDefaultFilterSchema();

        $fields = [];
        foreach ($originalFilters as $filter) {
            $filterName = $filter->getName();

            // Use custom component if defined, otherwise use original
            if (isset($customComponents[$filterName])) {
                $fields[] = $customComponents[$filterName];
            } else {
                $fields[] = $filter;
            }
        }

        // Use Group with statePath to properly nest data under the 'filters' key
        return [
            Group::make()
                ->statePath('filters')
                ->schema([
                    Grid::make()->schema($fields),
                ]),
        ];
    }

    /**
     * Render the dashboard manager component.
     */
    public function render(): View
    {
        return view('filament-dynamic-dashboard::livewire.dashboard-manager');
    }
}
