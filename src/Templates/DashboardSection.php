<?php

namespace MDDev\DynamicDashboard\Templates;

/**
 * One section (named zone) inside a dashboard template.
 *
 * `columns` is the section's column-span in the parent CSS grid AND the
 * column count of its inner GridStack. `rowSpan` is how many rows of the
 * parent grid the section occupies (default 1). `rowHeight` is the inner
 * GridStack cell height in pixels.
 *
 * `rawName` is a Laravel translation key for the section's label, shown in
 * the section selector of the Add Widget modal; `null` means the section has
 * no label and its slug is shown instead.
 */
final readonly class DashboardSection
{
    public function __construct(
        public string $slug,
        public ?string $rawName,
        public int $columns,
        public int $rowSpan,
        public int $rowHeight,
    ) {}

    /**
     * Localized label of the section, or null when it has none.
     */
    public function name(): ?string
    {
        return $this->rawName !== null ? (string) __($this->rawName) : null;
    }
}
