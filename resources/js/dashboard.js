// Alpine component that wires every `.grid-stack` inside `.dashboard-canvas`
// to a GridStack instance. Drag, resize, and cross-section moves all flush
// the full layout back to the Livewire page via `persistLayout`.
//
// Outside SPA mode this file is only loaded on dashboard pages (see the
// `@assets` block of the canvas view). The component is registered straight
// away when Alpine already exists, so it does not matter whether the script
// runs before or after `alpine:init` has fired.

(() => {
    const register = () => {
        window.Alpine.data('dashboardGrid', (options = {}) => ({
            options: { editable: true, ...options },
            debounceTimer: null,
            booted: false,
            destroyed: false,
            stopListeningToEditableChanges: null,
            // True while the stylesheet shows the one-widget-per-row layout
            // used on phones (see "Compact layout" in dashboard.css).
            compact: false,
            stopWatchingViewport: null,

            init() {
                // Alpine calls `init()` on its own. Guard against a second call
                // from a published view that still carries `x-init="init()"`.
                if (this.booted) return;
                this.booted = true;

                this.compact = this.isCompactLayout();
                this.$nextTick(() => this.bootGrids());

                // Rotating a phone or resizing the window can cross the
                // breakpoint in either direction.
                const onViewportChange = () => this.syncCompactLayout();
                window.addEventListener('resize', onViewportChange);
                this.stopWatchingViewport = () => window.removeEventListener('resize', onViewportChange);

                // Live updates from the manager's Locked / template toggles.
                // `dashboard-list-changed` causes the page to dispatch this with
                // the new effective editable state — we flip every GridStack's
                // staticGrid mode and toggle the `.is-readonly` class on the
                // canvas (which hides the action chrome via CSS).
                if (window.Livewire) {
                    this.stopListeningToEditableChanges = window.Livewire.on(
                        'dynamic-dashboard:editable-changed',
                        (payload) => {
                            const data = Array.isArray(payload) ? payload[0] : payload;
                            this.setEditable(Boolean(data?.editable));
                        }
                    );
                }
            },

            // Called by Alpine when the canvas leaves the DOM — typically a
            // `wire:navigate` visit, where the window (and everything
            // registered on it) outlives the page.
            destroy() {
                this.destroyed = true;

                if (typeof this.stopListeningToEditableChanges === 'function') {
                    this.stopListeningToEditableChanges();
                }
                this.stopListeningToEditableChanges = null;

                if (typeof this.stopWatchingViewport === 'function') {
                    this.stopWatchingViewport();
                }
                this.stopWatchingViewport = null;

                // A drop made less than 150 ms before leaving is still waiting
                // for its debounce. It cannot be saved from here: by the time
                // Alpine calls destroy(), Livewire has already torn the page
                // component down and no longer sends its calls.
                clearTimeout(this.debounceTimer);
                this.debounceTimer = null;

                // `false` keeps the DOM: Livewire owns its removal.
                this.gridInstances().forEach((grid) => {
                    try {
                        grid.destroy(false);
                    } catch (error) {
                        // Already torn down along with its element.
                    }
                });
            },

            // The GridStack instances of the canvas, read from the elements
            // GridStack attaches them to.
            //
            // They must never be kept in this component's data: Alpine makes
            // its data reactive, which hands them back wrapped in proxies.
            // GridStack compares its own objects by identity, so an instance
            // driven through a proxy ends up with drag handlers that no
            // longer recognise their nodes — after a `setStatic()` round trip
            // a drag would then never complete, and never be saved.
            gridInstances() {
                return Array.from(this.$el.querySelectorAll('.grid-stack'))
                    .map((el) => el.gridstack)
                    .filter(Boolean);
            },

            setEditable(editable) {
                this.options.editable = editable;
                this.$el.classList.toggle('is-readonly', ! editable);
                this.applyInteractivity();
            },

            // The stylesheet owns the breakpoint and exposes the result as a
            // custom property, so the script never has to know the width.
            isCompactLayout() {
                return getComputedStyle(this.$el).getPropertyValue('--dd-compact').trim() === '1';
            },

            syncCompactLayout() {
                const compact = this.isCompactLayout();
                if (compact === this.compact) return;

                this.compact = compact;
                this.applyInteractivity();
            },

            // Widgets can be moved and resized only when the dashboard is
            // editable AND laid out as a grid. In the compact layout the
            // positions on screen are not the stored ones, so a drag there
            // would have nothing meaningful to save.
            applyInteractivity() {
                const isStatic = ! this.options.editable || this.compact;
                this.gridInstances().forEach((grid) => grid.setStatic(isStatic));
            },

            bootGrids() {
                const editable = this.options.editable;

                // Everything below is set up whatever `editable` is at this
                // point. A dashboard can be loaded locked and unlocked later
                // without a reload: its grids then only switch out of static
                // mode, so the handlers that save a move must already be there.
                // A static grid accepts no drop and emits none of these events.
                this.$el.querySelectorAll('.grid-stack').forEach((el) => {
                    const grid = GridStack.init(
                        {
                            column: parseInt(el.getAttribute('gs-column')) || 12,
                            cellHeight: parseInt(el.getAttribute('gs-cell-height')) || 80,
                            acceptWidgets: true,
                            handle: '.dd-drag-handle',
                            staticGrid: !editable || this.compact,
                            // 12px on each side of every item → 24px gutter between
                            // widgets, enough room for the floating title badge
                            // (which sticks up 0.75rem / 12px) to clear the widget
                            // above without overlapping.
                            margin: 12,
                            animate: true,
                        },
                        el
                    );

                    grid.on('change added removed', () => this.scheduleFlush());

                    // Widget content (charts) follows the new box size: most chart
                    // libraries auto-resize on window.resize, and widgets that need
                    // explicit handling can listen to the Livewire event below.
                    grid.on('resizestop', (event, item) => this.broadcastResize(item));

                    // Drop-zone highlight: drive a canvas-level class from
                    // dragstart on every grid; clear it on whichever event
                    // signals the drag ended.
                    grid.on('dragstart', () => {
                        this.$el.classList.add('is-dragging');
                        el.classList.add('is-drag-source');
                    });

                    // dragstop fires for within-grid drops. dropped fires on the
                    // DESTINATION grid for cross-section drops — and dragstop
                    // may NOT fire on the source in that case because the
                    // dragged element has been re-parented away. Listening to
                    // both covers every scenario.
                    grid.on('dragstop', (event, item) => this.endDrag(item));
                    grid.on('dropped', (event, _prev, newWidget) => this.endDrag(newWidget?.el));
                });
            },

            broadcastResize(item) {
                // Universal: ApexCharts, Chart.js, ECharts, Plotly auto-resize on this.
                window.dispatchEvent(new Event('resize'));

                // Targeted: widgets opt-in via #[On('dynamic-dashboard:widget-resized')].
                const id = parseInt(item?.getAttribute?.('gs-id'));
                if (id && window.Livewire) {
                    window.Livewire.dispatch('dynamic-dashboard:widget-resized', { id });
                }
            },

            endDrag(item) {
                // Clear our own drag-state classes AND defensively clear any
                // stuck GridStack `.grid-stack-dragging` class. Cross-section
                // drops can leave the source grid with a stale class because
                // dragstop never fires on it (the widget element has been
                // moved to the destination grid).
                this.$el.classList.remove('is-dragging');
                this.$el.querySelectorAll('.is-drag-source, .grid-stack-dragging')
                    .forEach((g) => g.classList.remove('is-drag-source', 'grid-stack-dragging'));

                this.broadcastResize(item);
            },

            scheduleFlush() {
                // Tearing the grids down emits `removed`: nothing to save then.
                // Nor on a read-only dashboard or in the compact layout, where
                // nothing can have been moved.
                if (this.destroyed || this.compact || ! this.options.editable) return;

                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.debounceTimer = null;
                    this.flush();
                }, 150);
            },

            flush() {
                const layout = [];

                this.$el.querySelectorAll('.grid-stack').forEach((gridEl) => {
                    const section = gridEl.dataset.section;
                    const grid = gridEl.gridstack;
                    if (!grid) return;

                    grid.engine.nodes.forEach((node) => {
                        const id = parseInt(node.el.getAttribute('gs-id'));
                        if (!id) return;

                        // The compact layout reads the widget's height and
                        // reading order from these properties; keep them
                        // current after a move or a resize.
                        node.el.style.setProperty('--dd-rows', node.h);
                        node.el.style.setProperty('--dd-order', node.y * 1000 + node.x);

                        layout.push({
                            id,
                            section,
                            x: node.x,
                            y: node.y,
                            w: node.w,
                            h: node.h,
                        });
                    });
                });

                this.$wire.call('persistLayout', layout);
            },
        }));
    };

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
})();
