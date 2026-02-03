@php
    $colorClass = $colors[$depth] ?? $colors[2];
    $colSpan = $block->columns;
    $colSpanClass = $colSpanClasses[$colSpan] ?? 'col-span-12';
@endphp

<div class="{{ $colSpanClass }} border-2 rounded-lg p-3 {{ $colorClass }}">
    <div class="font-medium text-sm mb-1">{{ $block->name }}</div>
    <div class="text-xs opacity-75">{{ $colSpan }}/12</div>

    @if($block->children->count() > 0)
        <div class="grid grid-cols-12 gap-2 mt-2">
            @foreach($block->children as $child)
                @include('filament-dynamic-dashboard::partials.block-preview', [
                    'block' => $child,
                    'depth' => $depth + 1,
                    'colors' => $colors,
                    'colSpanClasses' => $colSpanClasses
                ])
            @endforeach
        </div>
    @endif
</div>
