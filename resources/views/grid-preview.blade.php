@php
    $colors = [
        0 => 'bg-blue-100 border-blue-400 text-blue-800',
        1 => 'bg-green-100 border-green-400 text-green-800',
        2 => 'bg-red-100 border-red-400 text-red-800',
    ];

    $colSpanClasses = [
        1 => 'col-span-1',
        2 => 'col-span-2',
        3 => 'col-span-3',
        4 => 'col-span-4',
        5 => 'col-span-5',
        6 => 'col-span-6',
        7 => 'col-span-7',
        8 => 'col-span-8',
        9 => 'col-span-9',
        10 => 'col-span-10',
        11 => 'col-span-11',
        12 => 'col-span-12',
    ];
@endphp

<div class="grid grid-cols-12 gap-2 p-4">
    @foreach($grid->rootBlocks as $block)
        @include('filament-dynamic-dashboard::partials.block-preview', [
            'block' => $block,
            'depth' => 0,
            'colors' => $colors,
            'colSpanClasses' => $colSpanClasses
        ])
    @endforeach
</div>
