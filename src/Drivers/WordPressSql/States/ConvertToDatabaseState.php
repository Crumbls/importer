<?php

namespace Crumbls\Importer\Drivers\WordPressSql\States;

use Crumbls\Importer\Drivers\Common\States\SqlToDatabaseState as BaseState;
use Crumbls\Importer\Support\MySQLToSQLiteConverter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class ConvertToDatabaseState extends BaseState
{
	protected function generateColumn(Blueprint $table, array $definition): void
	{
		parent::generateColumn($table, $definition);
	}
}