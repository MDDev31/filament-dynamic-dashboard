@php
    $childComponents = $getChildComponents();
    $actionsComponent = $childComponents[0] ?? null;
    $widgetComponents = array_slice($childComponents, 1);
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
    <div>
        @foreach($widgetComponents as $component)
            {{ $component }}
        @endforeach
    </div>
</div>
