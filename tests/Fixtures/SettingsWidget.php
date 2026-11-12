<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use Filament\Widgets\StatsOverviewWidget;
use MDDev\DynamicDashboard\Concerns\HasSizeDefaults;
use MDDev\DynamicDashboard\Contracts\DynamicWidget;

/**
 * Dynamic widget with typed settings and a non-default size (4 x 3).
 */
class SettingsWidget extends StatsOverviewWidget implements DynamicWidget
{
    use HasSizeDefaults;

    public ?Mode $mode = null;

    public array $modes = [];

    public ?int $limit = null;

    /** @inheritDoc */
    public static function getWidgetLabel(): string
    {
        return 'Settings widget';
    }

    /** @inheritDoc */
    public static function getSettingsFormSchema(): array
    {
        return [];
    }

    /** @inheritDoc */
    public static function getSettingsCasts(): array
    {
        return [
            'mode' => Mode::class,
            'modes' => ['array', Mode::class],
            'limit' => 'int',
        ];
    }

    /** @inheritDoc */
    public static function getDynamicDashboardDefaultHeight(): int
    {
        return 3;
    }

    /** @inheritDoc */
    protected function getStats(): array
    {
        return [];
    }
}
