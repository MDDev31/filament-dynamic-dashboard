<?php

namespace MDDev\DynamicDashboard\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component as LivewireComponent;
use MDDev\DynamicDashboard\DashboardModelHelper;
use MDDev\DynamicDashboard\Models\Dashboard;
use MDDev\DynamicDashboard\Pages\DynamicDashboard;
use MDDev\DynamicDashboard\Templates\TemplateRegistry;

/**
 * Livewire component that provides the slideover UI for managing dashboards
 * (CRUD, reorder, toggle active/locked, duplicate with widgets).
 */
class DashboardManager extends LivewireComponent implements HasActions, HasForms, HasTable
{
    use InteractsWithActions, InteractsWithForms, InteractsWithTable;

    /**
     * @var class-string<DynamicDashboard>|null
     */
    public ?string $pageClass = null;

    public ?int $currentDashboardId = null;

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
     * Configure the dashboards table.
     *
     * Displays all dashboards with:
     * - Name column with template name description
     * - Widget count badge
     * - Active/Locked toggle columns
     * - Edit, Duplicate, Delete row actions
     * - Create header action
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(DashboardModelHelper::model()::query())
            ->defaultSort('ordering')
            ->reorderable('ordering')
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-dynamic-dashboard::dashboard.name'))
                    ->weight(FontWeight::Bold)
                    ->description(fn (Dashboard $record): ?string => $record->template?->name())
                    ->icon(fn (Dashboard $record) => $record->is_personal ? Heroicon::OutlinedUser : null)
                    ->iconPosition(IconPosition::After)
                    ->tooltip(fn (Dashboard $record): ?string => $record->is_personal
                        ? __('filament-dynamic-dashboard::dashboard.personal')
                        : null)
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
                    ->modalHeading(__('filament-dynamic-dashboard::dashboard.edit'))
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema($this->getDashboardFormSchema())
                    ->after(function (): void {
                        $this->dispatch('dashboard-list-changed');
                    }),
                ReplicateAction::make()
                    ->label(__('filament-dynamic-dashboard::dashboard.duplicate'))
                    ->modal(false)
                    // `widgets_count` is a virtual attribute
                    ->excludeAttributes(['ordering', 'widgets_count', 'created_by'])
                    ->after(function (Dashboard $record, Dashboard $replica): void {
                        $replica->update(['name' => __('filament-dynamic-dashboard::dashboard.copy', ['name' => $replica->name])]);

                        $record->loadMissing('widgets');
                        foreach ($record->widgets as $widget) {
                            $replica->widgets()->create($widget->only([
                                'name', 'description', 'type', 'section_slug',
                                'x', 'y', 'w', 'h',
                                'is_active', 'display_title', 'settings',
                            ]));
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
     * - General: name, description, template_key selector, roles (if Spatie permissions enabled)
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
                    Grid::make()
                        ->schema([
                            Select::make('template_key')
                                ->label(__('filament-dynamic-dashboard::dashboard.template'))
                                ->options(fn (): array => $this->getTemplateOptions())
                                ->default(config('filament-dynamic-dashboard.default_template', 'flat-12'))
                                ->selectablePlaceholder(false)
                                ->helperText(fn (?string $state): ?string => $state
                                    ? app(TemplateRegistry::class)->find($state)?->description()
                                    : null)
                                ->live()
                                ->required(),
                            Html::make(fn (Get $get): string => $this->renderTemplatePreview($get('template_key'))),
                        ]),
                    Toggle::make('is_personal')
                        ->label(__('filament-dynamic-dashboard::dashboard.personal'))
                        ->helperText(__('filament-dynamic-dashboard::dashboard.personal_help'))
                        ->default(fn (): bool => (bool) config('filament-dynamic-dashboard.default_personal', false)),
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
     * Build the `[key => localized name]` map for the template selector.
     *
     * @return array<string, string>
     */
    protected function getTemplateOptions(): array
    {
        $defaultKey = config('filament-dynamic-dashboard.default_template', 'flat-12');

        $options = collect(app(TemplateRegistry::class)->enabled())
            ->mapWithKeys(fn ($template) => [$template->key => $template->name()])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE);

        if ($options->has($defaultKey)) {
            $options = collect([$defaultKey => $options->get($defaultKey)])->union($options);
        }

        return $options->all();
    }

    /**
     * HTML for the side-by-side template preview. Resolves the current `template_key`
     * (falling back to the configured default), pulls the paired SVG from the
     * registry, and wraps it so it scales to the form column.
     *
     * Returns an empty string when no SVG was shipped for the resolved key —
     * the right column then just collapses.
     */
    protected function renderTemplatePreview(?string $key): string
    {
        $resolved = $key ?? config('filament-dynamic-dashboard.default_template', 'flat-12');
        $svg = app(TemplateRegistry::class)->previewSvg($resolved);

        if ($svg === null) {
            return '';
        }

        return '<div style="display:flex;align-items:center;justify-content:center;width:100%;padding:0.25rem 0;">'
            .'<div style="width:100%;max-width:260px;">'.$svg.'</div>'
            .'</div>';
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
