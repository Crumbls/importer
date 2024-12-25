<?php

namespace Crumbls\Importer\Drivers\Common\States;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

/**
 * A state to create models from a database.
 */
class DatabaseToMigrationState extends AbstractState
{
	use IsTableSchemaAware,
		HasTransformerDefinition;

	public function getName(): string {
		return 'database-to-migration';
	}

	public function handle(): void {
		$record = $this->getRecord();
		$md = $record->metadata ?? [];
		$transformers = $md['transformers'] ?? [];

		foreach($transformers as $transformer) {
			$this->processMigrationsForTransformer($transformer);
		}
	}

	protected function processMigrationsForTransformer(array $transformer): void {
		// Get source and destination schema information
		$sourceConnection = $this->getDriver()->getImportConnection();
		$destinationConnection = DB::connection();

		$sourceTable = $transformer['from_table'];
		$destinationTable = $transformer['to_table'];

		$sourceColumns = $this->getTableSchema($sourceConnection, $sourceTable);

		// If table doesn't exist, create it
		if (!Schema::hasTable($destinationTable)) {
			$this->createTableMigration($transformer);
			return;
		}

		$destinationColumns = $this->getTableSchema($destinationConnection, $destinationTable);

		// Compare columns and create necessary migrations
		$this->compareAndCreateMigrations($transformer, $sourceColumns, $destinationColumns);
	}

	protected function compareAndCreateMigrations(array $transformer, array $sourceColumns, array $destinationColumns): void {
		$destinationTable = $transformer['to_table'];

		$missingColumns = [];
		$alterColumns = [];

		foreach ($transformer['mappings'] as $sourceColumn => $destinationColumn) {
			// Skip excluded columns
			if (in_array($destinationColumn, $transformer['excluded_columns'] ?? [])) {
				continue;
			}

			$sourceDefinition = $this->findColumnDefinition($sourceColumns, $sourceColumn);
			if (!$sourceDefinition) {
				continue;
			}

			$destinationDefinition = $this->findColumnDefinition($destinationColumns, $destinationColumn);

			if (!$destinationDefinition) {
				// Column doesn't exist in destination
				$missingColumns[$destinationColumn] = $sourceDefinition;
			} else {
				// Check if column needs to be modified
				if ($this->columnNeedsModification($sourceDefinition, $destinationDefinition)) {
					$alterColumns[$destinationColumn] = $sourceDefinition;
				}
			}
		}

		if (!empty($missingColumns)) {
			$this->createAddColumnsMigration($transformer['to_table'], $missingColumns);
		}

		if (!empty($alterColumns)) {
			$this->createModifyColumnsMigration($transformer['to_table'], $alterColumns);
		}
	}

	protected function columnNeedsModification(array $source, array $destination): bool {
		// Compare column properties that matter
		$comparisons = [
			'type_name' => $destination['type_name'] !== $source['type_name'],
			'length' => ($source['length'] ?? null) > ($destination['length'] ?? null),
			'nullable' => ($source['nullable'] ?? false) && !($destination['nullable'] ?? false),
			'unsigned' => ($source['unsigned'] ?? false) && !($destination['unsigned'] ?? false),
		];

		return in_array(true, $comparisons);
	}

	protected function findColumnDefinition(array $columns, string $columnName): ?array {
		foreach ($columns as $column) {
			if ($column['name'] === $columnName) {
				return $column;
			}
		}
		return null;
	}

	protected function createTableMigration(array $transformer): void {
		$migrationName = $this->generateMigrationName($transformer['to_table']);
		$path = $this->getMigrationPath($migrationName);

		// Skip if migration already exists
		if (file_exists($path)) {
			return;
		}

		$file = new PhpFile;
		$file->setStrictTypes();

		$class = new \Nette\PhpGenerator\ClassType(null);

		$method = $class->addMethod('getTable');
		$method->setBody('return with(new MigrationModel)->getTable();');

		// Add up method
		$upMethod = $class->addMethod('up');
		$this->generateUpMethod($upMethod, $transformer);

		// Add down method
		$downMethod = $class->addMethod('down');
		$this->generateDownMethod($downMethod, $transformer);

		// Generate the file
		$printer = new PsrPrinter;
		$output = $printer->printFile($file);

		// Create directory if it doesn't exist
		$directory = dirname($path);
		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

		$class = (string)$class;

		$class = '<?php

use '.$transformer['model_name'].' as MigrationModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration '.$class.';';

		// Save the migration file
		file_put_contents($path, $class);
	}

	protected function createAddColumnsMigration(string $table, array $columns): void {
		$migrationName = date('Y_m_d_His') . '_add_columns_to_' . $table . '_table.php';
		$path = $this->getMigrationPath($migrationName);

		$file = new PhpFile;
		$file->setStrictTypes();

		$class = new \Nette\PhpGenerator\ClassType(null);

		// Add up method
		$upMethod = $class->addMethod('up');
		$upBody = "Schema::table('{$table}', function (Blueprint \$table) {\n";
		foreach ($columns as $columnName => $definition) {
			$upBody .= "            " . $this->generateColumn($columnName, ['type' => $definition['type_name']]) . "\n";
		}
		$upBody .= "        });";
		$upMethod->setBody($upBody);

		// Add down method
		$downMethod = $class->addMethod('down');
		$downBody = "Schema::table('{$table}', function (Blueprint \$table) {\n";
		foreach ($columns as $columnName => $definition) {
			$downBody .= "            \$table->dropColumn('{$columnName}');\n";
		}
		$downBody .= "        });";
		$downMethod->setBody($downBody);

		$class = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {' . $class . '};';

		file_put_contents($path, $class);
	}

	protected function createModifyColumnsMigration(string $table, array $columns): void {
		$migrationName = date('Y_m_d_His') . '_modify_columns_in_' . $table . '_table.php';
		$path = $this->getMigrationPath($migrationName);

		$file = new PhpFile;
		$file->setStrictTypes();

		$class = new \Nette\PhpGenerator\ClassType(null);

		// Add up method
		$upMethod = $class->addMethod('up');
		$upBody = "Schema::table('{$table}', function (Blueprint \$table) {\n";
		foreach ($columns as $columnName => $definition) {
			$upBody .= "            " . $this->generateColumn($columnName, ['type' => $definition['type_name']], true) . "\n";
		}
		$upBody .= "        });";
		$upMethod->setBody($upBody);

		// Add down method - In this case, we don't revert the changes
		$downMethod = $class->addMethod('down');
		$downMethod->setBody("// Modifications are not reverted for data integrity");

		$class = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {' . $class . '};';

		file_put_contents($path, $class);
	}

	protected function generateMigrationName(string $table): string {
		$prefix = date('Y_m_d_His');
		return sprintf('%s_create_%s_table.php', $prefix, $table);
	}

	protected function getMigrationPath(string $filename): string {
		return database_path('migrations/' . $filename);
	}

	protected function generateUpMethod($method, array $transformer): void {
		$body = '$table = $this->getTable();
            if (Schema::hasTable($table)) {
                return;
            }'.
			sprintf(
				'Schema::create($this->getTable(), function (Blueprint $table) {
                %s
            });',
				$this->generateColumns($transformer)
			);
		$method->setBody($body);
	}

	protected function generateDownMethod($method, array $transformer): void {
		$tableName = $transformer['to_table'];
		$method->setBody('Schema::dropIfExists($this->getTable());');
	}

	protected function generateColumns(array $transformer): string {
		$columns = [];

		$transformer['excluded_columns'][] = 'created_at';
		$transformer['excluded_columns'][] = 'updated_at';
		$transformer['excluded_columns'][] = 'deleted_at';

		foreach ($transformer['mappings'] as $oldColumn => $newColumn) {
			if (!isset($transformer['types'][$oldColumn])) {
				continue;
			}

			if (in_array($newColumn, $transformer['excluded_columns'])) {
				continue;
			}

			$type = $transformer['types'][$oldColumn];
			$columnDef = $this->generateColumn($newColumn, $type);
			if ($columnDef) {
				$columns[] = $columnDef;
			}
		}

		// Add timestamps by default unless they're explicitly mapped
		if (!isset($transformer['mappings']['created_at']) && !isset($transformer['mappings']['updated_at'])) {
			$columns[] = '$table->timestamps();';
		}

		// Add softDeletes if needed
		if (isset($transformer['soft_deletes']) && $transformer['soft_deletes']) {
			$columns[] = '$table->softDeletes();';
		}

		return implode("\n                ", $columns);
	}

	protected function generateColumn(string $column, array $type, bool $change = false): ?string {
		$method = $this->getColumnMethod($type['type']);
		if (!$method) {
			return null;
		}

		$def = "\$table->{$method}('{$column}')";

		// Add modifiers
		if (isset($type['nullable']) && $type['nullable']) {
			$def .= '->nullable()';
		}

		if (isset($type['unsigned']) && $type['unsigned']) {
			$def .= '->unsigned()';
		}

		if (isset($type['default'])) {
			$default = is_string($type['default']) ? "'{$type['default']}'" : $type['default'];
			$def .= "->default({$default})";
		}

		if (isset($type['unique']) && $type['unique']) {
			$def .= '->unique()';
		}

		if (isset($type['index']) && $type['index']) {
			$def .= '->index()';
		}

		if ($change) {
			$def .= '->change()';
		}

		return $def . ';';
	}

	protected function getColumnMethod(string $type): string {
		return match($type) {
			'bigIncrements' => 'bigIncrements',
			'unsignedBigInteger' => 'unsignedBigInteger',
			'integer' => 'integer',
			'string' => 'string',
			'text' => 'text',
			'mediumText' => 'mediumText',
			'longText' => 'longText',
			'boolean' => 'boolean',
			'date' => 'date',
			'datetime' => 'dateTime',
			'decimal' => 'decimal',
			'float' => 'float',
			'json' => 'json',
			'timestamp' => 'timestamp',
			default => 'string'
		};
	}
}