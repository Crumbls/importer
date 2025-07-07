<?php

namespace Crumbls\Importer\States;

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

        try {
            $sqliteDbPath = $this->getSqliteDbPath($import);

            $connectionName = $this->setupSqliteConnection($sqliteDbPath);

            $import->update([
                'state' => static::class,
                'metadata' => array_merge($import->metadata ?? [], [
                    'sqlite_db_path' => $sqliteDbPath,
                    'sqlite_connection' => $connectionName,
                    'storage_created_at' => now()->toISOString(),
                ])
            ]);

        } catch (\Exception $e) {
	        /**
	         * TODO: Not all states have a FailedState, so throw an exception instead.
	         */
            $import->update([
                'state' => FailedState::class,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            throw $e;
        }
    }

    private function getSqliteDbPath(ImportContract $import): string
    {
        $sqliteDbPath = storage_path("app/imports/wp_import_{$import->getKey()}.sqlite");
        
        $directory = dirname($sqliteDbPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $sqliteDbPath;
    }

    private function setupSqliteConnection(string $sqliteDbPath): string
    {
        $connectionName = 'import_' . uniqid();
        
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

        try {
            DB::connection($connectionName)->statement('CREATE TABLE IF NOT EXISTS connection_test (id INTEGER)');
            DB::connection($connectionName)->statement('DROP TABLE connection_test');
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to establish SQLite connection: " . $e->getMessage());
        }
        
        return $connectionName;
    }

}