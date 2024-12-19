<?php

namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\SqlFileIterator;

use Crumbls\Importer\Support\SqlToQueryBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PDO;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Utils\Query;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\AlterStatement;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\Statements\DropStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\LockStatement;
use PhpMyAdmin\SqlParser\Statements\SetStatement;

use PhpMyAdmin\SqlParser\Lexer;

abstract class SqlToDatabaseState extends AbstractState {
	private int $chunkSize = 8192;
	private $buffer = '';
	private $fileHandle;

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

		/**
		 * Process through our SQL file.
		 */
		
		try {
			$this->fileHandle = fopen($record->source, 'r');
			if ($this->fileHandle === false) {
				throw new RuntimeException("Could not open file: {$this->filePath}");
			}

			$currentQuery = '';

			while (!feof($this->fileHandle)) {
				$chunk = fread($this->fileHandle, $this->chunkSize);
				if ($chunk === false) {
					throw new RuntimeException("Error reading file");
				}

				$this->buffer .= $chunk;

				// Process complete statements from buffer
				while (($pos = strpos($this->buffer, ';')) !== false) {
					$currentQuery = substr($this->buffer, 0, $pos + 1);
					$this->buffer = substr($this->buffer, $pos + 1);

//					dd($currentQuery);

					$this->parseAndProcessQuery($currentQuery);
				}
			}

			// Process any remaining content in buffer
			if (trim($this->buffer) !== '') {
				$this->parseAndProcessQuery($this->buffer);
			}

		} finally {
			if ($this->fileHandle) {
				fclose($this->fileHandle);
			}
		}


	}


	private function parseAndProcessQuery(string $query): void {
		try {
			$lexer = new Lexer($query);
			$parser = new Parser($lexer->list);

			// Only process if we have a valid parsed statement
			if (!empty($parser->statements)) {
				foreach ($parser->statements as $statement) {
						$type = get_class($statement);

						switch ($type) {
							case AlterStatement::class:
								$this->statementAlter($statement);
								break;
							case CreateStatement::class:
								$this->statementCreate($statement);
								break;
							case DropStatement::class:
								$this->statementDrop($statement);
								break;
							case InsertStatement::class:
								$this->statementInsert($statement);
								break;
							case LockStatement::class:
								$this->statementLock($statement);
								break;
							case SetStatement::class:
								$this->statementSet($statement);
								break;
							default:
								dump($type);
						}
				}
			}
		} catch (\Exception $e) {
			dd($e);
			// Log or handle parsing errors as needed
			error_log("Error parsing SQL: " . $e->getMessage());
		}
	}

	protected function statementAlter(AlterStatement $statement) : void {
//		dump($statement);
	}

	/**
	 * Execute a create.
	 * @param CreateStatement $statement
	 * @return void
	 */
	public function statementCreate(CreateStatement $statement) : void {
		$tableName = $statement->name->table;

		// Extract the raw SQL to analyze the primary key definition
		$rawSql = $statement->build();

		$columnDefinitions = $this->extractColumnDefinitions($statement);

		Schema::connection($this->getDriver()->getImportConnectionName())
			->create($tableName, function (Blueprint $table) use ($columnDefinitions, $tableName) {
				$hasPrimaryKey = false;

				// First pass - create all columns
				foreach ($columnDefinitions as $definition) {
					if (isset($definition['type']) && $definition['type'] === 'key') {
						if ($definition['key_type'] === 'PRIMARY KEY') {
							$hasPrimaryKey = true;
						}
						continue;
					}

					// Special handling for known WordPress tables
					if ($tableName === 'wp_posts' && $definition['name'] === 'ID') {
						$table->bigIncrements('ID');
						$hasPrimaryKey = true;
						continue;
					}

					$this->generateColumn($table, $definition);

					// Check if this column is auto-increment
					if ($definition['autoIncrement']) {
						$hasPrimaryKey = true;
					}
				}

				// Second pass - create indexes and keys
				foreach ($columnDefinitions as $definition) {
					if (isset($definition['type']) && $definition['type'] === 'key') {
						$this->generateKey($table, $definition);
					}
				}

				// If no primary key was defined, add an auto-incrementing ID
				if (!$hasPrimaryKey) {
					$table->increments('id');
				}
			});
	}

	public function statementDrop(DropStatement $statement) : void {
//		dump($statement);
	}
	/**
	 * Execute an insert.
	 * @param InsertStatement $statement
	 * @return void
	 */
	public function statementInsert(InsertStatement $statement) : void {
		$tableName = $statement->into->dest->table;
		$data = [];

		// Get column names if specified in the INSERT statement
		$hasDefinedColumns = !empty($statement->into->columns);
		$columns = [];

		if ($hasDefinedColumns) {
			$columns = array_map(
				fn($col) => is_object($col) ? $col->column : $col,
				$statement->into->columns
			);
		} else {
			// If no columns specified, we need to get them from the table structure
			$columns = $this->getDriver()
				->getImportConnection()
				->getSchemaBuilder()
				->getColumnListing($tableName);
		}

		// Handle each array of values in the statement
		foreach ($statement->values as $rowObj) {
			$rowData = [];
			foreach ($rowObj->values as $index => $value) {
				// Skip if we don't have a corresponding column
				if (!isset($columns[$index])) {
					continue;
				}

				$columnName = $columns[$index];
				$processedValue = $this->processValue($value);

				// Handle special cases for MySQL literals
				if (is_string($processedValue)) {
					// Convert MySQL boolean literals
					if (strtolower($processedValue) === 'yes') {
						$processedValue = true;
					} elseif (strtolower($processedValue) === 'no') {
						$processedValue = false;
					}

					// Handle unquoted string literals
					if (!str_starts_with($value, "'") && !str_starts_with($value, '"')) {
						$processedValue = $value;
					}
				}

				$rowData[$columnName] = $processedValue;
			}

			// Only add the row if it has the correct number of columns
			if (count($rowData) === count($columns)) {
				$data[] = $rowData;
			} else {
				// Log or handle mismatched column counts
				\Log::warning("Skipping insert row for table {$tableName} due to column count mismatch. Expected: " . count($columns) . ", Got: " . count($rowData));
			}
		}

		if (!empty($data)) {
			try {
				// Insert in chunks to handle large datasets
				foreach (array_chunk($data, 100) as $chunk) {
					$this->getDriver()
						->getImportConnection()
						->table($tableName)
						->insert($chunk);
				}
			} catch (\Exception $e) {
				\Log::error("Error inserting into {$tableName}: " . $e->getMessage());
				\Log::error("Data: " . json_encode($data));
				throw $e;
			}
		}
	}

	protected function statementLock(LockStatement $statement) : void {
//		dump($statement);
	}

	protected function statementSet(SetStatement $statement) : void {
//		dump($statement);
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
					$keyColumns = [];
					foreach ($field->key->columns as $column) {
						$keyColumns[] = is_array($column) ? $column['name'] : $column;
					}

					$definitions[] = [
						'type' => 'key',
						'key_type' => $field->key->type,
						'columns' => $keyColumns
					];
				}
				continue;
			}

			// Find default value if options exist
			$default = null;
			$isAutoIncrement = false;
			$isUnsigned = false;
			$isNullable = true;

			if ($field->options !== null) {
				// Handle options array
				foreach ($field->options as $optionName => $optionValue) {
					switch ($optionName) {
						case 'AUTO_INCREMENT':
							$isAutoIncrement = true;
							break;
						case 'UNSIGNED':
							$isUnsigned = true;
							break;
						case 'NOT NULL':
							$isNullable = false;
							break;
						case 'DEFAULT':
							$default = $optionValue;
							break;
					}
				}
			}

			$definitions[] = [
				'name' => $field->name,
				'type' => $field->type->name,
				'length' => $field->type->parameters,
				'nullable' => $isNullable,
				'default' => $default,
				'autoIncrement' => $isAutoIncrement,
				'unsigned' => $isUnsigned,
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
		if (isset($definition['type']) && $definition['type'] === 'key') {
			$this->generateKey($table, $definition);
			return;
		}

		// Map MySQL types to Laravel column types
		$typeMap = [
			'INT' => 'integer',
			'BIGINT' => 'bigInteger',
			'VARCHAR' => 'string',
			'TEXT' => 'text',
			'DATETIME' => 'dateTime',
			'TIMESTAMP' => 'timestamp',
			'BOOLEAN' => 'boolean',
			'LONGTEXT' => 'longText',
			'TINYINT' => 'tinyInteger',
			'MEDIUMTEXT' => 'mediumText',
		];

		$type = $typeMap[strtoupper($definition['type'])] ?? 'string';

		$column = null;

		// Handle auto-incrementing columns - don't add explicit primary key constraint
		if ($definition['autoIncrement']) {
			if ($type === 'bigInteger') {
				$column = $table->bigInteger($definition['name'])->autoIncrement()->unsigned();
			} else {
				$column = $table->integer($definition['name'])->autoIncrement()->unsigned();
			}
		} else if ($definition['length'] && $type === 'string') {
			$column = $table->$type($definition['name'], $definition['length'][0]);
		} else {
			$column = $table->$type($definition['name']);
		}

		if ($definition['unsigned'] && !$definition['autoIncrement']) {
			$column->unsigned();
		}

		if ($definition['nullable']) {
			$column->nullable();
		}

		if ($definition['default'] !== null) {
			$column->default($definition['default']);
		}
	}


	/**
	 * @param Blueprint $table
	 * @param array $definition
	 * @return void
	 */
	private function generateKey(Blueprint $table, array $definition): void
	{
		// Skip primary key creation if it's for an auto-incrementing column
		if ($definition['key_type'] === 'PRIMARY KEY' && count($definition['columns']) === 1) {
			foreach ($table->getColumns() as $column) {
				if ($column->get('name') === $definition['columns'][0] &&
					($column->get('autoIncrement') === true)) {
					return;
				}
			}
		}

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
	 * @param $value
	 * @return mixed
	 */
	protected function processValue($value): mixed
	{
		if ($value === 'NULL' || $value === null) {
			return null;
		}

		// Handle quoted strings
		if (is_string($value) && (
				(str_starts_with($value, "'") && str_ends_with($value, "'")) ||
				(str_starts_with($value, '"') && str_ends_with($value, '"'))
			)) {
			return substr($value, 1, -1);
		}

		// Handle numeric values
		if (is_numeric($value)) {
			return $value;
		}

		// Return the raw value for other cases
		return $value;
	}
}