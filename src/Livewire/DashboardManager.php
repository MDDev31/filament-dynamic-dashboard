<?php

namespace MDDev\DynamicDashboard\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component as LivewireComponent;
use MDDev\DynamicDashboard\DynamicDashboardHelper;
use MDDev\DynamicDashboard\Models\Contracts\DynamicDashboardModel;
use MDDev\DynamicDashboard\Pages\DynamicDashboard;

/**
 * Livewire component that provides the slideover UI for managing dashboards
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

    public function mount(?string $pageClass = null, ?int $currentDashboardId = null): void
    {
        $this->pageClass = $pageClass;
        $this->currentDashboardId = $currentDashboardId;

        abort_unless(
            $this->pageClass && is_subclass_of($this->pageClass, DynamicDashboard::class) && $this->pageClass::canEdit(),
            403
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(DynamicDashboardHelper::DashboardModel()::query())
            ->defaultSort('ordering')
            ->reorderable('ordering')
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-dynamic-dashboard::dashboard.name'))
                    ->weight(FontWeight::Bold)
                    ->description(fn (DynamicDashboardModel $record): ?string => $record->description ? strip_tags($record->description) : null)
                    ->searchable(),
                TextColumn::make('widgets_count')
                    ->label(__('filament-dynamic-dashboard::dashboard.widgets'))
                    ->counts('widgets')
                    ->badge(),
                ToggleColumn::make('is_active')
                    ->label(__('filament-dynamic-dashboard::dashboard.active'))
                    ->disabled(fn (DynamicDashboardModel $record): bool => $this->isCurrentDashboard($record) || $this->isLastActiveDashboard($record))
                    ->tooltip(fn (DynamicDashboardModel $record): ?string => $this->isCurrentDashboard($record)
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
                    ->schema($this->getFormSchema())
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
                // Duplicates the dashboard and deep-copies all its widgets
                ReplicateAction::make()
                    ->label(__('filament-dynamic-dashboard::dashboard.duplicate'))
                    ->modal(false)
                    ->excludeAttributes(['ordering'])
                    ->after(function (DynamicDashboardModel $record, DynamicDashboardModel $replica): void {
                        $replica->update(['name' => __('filament-dynamic-dashboard::dashboard.copy', ['name' => $replica->name])]);

                        foreach ($record->widgets as $widget) {
                            $replica->widgets()->create($widget->only(['name', 'description', 'type', 'ordering', 'columns', 'is_active', 'settings']));
                        }

                        $this->dispatch('dashboard-list-changed');
                    }),
                DeleteAction::make()
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.delete'))
                    ->modalDescription(__('filament-dynamic-dashboard::dashboard.delete_confirmation'))
                    ->visible(fn (DynamicDashboardModel $record): bool => ! $this->isCurrentDashboard($record) && ! $this->isLastActiveDashboard($record))
                    ->tooltip(fn (DynamicDashboardModel $record): ?string => $this->isCurrentDashboard($record)
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
                    ->model(DynamicDashboardHelper::DashboardModel())
                    ->schema($this->getFormSchema())
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
     * Check if the dashboard is the currently selected one.
     */
    protected function isCurrentDashboard(DynamicDashboardModel $record): bool
    {
        return $this->currentDashboardId === $record->getId();
    }

    /**
     * Check if this is the last active dashboard for the current page.
     */
    protected function isLastActiveDashboard(DynamicDashboardModel $record): bool
    {
        return $record->is_active && $this->getAvailableDashboards()->count() <= 1;
    }

    /**
     * Get all available dashboards for selection.
     */
    public function getAvailableDashboards(): Builder
    {
        return DynamicDashboardHelper::DashboardModel()::available($this->pageClass);
    }

    /**
     * @return array<Component>
     */
    protected function getFormSchema(): array
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

            Tab::make(__('filament-dynamic-dashboard::dashboard.tab_widgets'))
                ->schema([
                    Repeater::make('widgets')
                        ->hiddenLabel()
                        ->relationship()
                        ->table([
                            TableColumn::make(__('filament-dynamic-dashboard::dashboard.widget_name')),
                        ])
                        ->schema([
                            TextInput::make('name')
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->compact()
                        ->orderColumn('ordering')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable()
                        ->defaultItems(0),
                ])
                ->visible(fn (?DynamicDashboardModel $record): bool => $record !== null),
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
                ->formatStateUsing(function (?DynamicDashboardModel $record) use ($filterName) {
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

    public function render(): View
    {
        return view('filament-dynamic-dashboard::livewire.dashboard-manager');
    }
}
