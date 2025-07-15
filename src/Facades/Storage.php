<?php

namespace Crumbls\Importer\Facades;

use Illuminate\Support\Facades\Facade;

class Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'importer-storage';
    }
}