@php
    $childComponents = $getChildComponents();
    $actionsComponent = $childComponents[0] ?? null;
    $widgetComponent = $childComponents[1] ?? [];
@endphp

<div class="group relative">
    {{-- Floating title badge (if display_title is true) --}}
    @if($displayTitle && $name)
        <div class="absolute -top-3 left-4 z-10">
            <span class="inline-flex items-center px-3 py-1 text-xs font-medium text-gray-700 bg-white dark:bg-gray-900 dark:text-gray-300 border border-gray-200 dark:border-gray-700 rounded-full shadow-sm">
                {{ $name }}
            </span>
        </div>
    @endif

    {{-- Edit/Delete buttons (visible on hover) --}}
    @if($canEdit && !$isLocked)
        <div class="absolute top-2 right-2 z-20 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
            @if($actionsComponent)
                {{ $actionsComponent }}
            @endif
        </div>
    @endif

    {{-- Widget content --}}
    @if($showLoader)
        <div
            x-data="{ loading: false }"
            x-init="
                $nextTick(() => {
                    const wireEl = $el.querySelector('[wire\\:id]');
                    if (wireEl) {
                        const wireId = wireEl.getAttribute('wire:id');
                        Livewire.hook('commit', ({ component, succeed, fail }) => {
                            if (component.id === wireId) {
                                loading = true;
                                succeed(() => { loading = false; });
                                fail(() => { loading = false; });
                            }
                        });
                    }
                })
            "
            class="relative"
        >
            {{ $widgetComponent }}
            <div
                x-show="loading"
                x-cloak
                class="absolute inset-0 z-30 flex items-center justify-center bg-white/50 dark:bg-gray-900/50 rounded-xl transition-opacity">
                <x-filament::loading-indicator class="h-8 w-8 text-primary-500 dark:text-primary-400" />
            </div>
        </div>
    @else
        <div>
            {{ $widgetComponent }}
        </div>
    @endif
</div>
