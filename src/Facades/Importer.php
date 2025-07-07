<?php

namespace Crumbls\Importer\Facades;

use Illuminate\Support\Facades\Facade;

class Importer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'importer';
    }
}