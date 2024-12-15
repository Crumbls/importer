<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Support\SqlFileIterator;

use Crumbls\Importer\Support\SqlToQueryBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * @deprecated
 */
abstract class AbstractParseSqlState extends AbstractState {
	protected SqlFileIterator $iterator;


	public function getName(): string
	{
		return 'parse-sql';
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

		// a

		$md = $record->metadata ?? [];

		$dbPath = array_key_exists('db_path', $md) ? $md['db_path'] : null;

		if (!$dbPath) {
			$dbPath = database_path('/wp_import_' . $record->getKey() . '.sqlite');
			$md['db_path'] = $dbPath;
			$record->metadata = $md;
			$record->update(['metadata' => $md]);
		}

		$db = new PDO('sqlite:' . $dbPath);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// b

		/**
		 * We reconfigure it here.
		 * TODO: We really need to improve this.
		 */
		$this->getDriver()->getImportConnection();

		$this->iterator = new SqlFileIterator($record->source);

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

		/**
		 * REWRITE!
		 */
		$parser = new Parser($sql);
		foreach($parser->statements as $statement) {
			$flags = Query::getFlags($statement);
			dd($statement, $flags);
		}

dd($statement);
		if (stripos($statement, 'CREATE TABLE') === 0) {
			$this->handleCreateTable($statement);
		} elseif (stripos($statement, 'INSERT INTO') === 0) {
			$this->handleInsert($statement);
		} else {
			dd($statement);
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

	/**
	 * @deprecated
	 * @param Blueprint $table
	 * @param array $definition
	 * @return void
	 */
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

	/**
	 * Handle CREATE TABLE statements
	 * TODO: Fix this whole method.
	 */
	protected function handleCreateTable(string $statement): void {
		$tableName = $this->getTableName($statement);

		// Get everything between parentheses
		if (!preg_match('/\((.*)\)/s', $statement, $matches)) {
			return;
		}

		$columnDefinitions = $this->parseColumnDefinitions($matches[1]);

		// Use Schema builder
		Schema::connection($this->getDriver()->getImportConnectionName())
			->create($tableName, function (Blueprint $table) use ($columnDefinitions) {
				foreach ($columnDefinitions as $definition) {
					$this->addColumn($table, $definition);
				}
			});
//		dd($statement);
	}

	/**
	 * Handle INSERT INTO statements
	 */
	protected function handleInsert(string $statement): void {
		// Extract table name
		if (!preg_match('/INSERT INTO\s+[`"]?([^`"\s]+)[`"]?\s*/i', $statement, $matches)) {
			return;
		}

		$tableName = $this->cleanIdentifier($matches[1]);


		$connection = $this->getDriver()->getImportConnectionName();

		// Get all possible columns from the table
		$allTableColumns = Schema::connection($connection)->getColumnListing($tableName);



		/**
		 * TODO: Just doing a rewrite.  ffs.
		 */
		// Extract specified columns if any
		$specifiedColumns = [];
		if (preg_match('/\((.*?)\)\s+VALUES/i', $statement, $matches)) {
			$specifiedColumns = array_map(function($column) {
				return $this->cleanIdentifier($column);
			}, explode(',', $matches[1]));
		}
		if ($specifiedColumns) {
			dd($specifiedColumns);
		}

		// Extract values
		if (!preg_match('/VALUES\s*(.*);$/is', $statement, $matches)) {
			return;
		}

		$converter = new SqlToQueryBuilder($this->getDriver()->getImportConnection());
		$f = $converter->convert($statement);

		dd($f);
dd($statement);
		$valueString = $matches[1];

//		dd($valueString);

		/**
		 * TODO: Below this we are scrapping it all.
		 */

		if (!empty($specifiedColumns)) {
			// If columns were specified, use those for the insert
			$rows = $this->parseValues($valueString, $specifiedColumns);
		} else {
			// If no columns specified, match values count with table columns
			$sampleValues = $this->parseValueSet(explode('),(', $valueString)[0]);
			$columnsToUse = array_slice($allTableColumns, 0, count($sampleValues));
			$rows = $this->parseValues($valueString, $columnsToUse);
		}

// Using specific connection
// Convert and execute queries
//		$result = $converter->convert("SELECT * FROM users WHERE age > 18")->get();
//		dd($result);
//		$result = $converter->convert("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')")->execute();

		foreach($rows as $row) {
			dd($row);
		}

		dd($rows);

		// Insert in chunks to avoid memory issues
		foreach (array_chunk($rows, 1000) as $chunk) {
			try {
				$this->getDriver()
					->getImportConnection()
					->table($tableName)
					->insert($chunk);
			} catch (\Exception $e) {
				// Log the error and continue
				\Log::error("Error inserting into {$tableName}: " . $e->getMessage(), [
					'chunk_size' => count($chunk),
					'columns' => array_keys($chunk[0] ?? []),
					'table_columns' => $allTableColumns
				]);
				throw $e;
			}
		}
	}

	protected function parseValues(string $valueString, array $columns): array {
		$rows = [];
		$pattern = '/\(((?:[^)(]+|\((?:[^)(]+|\([^)(]*\))*\))*)\)/';

		if (preg_match_all($pattern, $valueString, $matches)) {
			foreach ($matches[1] as $valueSet) {
				$values = $this->parseValueSet($valueSet);
				if (count($values) === count($columns)) {
					$rows[] = array_combine($columns, $values);
					continue;
				}
				$rows[] = $values;
				dump($values);
				dump($columns);
				continue;
				if (count($values) !== count($columns)) {
					$this->getDriver()->getImportConnection()
						->table('test')
						->insert($values);
					dd($values, $columns);
					throw new \Exception(sprintf(
						"Column count (%d) doesn't match values count (%d)",
						count($columns),
						count($values)
					));
				}
			}
		}

		return $rows;
	}

	protected function parseValueSet(string $valueSet): array {
		$values = [];
		$currentValue = '';
		$inQuote = false;
		$quoteChar = '';

		for ($i = 0; $i < strlen($valueSet); $i++) {
			$char = $valueSet[$i];
			$prevChar = $i > 0 ? $valueSet[$i - 1] : '';

			// Handle quotes
			if (($char === "'" || $char === '"') && $prevChar !== '\\') {
				if (!$inQuote) {
					$inQuote = true;
					$quoteChar = $char;
					continue;
				} elseif ($char === $quoteChar) {
					$inQuote = false;
					continue;
				}
			}

			// Handle value separation
			if (!$inQuote && $char === ',') {
				$values[] = $this->processValue(trim($currentValue));
				$currentValue = '';
				continue;
			}

			$currentValue .= $char;
		}

		// Add the last value
		if ($currentValue !== '') {
			$values[] = $this->processValue(trim($currentValue));
		}

		return $values;
	}

	protected function processValue(string $value): mixed {
		// Handle NULL
		if (strtoupper($value) === 'NULL') {
			return null;
		}

		// Handle numbers
		if (is_numeric($value)) {
			return strpos($value, '.') !== false ? (float)$value : (int)$value;
		}

		// Handle quoted strings
		if (preg_match('/^[\'"](.*?)[\'"]$/s', $value, $matches)) {
			return $matches[1];
		}

		// Handle escaped values
		if (preg_match('/^[\'"]/s', $value)) {
			$value = substr($value, 1, -1);
		}

		return str_replace(
			['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t'],
			['\\', "'", '"', "\n", "\r", "\t"],
			$value
		);
	}
}