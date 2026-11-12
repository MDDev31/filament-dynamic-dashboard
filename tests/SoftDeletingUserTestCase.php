<?php

namespace MDDev\DynamicDashboard\Tests;

use MDDev\DynamicDashboard\Tests\Fixtures\SoftDeletingUser;

/**
 * Same application, but the configured user model uses SoftDeletes.
 */
abstract class SoftDeletingUserTestCase extends TestCase
{
    /** @inheritDoc */
    protected function userModel(): string
    {
        return SoftDeletingUser::class;
    }
}
