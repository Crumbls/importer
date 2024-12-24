<?php


namespace Crumbls\Importer\Drivers\Common\States;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
			$this->createMigrationForTransformer($transformer);
		}
	}

	protected function createMigrationForTransformer(array $transformer): void {

		// Skip if table already exists
		if (Schema::hasTable($transformer['to_table'])) {
			return;
		}

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

	protected function generateMigrationName(string $table): string {
		$prefix = date('Y_m_d_His');
		return sprintf('%s_create_%s_table.php', $prefix, $table);
	}

	protected function getMigrationPath(string $filename): string {
		return database_path('migrations/' . $filename);
	}

	protected function generateUpMethod($method, array $transformer): void {
		$method->setBody(sprintf(
			'Schema::create($this->getTable(), function (Blueprint $table) {
                %s
            });',
			$this->generateColumns($transformer)
		));
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

	protected function generateColumn(string $column, array $type): ?string {
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