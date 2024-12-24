<?php

namespace Crumbls\Importer\Drivers\WordPress\States;

use Crumbls\Importer\Drivers\Common\States\MapModelsState as BaseState;
use Crumbls\Importer\Traits\HasTableTransformer;
use Illuminate\Support\Str;
use Selective\Transformer\ArrayTransformer;

/**
 * TODO: We will end up using a version of this code under common code to map other tables.
 * This is here just as a brainstorming point.
 */
class MapModelsState extends BaseState
{
	protected function getTables() : array {

		$connection = $this->getDriver()->getImportConnection();

		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$prefix = array_key_exists('table_prefix', $md) && is_string($md['table_prefix']) ? $md['table_prefix'] : '';

		$tables = array_diff(array_column($this->getDatabaseTables($connection), 'name'), [
			$prefix.'posts',
			$prefix.'postmeta',
		]);

		$tables = array_combine($tables, array_map(function($table) use ($prefix) {
			return (strpos($table, $prefix) === 0) ? substr($table, strlen($prefix)) : $table;
		}, $tables));

		return $tables;
	}
}