<?php

namespace MDDev\DynamicDashboard\Templates;

use InvalidArgumentException;

/**
 * Loads dashboard layout templates from JSON files on disk.
 *
 * Paths scanned (in order):
 *  1. The package's own `resources/dashboard-templates` directory (shipped presets).
 *  2. Every directory listed in `config('filament-dynamic-dashboard.template_paths')`.
 *
 * Later definitions override earlier ones by `key`, so app-level templates can
 * replace shipped presets without editing the package. Parsed templates are
 * cached in-memory for the request; translation lookups happen at read time so
 * locale changes are picked up immediately.
 */
class TemplateRegistry
{
    /**
     * @var array<string, DashboardTemplate>|null
     */
    protected ?array $cache = null;

    /**
     * Absolute path to the SVG preview file for each template key, when one
     * exists alongside the JSON (same basename + `.svg`). Populated during
     * `load()` and cleared by `flush()`.
     *
     * @var array<string, string>
     */
    protected array $previewPaths = [];

    /**
     * All templates, keyed by their `key`.
     *
     * @return array<string, DashboardTemplate>
     */
    public function all(): array
    {
        return $this->cache ??= $this->load();
    }

    /**
     * Templates that should appear in the UI selector — i.e. `all()` minus
     * any keys listed in `config('filament-dynamic-dashboard.disabled_templates')`.
     *
     * Dashboards already pointing at a disabled template keep rendering
     * correctly through `find()` / `default()`; this filter only governs
     * what's offered when picking a template in the manager.
     *
     * @return array<string, DashboardTemplate>
     */
    public function enabled(): array
    {
        $disabled = config('filament-dynamic-dashboard.disabled_templates', []);

        if (empty($disabled)) {
            return $this->all();
        }

        return array_filter(
            $this->all(),
            fn (DashboardTemplate $template): bool => ! in_array($template->key, $disabled, true),
        );
    }

    /**
     * Look up a template by key. Returns null when unknown.
     */
    public function find(?string $key): ?DashboardTemplate
    {
        if ($key === null) {
            return null;
        }

        return $this->all()[$key] ?? null;
    }

    /**
     * The configured default template, or a hardcoded fallback when neither
     * the configured key nor any shipped preset resolves.
     */
    public function default(): DashboardTemplate
    {
        $configured = config('filament-dynamic-dashboard.default_template');

        return $this->find($configured)
            ?? $this->find('flat-12')
            ?? new DashboardTemplate(
                key: 'flat-12',
                columns: 12,
                rawName: 'filament-dynamic-dashboard::templates.flat_12.name',
                rawDescription: 'filament-dynamic-dashboard::templates.flat_12.description',
                sections: [
                    new DashboardSection(
                        slug: 'main',
                        rawName: null,
                        columns: 12,
                        rowSpan: 1,
                        rowHeight: 80,
                    ),
                ],
            );
    }

    /**
     * Drop the in-memory cache. Useful in tests.
     */
    public function flush(): void
    {
        $this->cache = null;
        $this->previewPaths = [];
    }

    /**
     * Contents of the SVG preview file paired with `$key`, or null when no
     * preview was shipped for that template.
     */
    public function previewSvg(?string $key): ?string
    {
        $this->all();

        if ($key === null || ! isset($this->previewPaths[$key])) {
            return null;
        }

        $contents = file_get_contents($this->previewPaths[$key]);

        return $contents === false ? null : $contents;
    }

    /**
     * Scan every configured path and parse each JSON file.
     *
     * @return array<string, DashboardTemplate>
     */
    protected function load(): array
    {
        $templates = [];
        $this->previewPaths = [];

        foreach ($this->paths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
                $template = $this->parse($file);
                $templates[$template->key] = $template;

                $svg = substr($file, 0, -strlen('.json')).'.svg';
                if (is_file($svg)) {
                    $this->previewPaths[$template->key] = $svg;
                }
            }
        }

        return $templates;
    }

    /**
     * Directories scanned for JSON templates. Package presets first, then user paths.
     *
     * @return array<int, string>
     */
    protected function paths(): array
    {
        return [
            __DIR__.'/../../resources/dashboard-templates',
            ...config('filament-dynamic-dashboard.template_paths', []),
        ];
    }

    /**
     * Parse and validate a single JSON template file.
     *
     * @throws InvalidArgumentException when the file is malformed or missing required fields.
     */
    protected function parse(string $file): DashboardTemplate
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new InvalidArgumentException('Cannot read template file: '.$file);
        }

        $data = json_decode($contents, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON in template file: '.$file);
        }

        foreach (['key', 'name', 'description', 'columns', 'sections'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidArgumentException(
                    'Missing "'.$required.'" in template file: '.$file
                );
            }
        }

        $parentColumns = (int) $data['columns'];

        if ($parentColumns < 1 || $parentColumns > 24) {
            throw new InvalidArgumentException(
                'Template "'.$data['key'].'" must have columns between 1 and 24, got '.$parentColumns
            );
        }

        if (! is_array($data['sections']) || $data['sections'] === []) {
            throw new InvalidArgumentException(
                'Template "'.$data['key'].'" must declare at least one section.'
            );
        }

        $sections = [];
        $seenSlugs = [];

        foreach ($data['sections'] as $index => $sectionData) {
            $section = $this->parseSection($data['key'], $index, $sectionData, $parentColumns);

            if (isset($seenSlugs[$section->slug])) {
                throw new InvalidArgumentException(
                    'Template "'.$data['key'].'" has duplicate section slug "'.$section->slug.'".'
                );
            }

            $seenSlugs[$section->slug] = true;
            $sections[] = $section;
        }

        return new DashboardTemplate(
            key: (string) $data['key'],
            columns: $parentColumns,
            rawName: (string) $data['name'],
            rawDescription: (string) $data['description'],
            sections: $sections,
        );
    }

    /**
     * Parse and validate one entry from the template's `sections` array.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseSection(string $templateKey, int $index, mixed $data, int $parentColumns): DashboardSection
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException(
                'Section #'.$index.' in template "'.$templateKey.'" is not an object.'
            );
        }

        foreach (['slug', 'columns', 'row_height'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidArgumentException(
                    'Section #'.$index.' in template "'.$templateKey.'" is missing "'.$required.'".'
                );
            }
        }

        $columns = (int) $data['columns'];
        $rowSpan = (int) ($data['row_span'] ?? 1);
        $rowHeight = (int) $data['row_height'];

        if ($columns < 1 || $columns > $parentColumns) {
            throw new InvalidArgumentException(
                'Section "'.$data['slug'].'" in template "'.$templateKey
                .'" must have columns between 1 and '.$parentColumns.', got '.$columns
            );
        }

        if ($rowSpan < 1) {
            throw new InvalidArgumentException(
                'Section "'.$data['slug'].'" in template "'.$templateKey
                .'" must have row_span >= 1, got '.$rowSpan
            );
        }

        if ($rowHeight < 20 || $rowHeight > 500) {
            throw new InvalidArgumentException(
                'Section "'.$data['slug'].'" in template "'.$templateKey
                .'" must have row_height between 20 and 500, got '.$rowHeight
            );
        }

        $rawName = $data['name'] ?? null;

        return new DashboardSection(
            slug: (string) $data['slug'],
            rawName: $rawName !== null ? (string) $rawName : null,
            columns: $columns,
            rowSpan: $rowSpan,
            rowHeight: $rowHeight,
        );
    }
}
