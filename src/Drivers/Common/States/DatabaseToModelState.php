<?php


namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\Models\ImportLog;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ModelAnalyzer;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use ReflectionClass;

/**
 * A state to create models from a database.
 */
class DatabaseToModelState extends AbstractState
{
	private array $composerJson;
	private ModelAnalyzer $analyzer;
	public function getName(): string
	{
		return 'database-to-model';
	}

	public function handle(): void
	{
		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$md['transformers'] = $md['transformers'] ?? [];

		// a

		$this->composerJson = json_decode(file_get_contents(base_path('composer.json')), true);

		if (!isset($this->composerJson['autoload']['psr-4'])) {
			throw new \RuntimeException("No PSR-4 autoload configuration found in composer.json");
		}
		// b


		foreach ($md['transformers'] as $transformer) {
			$this->generate($transformer);
		}
	}

	protected function generate(array $transformer): void
	{
		if (class_exists($transformer['model_name'])) {
			dump('Class already exists: ' . $transformer['model_name']);
			$this->verifyExistingModel($transformer);
			return;
		}

		$destination = $this->resolveClassPath($transformer['model_name']);

		if (file_exists($destination)) {
			dump('File already exists: ' . $destination);
			return;
		}

		$file = new PhpFile;
		$file->setStrictTypes();

		$namespace = $file->addNamespace($this->getNamespaceFromModelName($transformer['model_name']));

		// Add imports
		$namespace->addUse(\Illuminate\Database\Eloquent\Model::class);

//		$namespace->addUse(\Illuminate\Database\Eloquent\Factories\HasFactory::class);

		// Check if we have any datetime fields
		if ($this->hasDateTimeFields($transformer['types'])) {
			$namespace->addUse(\Carbon\Carbon::class);
		}

		$class = $namespace->addClass($this->getClassNameFromModelName($transformer['model_name']));
		$class->setExtends('Illuminate\Database\Eloquent\Model')
			->addTrait('Illuminate\Database\Eloquent\Factories\HasFactory');

// Add table property
		$class->addProperty('table')
			->setProtected()
			->setValue($transformer['to_table']);

// Add fillable property with new column names
		$fillable = array_values($transformer['mappings']);
		$class->addProperty('fillable')
			->setProtected()
			->setValue($fillable);

// Add property type hints using PHP 8 property promotion syntax
		$this->addPropertyTypes($class, $transformer);

// Add casts for datetime fields
		$casts = $this->generateCasts($transformer);

		if (!empty($casts)) {
			$class->addProperty('casts')
				->setProtected()
				->setValue($casts);
		}

// Generate the file
		$printer = new PsrPrinter;
		$output = $printer->printFile($file);

// Create directory if it doesn't exist
		$directory = dirname($destination);

		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

// Save the file
		file_put_contents(
			$destination,
			$output
		);
	}

	private function getNamespaceFromModelName(string $modelName): string
	{
		return implode('\\', array_slice(explode('\\', $modelName), 0, -1));
	}

	private function getClassNameFromModelName(string $modelName): string
	{
		return class_basename($modelName);
	}

	private function getModelPath(string $modelName): string
	{
		return str_replace('\\', '/', str_replace('App\\', '', $modelName)) . '.php';
	}

	private function hasDateTimeFields(array $types): bool
	{
		foreach ($types as $type) {
			if ($type['type'] === 'datetime') {
				return true;
			}
		}
		return false;
	}

	private function addPropertyTypes(ClassType $class, array $transformer): void
	{
		$docBlock = [];

		foreach ($transformer['mappings'] as $oldColumn => $newColumn) {
			if (isset($transformer['types'][$oldColumn])) {
				$type = $transformer['types'][$oldColumn]['type'];
				$phpType = $this->typeMap[$type] ?? 'mixed';

				// Add nullable indicator for non-primary keys
				if ($type !== 'bigIncrements' && $type !== 'increments') {
					$phpType .= '|null';
				}

				$docBlock[] = "@property {$phpType} \${$newColumn}";
			}
		}

		if (!empty($docBlock)) {
			$class->addComment(implode("\n", $docBlock));
		}
	}

	private function generateCasts(array $transformer): array
	{
		$casts = [];

		foreach ($transformer['mappings'] as $oldColumn => $newColumn) {
			if (isset($transformer['types'][$oldColumn])) {
				$type = $transformer['types'][$oldColumn]['type'];

				if ($type === 'datetime') {
					$casts[$newColumn] = 'datetime';
				}
			}
		}

		return $casts;
	}

	public function resolveClassPath(string $fullyQualifiedClassName): string
	{
		// Remove leading backslash if present
		$fullyQualifiedClassName = ltrim($fullyQualifiedClassName, '\\');

		// Get namespace and class name
		$lastBackslash = strrpos($fullyQualifiedClassName, '\\');
		if ($lastBackslash === false) {
			throw new \InvalidArgumentException("Class name must include namespace");
		}

		$namespace = substr($fullyQualifiedClassName, 0, $lastBackslash);
		$className = substr($fullyQualifiedClassName, $lastBackslash + 1);

		// Look through PSR-4 autoload mappings
		foreach ($this->composerJson['autoload']['psr-4'] as $prefix => $path) {
			$prefix = rtrim($prefix, '\\');
			if (strpos($namespace, $prefix) === 0) {
				// Found matching namespace prefix
				$relativePath = str_replace('\\', '/', substr($namespace, strlen($prefix)));
				$basePath = rtrim($path, '/');

				return str_replace('//', '/', sprintf(
					'%s/%s/%s/%s.php',
					base_path(),
					$basePath,
					$relativePath,
					$className
				));
			}
		}

		throw new \RuntimeException("No matching PSR-4 autoload configuration found for namespace: {$namespace}");
	}




	public function __construct($driver)
	{
		parent::__construct($driver);
		$this->analyzer = new ModelAnalyzer();
	}


	protected function verifyExistingModel(array $transformer): void
	{
		$modelClass = $transformer['model_name'];
		$instance = new $modelClass();
		$reflection = new ReflectionClass($modelClass);

		$issues = [];

		// Check table name
		if ($instance->getTable() !== $transformer['to_table']) {
			$issues[] = [
				'type' => 'table_mismatch',
				'message' => "Table name mismatch. Expected: {$transformer['to_table']}, Got: {$instance->getTable()}",
				'severity' => 'warning'
			];
		}

		// Check fillable fields
		$missingFillable = array_diff(
			array_values($transformer['mappings']),
			$instance->getFillable()
		);
		if (!empty($missingFillable)) {
			$issues[] = [
				'type' => 'missing_fillable',
				'message' => 'Missing fillable fields: ' . implode(', ', $missingFillable),
				'severity' => 'warning'
			];
		}

		// Check property types
		foreach ($transformer['mappings'] as $oldColumn => $newColumn) {
			if (isset($transformer['types'][$oldColumn])) {
				$expectedType = $this->getPhpType($transformer['types'][$oldColumn]);
				$actualType = $this->analyzer->getPropertyType($reflection, $newColumn);

				if ($actualType && $actualType !== $expectedType) {
					$issues[] = [
						'type' => 'type_mismatch',
						'message' => "Property type mismatch for {$newColumn}. Expected: {$expectedType}, Got: {$actualType}",
						'severity' => 'error'
					];
				}
			}
		}

		// Check casts
		$expectedCasts = $this->generateExpectedCasts($transformer);
		$actualCasts = $instance->getCasts();
		$castDiff = array_diff_assoc($expectedCasts, $actualCasts);

		/**
		 * Remove created_at and updated_at datetimes.
		 */
		if (array_key_exists('created_at', $castDiff) && $castDiff['created_at'] == 'datetime') {
			unset($castDiff['created_at']);
		}
		if (array_key_exists('updated_at', $castDiff) && $castDiff['updated_at'] == 'datetime') {
			unset($castDiff['updated_at']);
		}

		if (!empty($castDiff)) {
			$issues[] = [
				'type' => 'cast_mismatch',
				'message' => 'Cast mismatches found: ' . json_encode($castDiff),
				'severity' => 'warning'
			];
		}

		if ($issues) {
			dump($issues);
		}

		// Log all issues
		$this->logModelIssues($transformer['model_name'], $issues);
	}

	protected function getPhpType(array $columnDefinition): string
	{
		return match ($columnDefinition['type']) {
			'bigIncrements', 'unsignedBigInteger' => 'int',
			'boolean' => 'bool',
			'datetime' => '\Carbon\Carbon',
			'decimal', 'float' => 'float',
			'integer' => 'int',
			'json' => 'array',
			default => 'string',
		};
	}

	protected function generateExpectedCasts(array $transformer): array
	{
		$casts = [];
		foreach ($transformer['mappings'] as $oldColumn => $newColumn) {
			if (isset($transformer['types'][$oldColumn])) {
				$type = $transformer['types'][$oldColumn]['type'];
				if ($this->shouldBeCasted($type)) {
					$casts[$newColumn] = $this->getCastType($type);
				}
			}
		}
		return $casts;
	}

	protected function shouldBeCasted(string $type): bool
	{
		return in_array($type, ['datetime', 'json', 'boolean', 'decimal', 'encrypted']);
	}

	protected function getCastType(string $type): string
	{
		return match ($type) {
			'datetime' => 'datetime',
			'json' => 'array',
			'boolean' => 'boolean',
			'decimal' => 'decimal:2',
			'encrypted' => 'encrypted',
			default => 'string'
		};
	}

	protected function logModelIssues(string $modelName, array $issues): void
	{
		if (empty($issues)) {
			return;
		}

		$record = $this->getRecord();

		foreach ($issues as $issue) {
			ImportLog::{$this->getLogLevel($issue['severity'])}(
				$record,
				"Model Verification Issue ({$modelName}): {$issue['message']}",
				[
					'model' => $modelName,
					'issue_type' => $issue['type'],
					'severity' => $issue['severity']
				]
			);
		}
	}

	protected function getLogLevel(string $severity): string
	{
		return match ($severity) {
			'error' => 'error',
			'warning' => 'warning',
			default => 'info'
		};
	}


}