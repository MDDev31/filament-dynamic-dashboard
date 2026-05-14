@php
    $canEdit = $canEdit ?? false;
    $showLoader = $showLoader ?? true;
@endphp

<div
    class="dashboard-widget"
    wire:key="widget-{{ $widget['id'] }}-wrapper"
    @if ($showLoader)
        x-data="{ loading: false }"
        x-init="
            $nextTick(() => {
                const wireEl = $el.querySelector('[wire\\:id]');
                if (! wireEl) return;
                const wireId = wireEl.getAttribute('wire:id');
                Livewire.hook('commit', ({ component, succeed, fail }) => {
                    if (component.id !== wireId) return;
                    loading = true;
                    succeed(() => { loading = false; });
                    fail(() => { loading = false; });
                });
            })
        "
    @endif
>
    @if ($widget['displayTitle'] && ! empty($widget['name']))
        <div class="dashboard-widget-title">
            <span class="dashboard-widget-title-badge">{{ $widget['name'] }}</span>
        </div>
    @endif

    @if ($canEdit)
        <div class="dashboard-widget-actions">
            <span
                role="button"
                tabindex="0"
                class="dashboard-widget-action dd-drag-handle"
                title="{{ __('filament-dynamic-dashboard::dashboard.drag') }}"
            >
                <x-filament::icon icon="heroicon-o-arrows-pointing-out" />
            </span>

            <button
                type="button"
                class="dashboard-widget-action"
                x-on:click="$wire.mountAction('editWidget', { widget: {{ $widget['id'] }} })"
                title="{{ __('filament-dynamic-dashboard::dashboard.edit_widget') }}"
            >
                <x-filament::icon icon="heroicon-o-pencil-square" />
            </button>

            <button
                type="button"
                class="dashboard-widget-action dashboard-widget-action-danger"
                x-on:click="$wire.mountAction('deleteWidget', { widget: {{ $widget['id'] }} })"
                title="{{ __('filament-dynamic-dashboard::dashboard.delete_widget') }}"
            >
                <x-filament::icon icon="heroicon-o-trash" />
            </button>
        </div>
    @endif

    <div class="dashboard-widget-body">
        @livewire(
            $widget['type'],
            array_merge(
                [
                    'dynamicDashboardWidgetId' => $widget['id'],
                    'dynamicDashboardWidgetTitle' => $widget['name'],
                ],
                $widget['settings'] ?? []
            ),
            'widget-' . $widget['id']
        )

        @if ($showLoader)
            <div x-show="loading" x-cloak class="dashboard-widget-loader">
                <x-filament::loading-indicator />
            </div>
        @endif
    </div>
</div>
