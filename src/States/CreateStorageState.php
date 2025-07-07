<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateStorageState extends AbstractState
{
    public function onEnter(): void
    {
        $import = $this->getImport();

        if (!$import instanceof ImportContract) {
            throw new \RuntimeException('Import contract not found in context');
        }

		$metadata = $import->metadata ?? [];
		$updated = false;

		if (!isset($metadata['storage_driver']) || !$metadata['storage_driver']) {
			$metadata['storage_driver'] = Storage::getDefaultDriver();
		}

	    if (!isset($metadata['storage_connection']) || !$metadata['storage_connection']) {
		    $metadata['storage_connection'] = 'import_' . uniqid();
		    $updated = true;
	    }

	    if (!isset($metadata['storage_path']) || !$metadata['storage_path']) {
			$storeName = $this->getStoreName($import);

			$storage = Storage::driver($metadata['storage_driver'])
				->createOrFindStore($storeName);

			$metadata['storage_path'] = $storage->getStorePath();
			$metadata['storage_created_at'] = now()->toISOString();
			$updated = true;
		}

		$sqliteDbPath = $metadata['storage_path'];
		$connectionName = $metadata['storage_connection'];

	    // Ensure SQLite file exists
	    if (!file_exists($sqliteDbPath)) {
		    touch($sqliteDbPath);
	    }

	    // Add SQLite connection to Laravel's database config
	    config([
		    "database.connections.{$connectionName}" => [
			    'driver' => 'sqlite',
			    'database' => $sqliteDbPath,
			    'prefix' => '',
			    'foreign_key_constraints' => true,
		    ]
	    ]);

		if ($updated) {
			$import->update([
				'state' => static::class,
				'metadata' => $metadata
			]);
		} else {
			$import->update([
				'state' => static::class
			]);
		}

		DB::connection($connectionName)->statement('CREATE TABLE IF NOT EXISTS connection_test (id INTEGER)');
		DB::connection($connectionName)->statement('DROP TABLE connection_test');
	}

	private function getStoreName(ImportContract $import): string {
		return "import_{$import->getKey()}";
	}

}