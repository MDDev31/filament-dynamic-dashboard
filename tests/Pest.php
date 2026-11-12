<?php

use MDDev\DynamicDashboard\Tests\SoftDeletingUserTestCase;
use MDDev\DynamicDashboard\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(SoftDeletingUserTestCase::class)->in('SoftDeletes');
