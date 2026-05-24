<?php

namespace App\Plugins\Facades;

use Illuminate\Support\Facades\Facade;

class Plugin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'plugin.manager';
    }
}