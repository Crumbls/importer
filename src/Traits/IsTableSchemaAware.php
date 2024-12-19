<?php

namespace Crumbls\Importer\Traits;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

trait IsTableSchemaAware {
	public function getDatabaseTables(Connection $connection) : array {
		$connectionName = $connection->getName();
		$cacheKey = 'database::'.$connectionName;

		return Cache::remember($cacheKey, 1, function() use ($connectionName) {
			return Schema::connection($connectionName)->getTables();
		});
	}
	public function getTableSchema(Connection $connection, string $tableName) : array {
		$connectionName = $connection->getName();
		$cacheKey = 'database::'.$connectionName.'::'.$tableName;

		return Cache::remember($cacheKey, 1, function() use ($connectionName, $tableName) {
			return Schema::connection($connectionName)->getColumns($tableName);
		});
	}

}