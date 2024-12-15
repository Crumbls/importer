<?php

namespace Crumbls\Importer\Traits;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

trait HasImportConnection {
	public function getImportConnectionName() : ?string {
dd(__LINE__);
		$record = $this->getRecord();
		$md = $record->metadata ?? [];
		$dbName = $md['db_connection_name'] ?? null;
	}
	protected function configureConnection(): void {
		$record = $this->getRecord();
		$md = $record->metadata ?? [];
		$dbPath = $md['db_path'] ?? null;

		if (!$dbPath || !file_exists($dbPath)) {
			throw new \Exception("Database not found for import record");
		}

		dd($dbPath);
		Config::set('database.connections.wordpress_import', [
			'driver' => 'sqlite',
			'database' => $dbPath,
			'prefix' => '',
			'foreign_key_constraints' => true
		]);
	}

	public function getImportConnection(): ConnectionInterface {
		$this->configureConnection();
		return DB::connection('wordpress_import');
	}
}