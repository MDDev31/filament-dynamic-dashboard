@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\Facades\FilamentView;

    $widgetsBySection = $widgetsBySection ?? [];
    $canEdit = $canEdit ?? false;
    $canDrag = $canDrag ?? false;
    $pageFilters = $pageFilters ?? [];
    $assetPackage = 'mddev31/filament-dynamic-dashboard';
@endphp

{{--
    Outside SPA mode, GridStack and the canvas bundle are "loaded on request":
    Filament does not add them to every page, this view does, and Livewire
    places the block in the <head> once. In SPA mode Filament already loads
    them on every page (see LoadedOnRequestOutsideSpaMode for the reason).
--}}
@unless (FilamentView::hasSpaMode())
    @assets
        <link rel="stylesheet" href="{{ FilamentAsset::getStyleHref('gridstack', package: $assetPackage) }}" />
        <link rel="stylesheet" href="{{ FilamentAsset::getStyleHref('dashboard', package: $assetPackage) }}" />
        <script src="{{ FilamentAsset::getScriptSrc('gridstack', package: $assetPackage) }}"></script>
        <script src="{{ FilamentAsset::getScriptSrc('dashboard', package: $assetPackage) }}"></script>
    @endassets
@endunless

<div
    x-data="dashboardGrid({
        editable: @js($canDrag),
    })"
    class="dashboard-canvas{{ $canDrag ? '' : ' is-readonly' }}"
    style="grid-template-columns: repeat({{ $template->columns }}, minmax(0, 1fr));"
>
    @foreach ($template->sections as $section)
        <section
            class="dashboard-section"
            style="grid-column: span {{ $section->columns }}; grid-row: span {{ $section->rowSpan }};"
        >{{-- `--dd-row-height`, `--dd-rows` and `--dd-order` feed the compact
              (phone) layout of dashboard.css: minimum height and reading order
              of each widget once they are stacked one per row. --}}<div
                class="grid-stack"
                wire:ignore
                data-section="{{ $section->slug }}"
                gs-column="{{ $section->columns }}"
                gs-cell-height="{{ $section->rowHeight }}"
                style="--dd-row-height: {{ $section->rowHeight }}px;"
            >
                @foreach ($widgetsBySection[$section->slug] ?? [] as $widget)
                    <div
                        class="grid-stack-item"
                        gs-id="{{ $widget['id'] }}"
                        gs-x="{{ $widget['x'] }}"
                        gs-y="{{ $widget['y'] }}"
                        gs-w="{{ $widget['w'] }}"
                        gs-h="{{ $widget['h'] }}"
                        gs-min-w="{{ $widget['minW'] }}"
                        gs-max-w="{{ $widget['maxW'] }}"
                        gs-min-h="{{ $widget['minH'] }}"
                        gs-max-h="{{ $widget['maxH'] }}"
                        style="--dd-rows: {{ (int) $widget['h'] }}; --dd-order: {{ ((int) $widget['y']) * 1000 + (int) $widget['x'] }};"
                    >
                        <div class="grid-stack-item-content">
                            @include('filament-dynamic-dashboard::schemas.widget-wrapper', [
                                'widget' => $widget,
                                'canEdit' => $canEdit,
                                'showLoader' => $widget['showLoader'] ?? true,
                                'pageFilters' => $pageFilters,
                            ])
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
