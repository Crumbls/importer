<?php

namespace Crumbls\Importer\Traits;

use Crumbls\Importer\Transformers\TransformationDefinition;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

trait HasTransformerDefinition {
	private TransformationDefinition $definition;

	protected function setTransformer(?TransformationDefinition $definition) : self {
		$this->definition = $definition ? $definition : new TransformationDefinition(md5(time()));
		return $this;
	}

	protected function getTransformer() : TransformationDefinition {
		return $this->definition;
	}

	public function defineColumn(array $column) : TransformationDefinition {
		$transformer = $this->getTransformer();

		if ($transformer->isExcluded($column['name'])) {
			return $transformer;
		}

		// Start with basic column mapping
		$transformer
			->map($column['name'])
			->to($column['name']);

		// Handle type mapping
		$type = $this->getTransformedColumnType($column);

		$transformer->type($type);

		return $transformer;
	}

	protected function getTransformedColumnType(array $column) : string {
		return match($column['type_name']) {
			// Integers
			'integer' => $column['auto_increment'] ? 'increments' : ($column['unsigned'] ?? false ? 'unsignedInteger' : 'integer'),
			'bigint' => $column['auto_increment'] ? 'bigIncrements' : ($column['unsigned'] ?? false ? 'unsignedBigInteger' : 'bigInteger'),
			'smallint' => $column['auto_increment'] ? 'smallIncrements' : ($column['unsigned'] ?? false ? 'unsignedSmallInteger' : 'smallInteger'),
			'tinyint' => $column['auto_increment'] ? 'tinyIncrements' : ($column['unsigned'] ?? false ? 'unsignedTinyInteger' : 'tinyInteger'),
			'mediumint' => $column['auto_increment'] ? 'mediumIncrements' : ($column['unsigned'] ?? false ? 'unsignedMediumInteger' : 'mediumInteger'),

			// Decimals/Floats
			'decimal' => 'decimal',
			'float' => 'float',
			'double' => 'double',

			// Strings
			'char' => 'char',
			'varchar' => 'string',
			'tinytext' => 'string',
			'text' => 'text',
			'mediumtext' => 'mediumText',
			'longtext' => 'longText',

			// Dates and Times
			'date' => 'date',
			'datetime' => 'datetime',
			'timestamp' => 'timestamp',
			'time' => 'time',
			'year' => 'year',

			// Binary
			'binary' => 'binary',
			'varbinary' => 'binary',
			'blob' => 'binary',
			'mediumblob' => 'binary',
			'longblob' => 'binary',

			// Others
			'boolean' => 'boolean',
			'enum' => 'enum',
			'json' => 'json',
			'jsonb' => 'json',

			// Default fallback
			default => 'string'
		};
	}


}