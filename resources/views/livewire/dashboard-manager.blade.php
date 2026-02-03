<div>
    {{-- Tab navigation using Filament's built-in component --}}
    <x-filament::tabs label="Manager tabs" class="mb-4">
        <x-filament::tabs.item
            :active="$activeManagerTab === 'dashboards'"
            wire:click="switchTab('dashboards')"
        >
            {{ __('filament-dynamic-dashboard::dashboard.tab_manage_dashboards') }}
        </x-filament::tabs.item>
        <x-filament::tabs.item
            :active="$activeManagerTab === 'grids'"
            wire:click="switchTab('grids')"
        >
            {{ __('filament-dynamic-dashboard::dashboard.tab_manage_grids') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Table content based on active tab --}}
    {{ $this->table }}

    <x-filament-actions::modals />
</div>
