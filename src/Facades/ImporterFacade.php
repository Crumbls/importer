<?php

namespace Crumbls\Importer\Facades;

use Crumbls\Importer\Support\ImportManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Crumbls\Importer\Tasks\ImportTask create(string $driver = null)
 * @method static \Crumbls\Importer\Contracts\DriverInterface driver(string $driver = null)
 * @method static \Crumbls\Importer\Support\ImportManager extend(string $driver, \Closure $callback)
 */
class ImporterFacade extends Facade
{
	protected static function getFacadeAccessor(): string
	{
		return ImportManager::class;
	}
}