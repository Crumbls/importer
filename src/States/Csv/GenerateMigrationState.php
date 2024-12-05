<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\States\AbstractState;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\File;
use SplFileObject;
class GenerateMigrationState extends AbstractState
{

	/**
	 * Type detection rules
	 */
	private const TYPE_RULES = [
		'id' => 'bigIncrements',
		'email' => 'string',
		'password' => 'string',
		'created_at' => 'timestamp',
		'updated_at' => 'timestamp',
		'deleted_at' => 'timestamp'
	];

	public function execute(): void
	{
		$driver = $this->getDriver();

		$modelName = $driver->getParameter('model_name');

		$tableName = $driver->getParameter('table_name');

		if (!$tableName) {
			$tableName = class_exists($modelName) ? with(new $modelName)->getTable() : Str::plural(Str::snake(class_basename($modelName)));
			$driver->setParameter('table_name', $tableName);
		}

		$migrationNeeded = $driver->getParameter('migration_needed', true);

		// If model name already exists and is valid, skip generation
		if ($migrationNeeded === false) {
			return;
		}

		// Check if table already exists
		if (Schema::hasTable($tableName)) {
			$driver->setParameter('migration_needed', false);
			return;
		}

		$migrationPath = database_path('migrations');

		$files = glob($migrationPath.'/*create_'.$tableName.'_table.php');

		if ($files && $temp = preg_grep('#^\d{4}\_\d{2}\_\d{2}\_\d{6}\_create\_'.$tableName.'\_table\.php$#', array_map(function($file) { return basename($file); }, $files))) {
			$driver->setParameter('migration_needed', false);
			return;
		}

		// Get all existing migration files
		$existingFiles = File::glob($migrationPath . '/*.php');

		// Get the current date prefix
		$datePrefix = now()->format('Y_m_d');

		// Find migrations from today
		$todayMigrations = array_filter($existingFiles, function($file) use ($datePrefix) {
			return str_starts_with(basename($file), $datePrefix);
		});


		// Find the highest sequence number
		$sequence = 0;
		foreach ($todayMigrations as $file) {
			$matches = [];
			if (preg_match('/^' . $datePrefix . '_(\d{6})_/', basename($file), $matches)) {
				$sequence = max($sequence, (int) $matches[1]);
			}
		}

		// Generate the next sequence number
		$nextSequence = str_pad($sequence + 10, 6, '0', STR_PAD_LEFT);

		// Create the filename
		$migrationName =  $datePrefix . '_' . $nextSequence . '_create_'.$tableName.'_table.php';

		$headers = $driver->getParameter('headers');
		$filePath = $driver->getParameter('file_path');
		$delimiter = $driver->getParameter('delimiter');

		// Get column types from sample data
		$columnTypes = $this->determineColumnTypes($filePath, $headers, $delimiter);

		// Generate migration content
		$contents = $this->generateMigration($tableName, $columnTypes);

		file_put_contents($migrationPath.'/'.$migrationName, $contents);

		$driver->setParameter('migration_needed', false);
		$driver->setParameter('migration', $migrationName);
	}

	public function canTransition(): bool
	{
		$ret = $this->getDriver()->getParameter('migration_needed');
		return $ret === false;
	}

	public function getNextState(): ?string
	{
		return ExecuteMigrationState::class;
	}


	protected function determineColumnTypes(string $filePath, array $headers, string $delimiter): array
	{
		$driver = $this->getDriver();
		$sampleRows = $driver->getParameter('migration_sample_size', 100);

		$file = new SplFileObject($filePath, 'r');
		$file->setFlags(SplFileObject::READ_CSV);
		$file->setCsvControl($delimiter);

		// Skip header row
		$file->fgetcsv();

		$samples = [];
		$rowCount = 0;

		// Collect samples
		while (!$file->eof() && $rowCount < $sampleRows) {
			$row = $file->fgetcsv();
			if ($row === false || count($row) !== count($headers)) {
				continue;
			}
			$samples[] = array_combine($headers, $row);
			$rowCount++;
		}

		return $this->analyzeColumns($headers, $samples);
	}

	protected function analyzeColumns(array $headers, array $samples): array
	{
		$columnTypes = [];

		foreach ($headers as $header) {
			// Normalize header name
			$normalizedHeader = Str::snake($header);

			// Check predefined rules
			if (isset(self::TYPE_RULES[$normalizedHeader])) {
				$columnTypes[$normalizedHeader] = [
					'type' => self::TYPE_RULES[$normalizedHeader],
					'length' => null
				];
				continue;
			}

			// Analyze samples for this column
			$types = $this->analyzeColumnValues($samples, $header);
			$columnTypes[$normalizedHeader] = $types;
		}

		return $columnTypes;
	}

	protected function analyzeColumnValues(array $samples, string $header): array
	{
		$maxLength = 0;
		$isNumeric = true;
		$isInteger = true;
		$isBoolean = true;
		$hasDecimals = false;
		$maxDecimals = 0;

		foreach ($samples as $row) {
			$value = trim($row[$header]);
			if (empty($value)) continue;

			// Track max length
			$maxLength = max($maxLength, strlen($value));

			// Check numeric
			if (!is_numeric($value)) {
				$isNumeric = false;
				$isInteger = false;
			} elseif ($isNumeric) {
				// Check for decimals
				if (str_contains($value, '.')) {
					$isInteger = false;
					$hasDecimals = true;
					$decimals = strlen(substr(strrchr($value, "."), 1));
					$maxDecimals = max($maxDecimals, $decimals);
				}
			}

			// Check boolean
			if (!in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'])) {
				$isBoolean = false;
			}
		}

		// Determine type
		if ($isBoolean && $maxLength === 1) {
			return ['type' => 'boolean', 'length' => null];
		}

		if ($isInteger) {
			if ($maxLength <= 4) return ['type' => 'smallInteger', 'length' => null];
			if ($maxLength <= 9) return ['type' => 'integer', 'length' => null];
			return ['type' => 'bigInteger', 'length' => null];
		}

		if ($hasDecimals) {
			return ['type' => 'decimal', 'length' => [$maxLength, $maxDecimals]];
		}

		if ($maxLength <= 255) {
			return ['type' => 'string', 'length' => null];
		}

		return ['type' => 'text', 'length' => null];
	}

	protected function generateMigration(string $tableName, array $columnTypes): string
	{
		$file = new PhpFile;
		$file->setStrictTypes();

		$namespace = $file->addNamespace('');

		// Add imports
		$namespace->addUse(Migration::class);
		$namespace->addUse(Blueprint::class);
		$namespace->addUse(Schema::class);
		$namespace->addUse($this->getDriver()->getParameter('model_name'),'Model');

		$class = new \Nette\PhpGenerator\ClassType(null);
		$class->setExtends('Migration');

		/**
		 * return new class extends Migration
		 * {
		 */

		$method = $class->addMethod('getTable')
			->setPublic()
			->setReturnType('string')
			->setBody('return with(new Model())->getTable();');

		// Add up method
		$method = $class->addMethod('up')
			->setPublic()
			->setReturnType('void');

		// Build the schema creation code
		$schemaCode = [];
		$schemaCode[] = '$tableName = $this->getTableName()';
		$schemaCode[] = 'if (Schema::hasTable($tableName)) {';
		$schemaCode[] = "\treturn;";
		$schemaCode[] = '}';
		$schemaCode[] = 'Schema::create($tableName, function (Blueprint $table) {';

		foreach ($columnTypes as $column => $definition) {
			if ($definition['length']) {
				$length = implode(', ', (array)$definition['length']);
				$schemaCode[] = "    \$table->{$definition['type']}('$column', $length);";
			} else {
				$schemaCode[] = "    \$table->{$definition['type']}('$column');";
			}
		}

		// Add timestamps
		$schemaCode[] = "    \$table->timestamps();";
		$schemaCode[] = "});";

		$method->setBody(implode("\n", $schemaCode));

		// Add down method
		$method = $class->addMethod('down')
			->setPublic()
			->setReturnType('void')
			->setBody('Schema::dropIfExists($this->getTableName());');

		$schemaCode = [
			'<?php',
			(string)$namespace,
			(string)'return new class '.$class.';'
		];

		return implode(PHP_EOL, $schemaCode);
	}
}