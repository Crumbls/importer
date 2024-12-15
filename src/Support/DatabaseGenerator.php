<?php

namespace Crumbls\Importer\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TODO: Deprecate this.
 * @deprecated
 */
class DatabaseGenerator {
	protected $connection;

	public function __construct(string $connection = null) {
		$this->connection = $connection;
	}

	public function createTable(string $tableName, array $columnDefinitions): void {
		Schema::connection($this->connection)->create($tableName, function (Blueprint $table) use ($columnDefinitions) {
			foreach ($columnDefinitions as $column) {
				/** @var ColumnDefinition $column */
				$this->addColumn($table, $column);
			}
		});
	}

	protected function addColumn(Blueprint $table, ColumnDefinition $column): void {
		// Map column type to Blueprint method
		$method = $this->getColumnMethod($column->type);

		// Create the column
		$tableColumn = $table->{$method}($column->newName);

		// Apply modifiers
		if ($column->nullable) {
			$tableColumn->nullable();
		}

		if ($column->default !== null) {
			$tableColumn->default($column->default);
		}

		if ($column->length && in_array($column->type, ['string', 'char'])) {
			$tableColumn->length($column->length);
		}

		if ($column->unsigned) {
			$tableColumn->unsigned();
		}

		if ($column->primary) {
			if ($column->autoIncrement) {
				$tableColumn->primary()->autoIncrement();
			} else {
				$tableColumn->primary();
			}
		}
	}

	protected function getColumnMethod(string $type): string {
		return match($type) {
			'integer' => 'integer',
			'bigInteger' => 'bigInteger',
			'string' => 'string',
			'text' => 'text',
			'mediumText' => 'mediumText',
			'longText' => 'longText',
			'float' => 'float',
			'double' => 'double',
			'decimal' => 'decimal',
			'boolean' => 'boolean',
			'date' => 'date',
			'dateTime' => 'dateTime',
			'time' => 'time',
			'timestamp' => 'timestamp',
			'binary' => 'binary',
			'json' => 'json',
			default => 'string'
		};
	}
}