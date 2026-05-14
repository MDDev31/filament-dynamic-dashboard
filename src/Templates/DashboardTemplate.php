<?php

namespace MDDev\DynamicDashboard\Templates;

/**
 * Parsed representation of a dashboard layout template.
 *
 * Templates are stored as JSON files on disk and loaded by TemplateRegistry.
 * `rawName` and `rawDescription` are Laravel translation keys — call the
 * `name()` / `description()` methods to get the localized strings.
 *
 * `columns` is the parent CSS-grid column count; the ordered `sections`
 * each declare their own column-span / row-span / row-height. There is
 * always at least one section.
 */
final readonly class DashboardTemplate
{
    /**
     * @param  array<int, DashboardSection>  $sections
     */
    public function __construct(
        public string $key,
        public int $columns,
        public string $rawName,
        public string $rawDescription,
        public array $sections,
    ) {}

    /**
     * Localized template name.
     */
    public function name(): string
    {
        return (string) __($this->rawName);
    }

    /**
     * Localized template description.
     */
    public function description(): string
    {
        return (string) __($this->rawDescription);
    }

    /**
     * Look up a section by slug. Returns null when unknown.
     */
    public function section(string $slug): ?DashboardSection
    {
        foreach ($this->sections as $section) {
            if ($section->slug === $slug) {
                return $section;
            }
        }

        return null;
    }
}
