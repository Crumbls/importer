<?php

namespace Crumbls\Importer\Traits;

use Crumbls\Importer\Support\ColumnDefinition;
use Crumbls\Importer\Support\SqlFileIterator;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Traits\Macroable;

trait HasSqlImporter {
	protected $file;
	protected $tables = [];
	protected $currentTable = null;
	protected $batch = 1000;
	protected array $tableDefinitions = [];
	protected $connection;

	public function initializeSqlImporter(string $filePath, ?string $connection = null) {
		if (!file_exists($filePath)) {
			throw new Exception("SQL file not found: {$filePath}");
		}

		$this->file = fopen($filePath, 'r');
		$this->connection = $connection;
	}

	protected function getDb() {
		return $this->connection ? DB::connection($this->connection) : DB::connection();
	}

	public function import() {
		$db = $this->getDb();
		$db->disableQueryLog();

		$iterator = new SqlFileIterator($this->getRecord()->source);

		foreach ($iterator as $statement) {
			/**
			 * This is still causing a lot of problems when we have special characters.
			 */
			dump($statement);
			if (stripos($statement, 'CREATE TABLE') === 0) {
				$this->handleCreateTable($statement);
			} elseif (stripos($statement, 'INSERT INTO') === 0) {
				/**
				 * TODO: Find a better way to extract the table name.
				 */
				$tableName = trim(substr($statement, 11));

				if ($x = strpos($tableName,' VALUES ')) {
					$tableName = substr($tableName, 0, $x);
				}

				$tableName = preg_replace('/[^a-zA-Z0-9_ %\[\]\.\(\)%&-]/s', '', $tableName);

				$this->handleInsert($tableName, $statement);
			} else {

				dump($statement);
				continue;
			}
		}
		return;
		exit;

		while (!feof($this->file)) {
			$line = trim(fgets($this->file));

			echo $line.'<br />'.PHP_EOL;

			if (empty($line) || strpos($line, '--') === 0) {
				continue;
			}


			if (preg_match('/^CREATE TABLE\s+[`"]?([^`"\s]+)[`"]?\s*\(/i', $line, $matches)) {
//				dump($line);
				$this->handleCreateTable($matches[1]);
				continue;
			}

			if (preg_match('/^INSERT INTO\s+[`"]?([^`"\s]+)[`"]?\s+/i', $line, $matches)) {
				dump($line);
				$this->handleInsert($matches[1], $line);
				continue;
			}
		}

		fclose($this->file);
		$db->enableQueryLog();
	}

	protected function handleCreateTable($tableName) {
		$this->currentTable = $this->sanitizeTableName($tableName);

		$this->tableDefinitions[$this->currentTable] = [];

		$definition = '';
		while (!feof($this->file)) {
			$line = trim(fgets($this->file));
			$definition .= ' ' . $line;

			if (strpos($line, ');') !== false) {
				break;
			}
		}

		preg_match_all('/[`"]([^`"]+)[`"]\s+([^,\n]+)(?:,|\n|$)/i', $definition, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			if (!preg_match('/PRIMARY\s+KEY|KEY|INDEX|CONSTRAINT/i', $match[0])) {
				$columnName = $match[1];
				$columnDefinition = ColumnDefinition::fromMySqlDefinition($columnName, $match[2]);
				$this->tableDefinitions[$this->currentTable][$columnName] = $columnDefinition;
			}
		}

		// a
		$createBlueprint = function($table, ?\Closure $callback = null)
		{
			$prefix = $this->getImportConnection()->getConfig('prefix_indexes')
				? $this->getImportConnection()->getConfig('prefix')
				: '';

			return Container::getInstance()->make(Blueprint::class, compact('table', 'callback', 'prefix'));
		};

		$temp = $createBlueprint($tableName, function($c) {
			dd($c);

			$callback($blueprint);
		});

		dd($temp);
		// b

		foreach($this->tableDefinitions[$this->currentTable] as $column) {
			dd($column, $this->tableDefinitions[$this->currentTable]);
		}
		dd(__LINE__);
		/**
		 * Done after this.
		 */

		// Create SQLite table
		$columns = [];
		foreach ($this->tableDefinitions[$this->currentTable] as $column) {
			$sqliteType = $column->toSqliteType();
			$columns[] = sprintf('"%s" %s%s',
				$column->originalName,
				$sqliteType,
				$column->nullable ? '' : ' NOT NULL'
			);
		}

		$createTableSql = sprintf(
			'CREATE TABLE IF NOT EXISTS "%s" (%s)',
			$this->currentTable,
			implode(', ', $columns)
		);

		$this->getDb()->statement($createTableSql);
	}
	protected function handleInsert($tableName, $line) {
		$tableName = $this->sanitizeTableName($tableName);
		$values = [];
		$columns = [];
		$completeStatement = $line;

		// For multi-line INSERT statements
		if (!str_contains($line, ';')) {
			while (!feof($this->file)) {
				$nextLine = trim(fgets($this->file));
				$completeStatement .= ' ' . $nextLine;

				// Check for balanced parentheses and semicolon to ensure complete statement
				if ($this->isCompleteInsertStatement($completeStatement)) {
					break;
				}
			}
		}

		// Extract columns
		if (preg_match('/INSERT INTO[^(]+\(([^)]+)\)/i', $completeStatement, $matches)) {
			$columns = array_map(function($col) {
				return trim(trim($col, '`"'));
			}, explode(',', $matches[1]));
		} else {
			$columns = array_keys($this->tableDefinitions[$tableName]);
		}

		// Extract all value sets
		if (preg_match('/VALUES\s*(.*);$/is', $completeStatement, $matches)) {
			$valueString = $matches[1];
			$values = $this->parseValues($valueString, $columns, $tableName);

			// Insert in batches
			foreach (array_chunk($values, $this->batch) as $batch) {
				try {
					$this->getDb()->table($tableName)->insert($batch);
				} catch (\Exception $e) {
					// Log the error and continue
					error_log("Error inserting into {$tableName}: " . $e->getMessage());
					error_log("Statement: " . print_r($batch, true));
				}
			}
		}
	}

	private function isCompleteInsertStatement($statement): bool {
		// Check for semicolon at the end
		if (!str_ends_with(trim($statement), ';')) {
			return false;
		}

		// Count opening and closing parentheses
		$openCount = substr_count($statement, '(');
		$closeCount = substr_count($statement, ')');

		return $openCount === $closeCount;
	}

	protected function parseValues($valueString, $columns, $tableName): array {
		$values = [];
		$pattern = '/\(((?:[^)(]+|\((?:[^)(]+|\([^)(]*\))*\))*)\)/s';
		preg_match_all($pattern, $valueString, $matches);

		foreach ($matches[1] as $valueSet) {
			$rowValues = $this->parseValueSet($valueSet, $columns, $tableName);
			if ($rowValues) {
				$values[] = $rowValues;
			}
		}

		return $values;
	}

	private function parseValueSet($valueSet, $columns, $tableName): ?array {
		$values = [];
		$currentValue = '';
		$inQuote = false;
		$quoteChar = '';
		$position = 0;

		for ($i = 0; $i < strlen($valueSet); $i++) {
			$char = $valueSet[$i];
			$prevChar = $i > 0 ? $valueSet[$i - 1] : '';

			// Handle quotes
			if (($char === "'" || $char === '"') && $prevChar !== '\\') {
				if (!$inQuote) {
					$inQuote = true;
					$quoteChar = $char;
				} elseif ($char === $quoteChar) {
					$inQuote = false;
				} else {
					$currentValue .= $char;
				}
				continue;
			}

			// Handle commas outside quotes
			if ($char === ',' && !$inQuote) {
				$values[] = $this->processValue(trim($currentValue), $columns[$position], $tableName);
				$currentValue = '';
				$position++;
				continue;
			}

			$currentValue .= $char;
		}

		// Add the last value
		if ($currentValue !== '') {
			$values[] = $this->processValue(trim($currentValue), $columns[$position], $tableName);
		}

		// Only return if we have the right number of columns
		return count($values) === count($columns) ? array_combine($columns, $values) : null;
	}

	protected function processValue($value, $column, $tableName) {
		$value = trim($value);
		$columnDefinition = $this->tableDefinitions[$tableName][$column] ?? null;

		if (!$columnDefinition) {
			return $value;
		}

		if (strtoupper($value) === 'NULL') {
			return null;
		}

		if (preg_match('/^[\'"](.*)[\'"]$/', $value, $matches)) {
			$value = str_replace(['\\\\', "\\'", '\\"'], ['\\', "'", '"'], $matches[1]);
		}

		return $columnDefinition->transformValue($value);
	}

	protected function sanitizeTableName($name) {
		return trim(trim($name, '`"'));
	}

	public function setBatchSize(int $size) {
		$this->batch = $size;
		return $this;
	}
}