<?php

namespace Crumbls\Importer\StorageDrivers;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\ConnectionInterface;
use Crumbls\Importer\StorageDrivers\Contracts\TransactionalStorageContract;
use Crumbls\Importer\Exceptions\StorageException;

class SqliteDriver extends AbstractDriver implements TransactionalStorageContract
{
	protected string $connectionName;
	protected ?ConnectionInterface $db = null;

	public function connection(string $connection): static {
		$this->connectionName = $connection;
		return $this;
	}

	public function path(string $path): static {
		$this->storePath = $path;
		return $this;
	}

	public function createOrFindStore(string $name): static {

		$name = Str::chopEnd($name, '.sqlite');

		$name = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $name);

		if (!Str::endsWith($name, '.sqlite')) {
			$name .= '.sqlite';
		}

		$this->storePath = storage_path('app/imports/' . $name);

		$directory = dirname($this->storePath);

		if (!is_dir($directory)) {
			mkdir($directory, 0755, true);
		}

		// Create the file if it doesn't exist
		if (!file_exists($this->storePath)) {
			touch($this->storePath);
		}

		return $this;
	}

	public function deleteStore(): static
	{
		if ($this->getStorePath()) {
			@unlink($this->getStorePath());
		}
		return $this;
	}


	public function connect(): static {
		if (!$this->connected) {
			$connectionName = 'import_' . uniqid();

			$storePath = $this->getStorePath();

			if (!file_exists($storePath)) {
				touch($this->storePath);
			}

			config(["database.connections.{$connectionName}" => [
				'driver' => 'sqlite',
				'database' => $storePath,
				'prefix' => '',
				'foreign_key_constraints' => true,
			]]);

			$this->db = DB::connection($connectionName);
			$this->connectionName = $connectionName;
			$this->connected = true;
		}
		
		return $this;
	}

	public function disconnect(): void {
		if ($this->connected) {
			DB::purge($this->connectionName);
			$this->db = null;
			$this->connected = false;
		}
	}

	public function createTable(string $tableName, callable $schemaCallback): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		Schema::connection($this->connectionName)->create($tableName, $schemaCallback);
		
		return $this;
	}

	public function createTableFromSchema(string $tableName, array $schema): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		// Convert schema definition to Laravel Blueprint
		Schema::connection($this->connectionName)->create($tableName, function (Blueprint $table) use ($schema) {
			if (isset($schema['columns'])) {
				foreach ($schema['columns'] as $columnName => $columnDef) {
					$this->addColumnFromDefinition($table, $columnName, $columnDef);
				}
			}
			
			if (isset($schema['indexes'])) {
				foreach ($schema['indexes'] as $indexDef) {
					$this->addIndexFromDefinition($table, $indexDef);
				}
			}
		});
		
		return $this;
	}

	protected function addColumnFromDefinition(Blueprint $table, string $name, array $definition): void
	{
		$this->validateColumnName($name);
		$type = $definition['type'] ?? 'string';
		$options = $definition;
		
		$column = match($type) {
			'bigint' => $table->bigInteger($name),
			'integer' => $table->integer($name),
			'string' => $table->string($name, $options['length'] ?? 255),
			'text' => $table->text($name),
			'longtext' => $table->longText($name),
			'timestamp' => $table->timestamp($name),
			'boolean' => $table->boolean($name),
			default => $table->string($name)
		};
		
		if ($options['nullable'] ?? false) {
			$column->nullable();
		}
		
		if (isset($options['default'])) {
			$column->default($options['default']);
		}
		
		if ($options['primary'] ?? false) {
			$column->primary();
		}
	}

	protected function addIndexFromDefinition(Blueprint $table, array $definition): void
	{
		$type = $definition['type'] ?? 'index';
		$columns = $definition['columns'] ?? [];
		$name = $definition['name'] ?? null;
		
		match($type) {
			'primary' => $table->primary($columns),
			'index' => $name ? $table->index($columns, $name) : $table->index($columns),
			'unique' => $name ? $table->unique($columns, $name) : $table->unique($columns),
			default => $table->index($columns)
		};
	}

	public function dropTable(string $tableName): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		Schema::connection($this->connectionName)->dropIfExists($tableName);
		
		return $this;
	}

	public function tableExists(string $tableName): bool {
		$this->connect();
		
		$this->validateTableName($tableName);
		return Schema::connection($this->connectionName)->hasTable($tableName);
	}

	public function getTables(): array {
		$this->connect();
		
		$tables = $this->db->select("SELECT name FROM sqlite_master WHERE type = ? AND name NOT LIKE ?", ['table', 'sqlite_%']);
		
		return array_column($tables, 'name');
	}

	public function insert(string $tableName, array $data): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		$this->db->table($tableName)->insert($data);
		
		return $this;
	}

	public function insertBatch(string $tableName, array $rows): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		
		// Use INSERT OR REPLACE for SQLite to handle duplicates gracefully
		if (empty($rows)) {
			return $this;
		}
		
		// Get column names from first row
		$columns = array_keys($rows[0]);
		$placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
		$values = [];
		
		foreach ($rows as $row) {
			foreach ($columns as $column) {
				$values[] = $row[$column] ?? null;
			}
		}
		
		// Build INSERT OR REPLACE query
		$sql = "INSERT OR REPLACE INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES ";
		$sql .= implode(', ', array_fill(0, count($rows), $placeholders));
		
		$this->db->statement($sql, $values);
		
		return $this;
	}

	public function select(string $tableName, array $conditions = []): array {
		$this->connect();
		
		$this->validateTableName($tableName);
		$query = $this->db->table($tableName);
		
		foreach ($conditions as $column => $value) {
			if (is_array($value)) {
				$query->whereIn($column, $value);
			} else {
				$query->where($column, $value);
			}
		}
		
		return $query->get()->toArray();
	}

	public function update(string $tableName, array $data, array $conditions): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		$query = $this->db->table($tableName);
		
		foreach ($conditions as $column => $value) {
			if (is_array($value)) {
				$query->whereIn($column, $value);
			} else {
				$query->where($column, $value);
			}
		}
		
		$query->update($data);
		
		return $this;
	}

	public function delete(string $tableName, array $conditions): static {
		$this->connect();
		
		$this->validateTableName($tableName);
		$query = $this->db->table($tableName);
		
		foreach ($conditions as $column => $value) {
			if (is_array($value)) {
				$query->whereIn($column, $value);
			} else {
				$query->where($column, $value);
			}
		}
		
		$query->delete();
		
		return $this;
	}

	public function count(string $tableName, array $conditions = []): int {
		$this->connect();
		
		$this->validateTableName($tableName);
		$query = $this->db->table($tableName);
		
		foreach ($conditions as $column => $value) {
			if (is_array($value)) {
				$query->whereIn($column, $value);
			} else {
				$query->where($column, $value);
			}
		}
		
		return $query->count();
	}

	public function exists(string $tableName, array $conditions): bool {
		return $this->count($tableName, $conditions) > 0;
	}

	public function transaction(callable $callback): mixed {
		$this->connect();
		
		return $this->db->transaction($callback);
	}

	public function beginTransaction(): static {
		$this->connect();
		
		$this->db->beginTransaction();
		
		return $this;
	}

	public function commit(): static {
		$this->connect();
		
		$this->db->commit();
		
		return $this;
	}

	public function rollback(): static {
		$this->connect();
		
		$this->db->rollBack();
		
		return $this;
	}

	public function getColumns(string $tableName): array {
		$this->connect();
		
		$this->validateTableName($tableName);
		return Schema::connection($this->connectionName)->getColumnListing($tableName);
	}

	public function getSize(): int {
		if (!file_exists($this->getStorePath())) {
			return 0;
		}
		
		return filesize($this->getStorePath());
	}

	public function db() : SQLiteConnection {
		if (!isset($this->db)) {
			throw StorageException::connectionFailed('Database connection is not established');
		}
		return $this->db;
	}

	/**
	 * Validate table name to prevent SQL injection
	 */
	protected function validateTableName(string $tableName): void {
		if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
			throw StorageException::invalidTableName($tableName);
		}
	}

	/**
	 * Validate column name to prevent SQL injection
	 */
	protected function validateColumnName(string $columnName): void {
		if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columnName)) {
			throw StorageException::invalidColumnName($columnName);
		}
	}
}