<?php

namespace Crumbls\Importer\Facades;

use Crumbls\Importer\Transformers\TransformationManager;
use Illuminate\Support\Facades\Facade;

class TransformationManagerFacade extends Facade
{
	protected static function getFacadeAccessor()
	{
		return TransformationManager::class;
	}
}