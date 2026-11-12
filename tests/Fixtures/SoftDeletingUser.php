<?php

namespace MDDev\DynamicDashboard\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletingUser extends User
{
    use SoftDeletes;
}
