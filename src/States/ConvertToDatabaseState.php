<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Support\SqlFileIterator;

use Crumbls\Importer\Support\SqlToQueryBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PDO;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Utils\Query;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;


/**
 * @deprecated
 */
abstract class ConvertToDatabaseState extends AbstractState {


	public function getName(): string
	{
		return 'convert-to-database';
	}

	/**
	 * Parse this.
	 * TODO: We are recreating the connection. Separate our concerns and improve implementation.
	 * @return void
	 * @throws \Exception
	 */
	public function handle(): void {
		$record = $this->getRecord();

		if (!file_exists($record->source)) {
			throw new \Exception("SQL file not found: {$record->source}");
		}

		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$dbPath = array_key_exists('db_path', $md) ? $md['db_path'] : null;

		if (!$dbPath) {
			$dbPath = database_path('/wp_import_' . $record->getKey() . '.sqlite');
			$md['db_path'] = $dbPath;
			$record->metadata = $md;
			$record->update(['metadata' => $md]);
		}

		/**
		 * Create database if necessary.
		 */
		$db = new PDO('sqlite:' . $dbPath);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		/**
		 * We reconfigure it here.
		 * TODO: We really need to improve this.
		 */
		$this->getDriver()->getImportConnection();

		dd($record->source);
		/**
		 * Iterate over our sql file.
		 */
		foreach ($this->iterator as $statement) {
			$this->processStatement($statement);
		}
	}

	/**
	 * Process individual SQL statement
	 */
	protected function processStatement(string $statement): void {
		$statement = trim($statement);

		if (empty($statement)) {
			return;
		}

		$parser = new Parser($statement);

		/**
		 * We no longer care about the previous statement.
		 */
		foreach($parser->statements as $statement) {
			$flags = Query::getFlags($statement);
			$queryType = $flags['querytype'];
			match($queryType) {
				'CREATE' => $this->handleCreate($statement),
//				'SELECT' => $this->handleSelect(),
				'INSERT' => $this->handleInsert($statement),
//				'UPDATE' => $this->handleUpdate(),
//				'DELETE' => $this->handleDelete(),
				default => throw new \Exception('Unsupported SQL statement type: '.$queryType),
			};
//			dump($statement);
		}
	}

	protected function getTableName(string $statement) : string {
		if (preg_match('/CREATE TABLE\s+[`"]?([^`"\s]+)[`"]?\s*\(/i', $statement, $matches)) {
			$tableName = $this->cleanIdentifier($matches[1]);
			return $tableName;
		} else if (preg_match('/INSERT INTO\s+[`"]?([^`"\s]+)[`"]?\s*/i', $statement, $matches)) {
			$tableName = $this->cleanIdentifier($matches[1]);
			return $tableName;
		}

		dd($statement);

		throw new \Exception('Unable to determine table.');
	}

	protected function parseColumnDefinitions(string $columns): array {
		$definitions = [];
		$lines = array_map('trim', explode(',', $columns));

		foreach ($lines as $line) {
			// Skip if it starts with KEY, INDEX, CONSTRAINT, etc.
			if (preg_match('/^(PRIMARY\s+KEY|KEY|INDEX|CONSTRAINT|UNIQUE)/i', $line)) {
				continue;
			}

			// Parse column definition
			if (preg_match('/^[`"]?([^`"\s]+)[`"]?\s+([^,\n]+)/i', $line, $matches)) {
				$columnName = $this->cleanIdentifier($matches[1]);
				$columnType = $matches[2];

				$definitions[] = [
					'name' => $columnName,
					'type' => $this->parseColumnType($columnType),
					'length' => $this->parseLength($columnType),
					'nullable' => !str_contains(strtoupper($columnType), 'NOT NULL'),
					'default' => $this->parseDefault($columnType),
					'unsigned' => str_contains(strtolower($columnType), 'unsigned'),
					'autoIncrement' => str_contains(strtoupper($columnType), 'AUTO_INCREMENT'),
					'primary' => str_contains(strtoupper($columnType), 'PRIMARY KEY')
				];
			}
		}

		return $definitions;
	}

	protected function addColumn(Blueprint $table, array $definition): void {
		$type = $definition['type'];
		$method = $this->getColumnMethod($type);

		$column = $table->$method($definition['name']);

		if ($definition['length'] && in_array($type, ['string', 'char'])) {
			$column->length($definition['length']);
		}

		if ($definition['nullable']) {
			$column->nullable();
		}

		if ($definition['default'] !== null) {
			$column->default($definition['default']);
		}

		if ($definition['unsigned']) {
			$column->unsigned();
		}

		if ($definition['autoIncrement']) {
			$column->autoIncrement();
		}

		if ($definition['primary']) {
			$column->primary();
		}
	}

	protected function getColumnMethod(string $type): string {
		return match($type) {
			'bigint' => 'bigInteger',
			'int', 'tinyint', 'smallint', 'mediumint' => 'integer',
			'varchar', 'char' => 'string',
			'text' => 'text',
			'mediumtext' => 'mediumText',
			'longtext' => 'longText',
			'float' => 'float',
			'double' => 'double',
			'decimal' => 'decimal',
			'datetime' => 'dateTime',
			'timestamp' => 'timestamp',
			'date' => 'date',
			'time' => 'time',
			'tinyint(1)' => 'boolean',
			'json' => 'json',
			'blob', 'binary' => 'binary',
			default => 'string'
		};
	}

	protected function parseColumnType(string $definition): string {
		if (preg_match('/^([a-z]+)(?:\(.*?\))?/i', $definition, $matches)) {
			return strtolower($matches[1]);
		}
		return 'string';
	}

	protected function parseLength(string $definition): ?int {
		if (preg_match('/\((\d+)\)/', $definition, $matches)) {
			return (int) $matches[1];
		}
		return null;
	}

	protected function parseDefault(string $definition): mixed {
		if (preg_match("/DEFAULT\s+'([^']+)'/i", $definition, $matches) ||
			preg_match('/DEFAULT\s+(\d+)/i', $definition, $matches)) {
			return $matches[1];
		}
		return null;
	}

	protected function cleanIdentifier(string $identifier): string {
		return trim($identifier, '`"');
	}

	protected function handleCreate(CreateStatement $statement) : void {
		$tableName = $statement->name->table;

		$columnDefinitions = $this->extractColumnDefinitions($statement);

		Schema::connection($this->getDriver()->getImportConnectionName())
			->create($tableName, function (Blueprint $table) use ($columnDefinitions) {
				foreach ($columnDefinitions as $definition) {
					$this->generateColumn($table, $definition);
				}
			});
	}

	/**
	 * Convert table schemas to useable data.
	 * @param Statement $statement
	 * @return array
	 */
	private function extractColumnDefinitions(Statement $statement): array
	{
		$definitions = [];

		foreach ($statement->fields as $field) {
			// Handle KEY definitions
			if ($field->type === null) {
				if ($field->key) {
					$definitions[] = [
						'type' => 'key',
						'key_type' => $field->key->type,
						'columns' => array_map(fn($column) => $column['name'], $field->key->columns)
					];
				}
				continue;
			}

			// Find default value if options exist
			$default = null;
			if ($field->options !== null) {
				foreach ($field->options as $optionName => $optionValue) {
					if ($optionName === 'DEFAULT') {
						$default = $optionValue;
						break;
					}
				}
			}

			$definitions[] = [
				'name' => $field->name,
				'type' => $field->type->name,
				'length' => $field->type->parameters,
				'nullable' => $field->options ? !$field->options->has('NOT NULL') : true,
				'default' => $default,
				'autoIncrement' => $field->options ? $field->options->has('AUTO_INCREMENT') : false,
				'unsigned' => $field->options ? $field->options->has('UNSIGNED') : false,
			];
		}

		return $definitions;
	}

	/**
	 * @param Blueprint $table
	 * @param array $definition
	 * @return void
	 */

	protected function generateColumn(Blueprint $table, array $definition): void
	{
		// If it's a key definition, handle it differently
		if (isset($definition['type']) && $definition['type'] === 'key') {
			$this->generateKey($table, $definition);
			return;
		}

		// Map MySQL types to Laravel column types
		$typeMap = [
			'INT' => 'integer',
			'VARCHAR' => 'string',
			'TEXT' => 'text',
			'DATETIME' => 'dateTime',
			'TIMESTAMP' => 'timestamp',
			'BOOLEAN' => 'boolean',
			// Add more type mappings as needed
		];

		$type = $typeMap[strtoupper($definition['type'])] ?? 'string';

		$column = null;
		if ($definition['length'] && $type === 'string') {
			$column = $table->$type($definition['name'], $definition['length'][0]);
		} else {
			$column = $table->$type($definition['name']);
		}

		if ($definition['unsigned']) {
			$column->unsigned();
		}

		if ($definition['nullable']) {
			$column->nullable();
		}

		if ($definition['default'] !== null) {
			$column->default($definition['default']);
		}

		if ($definition['autoIncrement']) {
			$column->autoIncrement();
		}
	}

	/**
	 * @param Blueprint $table
	 * @param array $definition
	 * @return void
	 */
	private function generateKey(Blueprint $table, array $definition): void
	{
		switch ($definition['key_type']) {
			case 'PRIMARY KEY':
				$table->primary($definition['columns']);
				break;
			case 'UNIQUE':
				$table->unique($definition['columns']);
				break;
			case 'INDEX':
				$table->index($definition['columns']);
				break;
		}
	}

	/**
	 * @param InsertStatement $statement
	 * @return void
	 */
	protected function handleInsert(InsertStatement $statement): void
	{
		$tableName = $statement->into->dest->table;
		$data = [];

		// Get column names if specified in the INSERT statement
		$hasDefinedColumns = !empty($statement->into->columns);
		$columns = [];
		if ($hasDefinedColumns) {
			try {
				$columns = array_map(
					fn($col) => is_object($col) ? $col->column : $col,
					$statement->into->columns
				);
			} catch (\Throwable $e) {
				dd($e, $hasDefinedColumns);
			}
		} else {
			// If no columns specified, we need to get them from the table structure
			$columns = $this->getDriver()->getImportConnection()
				->getSchemaBuilder()
				->getColumnListing($tableName);
		}

		// Handle each array of values in the statement
		foreach ($statement->values as $rowObj) {
			$rowData = [];
			foreach ($rowObj->values as $index => $value) {
				if (!isset($columns[$index])) {
					continue;
				}
				$columnName = $columns[$index];
				$processedValue = $this->processValue($value);
				$rowData[$columnName] = $processedValue;
			}
			$data[] = $rowData;
		}

		if (!empty($data)) {
			$this->getDriver()->getImportConnection()
				->table($tableName)
				->insert($data);
		}
	}

	/**
	 * @param $value
	 * @return mixed
	 */
	protected function processValue($value): mixed
	{
		if ($value === 'NULL') {
			return null;
		}

		// Remove quotes from strings
		if (is_string($value) && (
				(str_starts_with($value, "'") && str_ends_with($value, "'")) ||
				(str_starts_with($value, '"') && str_ends_with($value, '"'))
			)) {
			return substr($value, 1, -1);
		}

		return $value;
	}
}