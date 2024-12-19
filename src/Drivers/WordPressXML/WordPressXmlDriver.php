<?php

namespace Crumbls\Importer\Drivers\WordPressXML;

use Crumbls\Importer\Contracts\ColumnMappingInterface;
use Crumbls\Importer\Contracts\DriverInterface;
use Crumbls\Importer\Drivers\AbstractDriver;
use Crumbls\Importer\Drivers\WordPressXML\States\CompleteState;
use Crumbls\Importer\Drivers\WordPressXML\States\ConvertToDatabaseState;
use Crumbls\Importer\Drivers\WordPressXML\States\InitializeState;
use Crumbls\Importer\Drivers\WordPressXML\States\MapPostTypesState;
use Crumbls\Importer\Drivers\WordPressXML\States\ValidateState;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\States\CreateFilamentResourcesState;
use Crumbls\Importer\States\CreateMigrationsState;
use Crumbls\Importer\States\CreateModelsState;
use Crumbls\Importer\Traits\HasColumnMapping;
use Crumbls\Importer\Traits\HasImportConnection;
use Crumbls\Importer\Traits\HasWordPressColumns;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class WordPressXmlDriver extends AbstractDriver implements DriverInterface //, ColumnMappingInterface
{

	public static function getRegisteredStates(): array {
		return [
			ValidateState::class,
			ConvertToDatabaseState::class,
			MapPostTypesState::class,
			CreateModelsState::class,
			CompleteState::class
		];
	}

	public static function getRegisteredTransitions(): array {
		return [
			ValidateState::class => [
				ConvertToDatabaseState::class
			],
			ConvertToDatabaseState::class => [
				MapPostTypesState::class
				],
			MapPostTypesState::class => [
				CreateModelsState::class
			],
			CreateModelsState::class => [
				CreateMigrationsState::class
			],
			CreateMigrationsState::class => [
				CreateFilamentResourcesState::class
			],
			CreateFilamentResourcesState::class => [
				CompleteState::class
			]
		];
	}



	/**
	 * Create new driver with optional config
	 */

	/**
	 * Get the name of the driver
	 */
	public static function getName(): string
	{
		return 'wordpress-xml';
	}

	protected function configureConnection(): void {
		$record = $this->getRecord();
		$md = $record->metadata ?? [];
		$dbPath = $md['db_path'] ?? null;

		if (!$dbPath || !file_exists($dbPath)) {
			throw new \Exception("Database not found for import record");
		}

		$connectionName = $this->getImportConnectionName();

		Config::set('database.connections.'.$connectionName, [
			'driver' => 'sqlite',
			'database' => $dbPath,
			'prefix' => '',
			'foreign_key_constraints' => true
		]);
	}

	public function getImportConnectionName(): string|null
	{
		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		if (!array_key_exists('db_connection_name', $md) || !$md['db_connection_name'] || !is_string($md['db_connection_name'])) {
			$md['db_connection_name'] = 'wp_import_'.$record->getKey();
			$record->update([
				'metadata' => $md
			]);
		}


		return $md['db_connection_name'] ?? null;
	}

	public function getImportConnection(): ConnectionInterface {
		$this->configureConnection();

		return DB::connection($this->getImportConnectionName());
	}
}