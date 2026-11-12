<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use Filament\Pages\Page;

/**
 * A panel page that has nothing to do with dashboards.
 */
class PlainPage extends Page
{
    protected static ?string $slug = 'plain-page';
}
