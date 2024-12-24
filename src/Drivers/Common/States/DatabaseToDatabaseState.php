<?php


namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\Models\ImportLog;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ModelAnalyzer;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsComposerAware;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use Crumbls\Importer\Transformers\TransformationManager;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use ReflectionClass;

use Crumbls\Importer\Facades\TransformationManagerFacade as Transformer;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * A state to create models from a database.
 */
class DatabaseToDatabaseState extends AbstractState
{

	use IsTableSchemaAware,
		HasTransformerDefinition;
	protected const BATCH_SIZE = 1000;


	public function getName(): string
	{
		return 'database-to-database';
	}

	public function handle(): void
	{
		$record = $this->getRecord();
		$md = $record->metadata ?? [];
		$transformers = $md['transformers'] ?? [];

		\DB::enableQueryLog();
		foreach ($transformers as $transformer) {
			$this->migrateData($transformer);
		}
		dump(\DB::getQueryLog());
	}

	protected function migrateData(array $transformer): void
	{
		$sourceConnection = $this->getDriver()->getImportConnection();
		$destinationConnection = DB::connection();

		// Get the actual table names without duplicate prefixes
		$sourceTable = $this->getActualTableName($transformer['from_table'], $sourceConnection);
//		$sourceTable = $this->getActualTableName($sourceTable, $sourceConnection);
//		dd($sourceTable);
		$destinationTable = $transformer['to_table'];

		// Start transaction on destination
		$destinationConnection->beginTransaction();

		try {
			// Get total count for chunking using correct table name

			$total = $sourceConnection->table($sourceTable)->count();

			// Process in chunks
			for ($offset = 0; $offset < $total; $offset += static::BATCH_SIZE) {
				$records = $this->getSourceRecords($sourceConnection, $sourceTable, $transformer, $offset);
				$transformedRecords = $this->transformRecords($records, $transformer);

				if (!empty($transformedRecords)) {
					dump($transformedRecords);
					$destinationConnection->table($destinationTable)
						->insert($transformedRecords);
				} else {
					dump(__LINE__);
				}

				// Log progress
				$this->logProgress($destinationTable, $offset, $total);
			}

			$destinationConnection->commit();
		} catch (\Exception $e) {
			$destinationConnection->rollBack();
dump($e);
/*
			// Log the error
			ImportLog::error(
				$this->getRecord(),
				"Failed to migrate data for table {$destinationTable}: " . $e->getMessage(),
				[
					'transformer' => $transformer,
					'exception' => $e
				]
			);
*/
			throw $e;
		}
	}
	protected function getSourceRecords($sourceConnection, string $sourceTable, array $transformer, int $offset): Collection
	{
		$query = $sourceConnection->table($sourceTable);

		// Apply any source filters if defined
		if (isset($transformer['source_filters']) && is_array($transformer['source_filters'])) {
			foreach ($transformer['source_filters'] as $filter) {
				$this->applyFilter($query, $filter);
			}
		}

		return $query->skip($offset)
			->take(static::BATCH_SIZE)
			->get();
	}

	/**
	 * Get the actual table name without duplicate prefixes
	 */
	protected function getActualTableName(string $tableName, $connection): string
	{
		$prefix = $connection->getTablePrefix();

		return $prefix ? Str::chopStart($tableName, $prefix) : $tableName;
	}

	protected function transformRecords(Collection $records, array $transformer): array
	{
		return $records->map(function ($record) use ($transformer) {
			try {
				$transformedRecord = [];

				foreach ($transformer['mappings'] as $fromColumn => $toColumn) {
					// Skip excluded columns
					if (in_array($toColumn, $transformer['excluded_columns'] ?? [])) {
						continue;
					}

					try {
						// Get original value, allowing for nested keys using dot notation
						$value = data_get($record, $fromColumn);

						// Apply transformations if defined
						if (isset($transformer['transformations'][$fromColumn])) {
							try {
								$value = Transformer::transformMany(
									$value,
									$transformer['transformations'][$fromColumn]
								);
							} catch (\Exception $e) {
								ImportLog::warning(
									$this->getRecord(),
									"Transform failed for column {$fromColumn}: {$e->getMessage()}",
									[
										'column' => $fromColumn,
										'value' => $value,
										'transformations' => $transformer['transformations'][$fromColumn],
										'exception' => $e->getMessage()
									]
								);

								// Use original value if transformation fails
								// Or you could set to null based on your requirements
								// $value = null;
							}
						}

						// Apply type casting if defined
						if (isset($transformer['types'][$fromColumn])) {
							try {
								$value = $this->castValue($value, $transformer['types'][$fromColumn]);
							} catch (\Exception $e) {
								ImportLog::warning(
									$this->getRecord(),
									"Type casting failed for column {$fromColumn}: {$e->getMessage()}",
									[
										'column' => $fromColumn,
										'value' => $value,
										'type' => $transformer['types'][$fromColumn],
										'exception' => $e->getMessage()
									]
								);
							}
						}

						// Apply default value if value is null and default is specified
						if ($value === null && isset($transformer['defaults'][$toColumn])) {
							$value = $transformer['defaults'][$toColumn];
						}

						// Handle required fields
						if ($value === null && isset($transformer['required']) && in_array($fromColumn, $transformer['required'])) {
							throw new \Exception("Required field {$fromColumn} is null");
						}

						$transformedRecord[$toColumn] = $value;

					} catch (\Exception $e) {
						ImportLog::error(
							$this->getRecord(),
							"Failed to transform column {$fromColumn}: {$e->getMessage()}",
							[
								'column' => $fromColumn,
								'record_id' => $record->id ?? null,
								'exception' => $e->getMessage()
							]
						);

						// If this is a required field, we might want to skip the entire record
						if (isset($transformer['required']) && in_array($fromColumn, $transformer['required'])) {
							throw $e;
						}
					}
				}

				// Add any additional default values for columns not in mapping
				if (isset($transformer['defaults'])) {
					foreach ($transformer['defaults'] as $column => $default) {
						if (!isset($transformedRecord[$column])) {
							$transformedRecord[$column] = $default;
						}
					}
				}

				// Apply any post-transform validations
				if (isset($transformer['validations'])) {
					foreach ($transformer['validations'] as $validation) {
						$result = $this->validateTransformedRecord($transformedRecord, $validation);
						if (!$result['valid']) {
							ImportLog::warning(
								$this->getRecord(),
								"Validation failed: {$result['message']}",
								[
									'record' => $transformedRecord,
									'validation' => $validation
								]
							);

							if ($validation['strict'] ?? false) {
								return null; // Skip this record
							}
						}
					}
				}

				// Apply any post-processing transformations
				if (isset($transformer['post_process']) && is_callable($transformer['post_process'])) {
					try {
						$transformedRecord = $transformer['post_process']($transformedRecord, $record);
					} catch (\Exception $e) {
						ImportLog::error(
							$this->getRecord(),
							"Post-processing failed: {$e->getMessage()}",
							[
								'record' => $transformedRecord,
								'exception' => $e->getMessage()
							]
						);
						return null; // Skip this record
					}
				}

				return $transformedRecord;

			} catch (\Exception $e) {
				ImportLog::error(
					$this->getRecord(),
					"Failed to transform record: {$e->getMessage()}",
					[
						'record_id' => $record->id ?? null,
						'exception' => $e->getMessage()
					]
				);
				return null; // Skip this record entirely
			}
		})->filter()->all(); // Remove any null records
	}

	/**
	 * Validate a transformed record
	 */
	protected function validateTransformedRecord(array $record, array $validation): array
	{
		$type = $validation['type'] ?? 'callback';
		$field = $validation['field'] ?? null;

		try {
			$valid = match($type) {
				'required' => isset($record[$field]) && $record[$field] !== null,
				'not_empty' => !empty($record[$field]),
				'regex' => $field && isset($record[$field]) &&
					preg_match($validation['pattern'], $record[$field]),
				'in' => $field && isset($record[$field]) &&
					in_array($record[$field], $validation['values'] ?? []),
				'callback' => $validation['callback']($record),
				default => true
			};

			return [
				'valid' => $valid,
				'message' => $valid ? '' : ($validation['message'] ?? 'Validation failed')
			];
		} catch (\Exception $e) {
			return [
				'valid' => false,
				'message' => "Validation error: {$e->getMessage()}"
			];
		}
	}

	protected function applyFilter(Builder $query, array $filter): void
	{
		$method = $filter['method'] ?? 'where';
		$params = $filter['parameters'] ?? [];

		$query->{$method}(...$params);
	}

	protected function castValue($value, array $type): mixed
	{
		if ($value === null) {
			return null;
		}

		return match($type['type']) {
			'integer', 'bigIncrements', 'unsignedBigInteger' => (int) $value,
			'float', 'decimal' => (float) $value,
			'boolean' => (bool) $value,
			'datetime' => date('Y-m-d H:i:s', is_numeric($value) ? $value : strtotime($value)),
			'date' => date('Y-m-d', is_numeric($value) ? $value : strtotime($value)),
			'json' => is_string($value) ? json_decode($value, true) : $value,
			default => (string) $value
		};
	}

	protected function logProgress(string $table, int $processed, int $total): void
	{
		$percentage = round(($processed / $total) * 100, 2);

		ImportLog::info(
			$this->getRecord(),
			"Migrating data for table {$table}: {$percentage}% complete",
			[
				'table' => $table,
				'processed' => $processed,
				'total' => $total,
				'percentage' => $percentage
			]
		);
	}

	protected function validateTransformer(array $transformer): void
	{
		$required = ['from_table', 'to_table', 'mappings'];

		foreach ($required as $field) {
			if (!isset($transformer[$field])) {
				throw new \InvalidArgumentException("Missing required transformer field: {$field}");
			}
		}

		if (!is_array($transformer['mappings'])) {
			throw new \InvalidArgumentException("Transformer mappings must be an array");
		}

		// Validate all transformations if present
		if (isset($transformer['transformations'])) {
			foreach ($transformer['transformations'] as $column => $transformations) {
				if (!is_array($transformations)) {
					throw new \InvalidArgumentException(
						"Transformations for column {$column} must be an array"
					);
				}

				foreach ($transformations as $transformation) {
					if (!isset($transformation['type'])) {
						throw new \InvalidArgumentException(
							"Transformation type must be specified for column {$column}"
						);
					}
				}
			}
		}
	}

}