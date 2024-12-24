<?php

namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Traits\HasTableTransformer;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use Crumbls\Importer\Transformers\TransformationDefinition;
use Illuminate\Support\Str;
use Selective\Transformer\ArrayTransformer;

/**
 * TODO: We will end up using a version of this code under common code to map other tables.
 * This is here just as a brainstorming point.
 */
class MapModelsState extends AbstractState
{
	use IsTableSchemaAware,
		HasTransformerDefinition;

	public function getName(): string {
		return 'map-database-models';
	}

	public function handle(): void {

		$connection = $this->getDriver()->getImportConnection();

		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$md['transformers'] = array_key_exists('transformers', $md) && is_array($md['transformers']) ? $md['transformers'] : [];

		$tables = $this->getTables();


		$namespace = app()->getNamespace().'Models\\';

		/**
		 * Now create a transformer for every table.
		 */
		foreach($tables as $source => $destination) {
			$modelName = $this->generateModelName($destination);

			// Define your transformation
			$definition = new TransformationDefinition($destination);
			$definition
				->setModelName($namespace . $modelName)
				->setFromTable($source)
				->setToTable($destination);

			$this->setTransformer($definition);

			$existing = $definition->getMappedKeys();

			$schema = $this->getTableSchema($connection, $destination);

			foreach($schema as $column) {
				if (in_array($column['name'], $existing)) {
					continue;
				}

				if ($definition->isExcluded($column['name'])) {
					continue;
				}

				/**
				 * I am not happy with how this is working right now. Jesus.
				 */
				$this->defineColumn($column);
			}

			$type = array_key_exists($destination, $md['transformers']) ? (string)\Str::uuid() : $destination;

			$md['transformers'][$type] = $definition->toArray();
		}

		$record->update([
			'metadata' => $md
		]);
	}

	protected function getTables() : array {

		$connection = $this->getDriver()->getImportConnection();

		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$prefix = array_key_exists('table_prefix', $md) && is_string($md['table_prefix']) ? $md['table_prefix'] : '';

		$tables = array_column($this->getDatabaseTables($connection), 'name');

		$tables = array_combine($tables, array_map(function($table) use ($prefix) {
			return (strpos($table, $prefix) === 0) ? substr($table, strlen($prefix)) : $table;
		}, $tables));

		return $tables;
	}

	private function generateModelName(string $type): string {
		return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $type)));
	}

	private function generateTableName(string $type): string {
		return strtolower(Str::plural($type));
	}
}