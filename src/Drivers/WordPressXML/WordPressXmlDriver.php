<?php

namespace Crumbls\Importer\Drivers\WordPressXML;

use Crumbls\Importer\Contracts\ColumnMappingInterface;
use Crumbls\Importer\Contracts\DriverInterface;
use Crumbls\Importer\Drivers\AbstractDriver;
use Crumbls\Importer\Drivers\Common\States\CompleteState;
use Crumbls\Importer\Drivers\Common\States\CreateFilamentResourcesState;
use Crumbls\Importer\Drivers\Common\States\DatabaseToDatabaseState;
use Crumbls\Importer\Drivers\Common\States\DatabaseToMigrationState;
use Crumbls\Importer\Drivers\Common\States\DatabaseToModelState;
use Crumbls\Importer\Drivers\Common\States\ExecuteMigrations;
use Crumbls\Importer\Drivers\WordPress\States\MapModelsState;
use Crumbls\Importer\Drivers\WordPressSql\States\DetermineTablePrefixState;
use Crumbls\Importer\Drivers\WordPressXML\States\ConvertToDatabaseState;
use Crumbls\Importer\Drivers\WordPressXML\States\InitializeState;
use Crumbls\Importer\Drivers\WordPressXML\States\MapPostTypesState;
use Crumbls\Importer\Drivers\WordPressXML\States\ValidateState;
use Crumbls\Importer\States\CreateMigrationsState;
use Crumbls\Importer\States\CreateModelsState;
use Crumbls\Importer\Traits\HasColumnMapping;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class WordPressXmlDriver extends AbstractDriver implements DriverInterface //, ColumnMappingInterface
{


	public static function getRegisteredStates(): array {
		return [
			ValidateState::class,
			ConvertToDatabaseState::class,
			MapPostTypesState::class,
			MapModelsState::class,
			DatabaseToModelState::class,
			DatabaseToMigrationState::class,
			DatabaseToDatabaseState::class,
			CompleteState::class
		];
	}

	public static function getRegisteredTransitions(): array {
		return [
			ValidateState::class => [
				ConvertToDatabaseState::class
			],
			ConvertToDatabaseState::class => [
				DetermineTablePrefixState::class
			],
			DetermineTablePrefixState::class => [
				MapPostTypesState::class
			],
			MapPostTypesState::class => [
				MapModelsState::class
			],
			MapModelsState::class => [
				DatabaseToModelState::class,
			],
			DatabaseToModelState::class => [
				DatabaseToMigrationState::class
			],
			DatabaseToMigrationState::class => [
				ExecuteMigrations::class
			],
			ExecuteMigrations::class => [
				CreateFilamentResourcesState::class
			],
			CreateFilamentResourcesState::class => [
				DatabaseToDatabaseState::class
			],
			DatabaseToDatabaseState::class => [
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