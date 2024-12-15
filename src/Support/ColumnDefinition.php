<?php

namespace Crumbls\Importer\Support;

class ColumnDefinition {
	public function __construct(
		public string $originalName,
		public string $newName,
		public string $type,
		public ?string $transform = null,
		public bool $nullable = false,
		public mixed $default = null,
		public ?int $length = null,
		public bool $unsigned = false,
		public bool $primary = false,
		public bool $autoIncrement = false,
		public ?string $charset = null,
		public ?string $collation = null,
		public array $index = []
	) {}

	public static function fromMySqlDefinition(string $name, string $definition): self {
		// Use the original name as new name initially
		$originalName = $name;
		$newName = $name;

		$def = new self(
			originalName: $originalName,
			newName: $newName,
			type: 'string'
		);

		// Basic type mapping
		if (preg_match('/^(\w+)(?:\(([\d,]+)\))?/', $definition, $matches)) {
			$type = strtolower($matches[1]);
			$length = $matches[2] ?? null;

			$def->type = match($type) {
				'int', 'tinyint', 'smallint', 'mediumint', 'bigint' => 'integer',
				'varchar', 'char' => 'string',
				'text', 'mediumtext', 'longtext' => 'text',
				'decimal', 'double', 'float' => 'decimal',
				'datetime', 'timestamp' => 'datetime',
				'date' => 'date',
				'time' => 'time',
				default => 'string'
			};

			// Set transform based on type
			$def->transform = match($def->type) {
				'integer' => 'integer',
				'decimal' => 'float',
				'datetime' => 'datetime',
				'date' => 'date',
				default => null
			};

			$def->length = $length ? (int)$length : null;
		}

		// Nullability
		$def->nullable = !str_contains($definition, 'NOT NULL');

		// Default value
		if (preg_match('/DEFAULT\s+([^\/\s]+)/', $definition, $matches)) {
			$def->default = trim($matches[1], "'\"");
		}

		// Unsigned
		$def->unsigned = str_contains($definition, 'unsigned');

		// Auto increment and primary key
		$def->autoIncrement = str_contains($definition, 'AUTO_INCREMENT');
		$def->primary = str_contains($definition, 'PRIMARY KEY');

		// Character set and collation
		if (preg_match('/CHARACTER SET\s+(\w+)/', $definition, $matches)) {
			$def->charset = $matches[1];
		}
		if (preg_match('/COLLATE\s+(\w+)/', $definition, $matches)) {
			$def->collation = $matches[1];
		}

		return $def;
	}

	public function toMigrationColumn(): string {
		$method = match($this->type) {
			'integer' => $this->unsigned ? 'unsignedInteger' : 'integer',
			'bigint' => $this->unsigned ? 'unsignedBigInteger' : 'bigInteger',
			'text' => 'text',
			'string' => 'string',
			'datetime' => 'datetime',
			'date' => 'date',
			'time' => 'time',
			'decimal' => 'decimal',
			default => 'string'
		};

		$column = "\$table->{$method}('{$this->newName}')";

		if ($this->length && $this->type === 'string') {
			$column = "\$table->string('{$this->newName}', {$this->length})";
		}

		if ($this->nullable) {
			$column .= "->nullable()";
		}

		if ($this->default !== null) {
			$column .= "->default('{$this->default}')";
		}

		if ($this->autoIncrement) {
			$column .= "->autoIncrement()";
		}

		if ($this->primary && !$this->autoIncrement) {
			$column .= "->primary()";
		}

		if ($this->charset) {
			$column .= "->charset('{$this->charset}')";
		}

		if ($this->collation) {
			$column .= "->collation('{$this->collation}')";
		}

		return $column;
	}

	public function toSqliteType(): string {
		if ($this->primary && $this->autoIncrement) {
			return 'INTEGER PRIMARY KEY AUTOINCREMENT';
		}

		return match($this->type) {
			'integer', 'bigint' => 'INTEGER',
			'decimal', 'float', 'double' => 'REAL',
			'text', 'string' => 'TEXT',
			'datetime', 'date', 'time' => 'TEXT',
			default => 'TEXT'
		};
	}

	/**
	 * Transform a value according to the column's type
	 */
	public function transformValue(mixed $value): mixed {
		if ($value === null) {
			return null;
		}

		return match($this->transform) {
			'integer' => (int) $value,
			'float' => (float) $value,
			'boolean' => (bool) $value,
			'datetime' => date('Y-m-d H:i:s', strtotime($value)),
			'date' => date('Y-m-d', strtotime($value)),
			default => $value
		};
	}
}