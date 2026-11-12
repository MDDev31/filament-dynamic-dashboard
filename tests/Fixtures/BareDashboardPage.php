<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use MDDev\DynamicDashboard\Pages\DynamicDashboard;

/**
 * Dashboard page with one filter and no header actions at all: nothing but
 * the page's own code loads the current dashboard on subsequent requests.
 */
class BareDashboardPage extends DynamicDashboard
{
    protected static ?string $slug = 'bare-dashboard';

    public static bool $editable = true;

    /** @inheritDoc */
    public static function canEdit(): bool
    {
        return static::$editable;
    }

    /** @inheritDoc */
    public static function getDashboardFilters(): array
    {
        return [
            TextInput::make('keyword'),
        ];
    }

    /** @inheritDoc */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
