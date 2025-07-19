<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as LaravelSchema;
use Illuminate\Support\Facades\DB;

class CreateStorageState extends AbstractState
{
	use AutoTransitionsTrait;

    private function createStorage(ImportContract$import): void
    {
            $metadata = $import->metadata ?? [];
            $updated = false;

            if (!isset($metadata['storage_driver']) || !$metadata['storage_driver']) {
                $metadata['storage_driver'] = Storage::getDefaultDriver();
            }

            if (!isset($metadata['storage_connection']) || !$metadata['storage_connection']) {
                $metadata['storage_connection'] = 'import_' . uniqid();
                $updated = true;
            }

            $storeName = $this->getStoreName($import);

            if (!isset($metadata['storage_path']) || !$metadata['storage_path']) {
                // No storage path set, create new storage
                $storage = Storage::driver($metadata['storage_driver'])
                    ->createOrFindStore($storeName);

                $metadata['storage_path'] = $storage->getStorePath();
                $metadata['storage_created_at'] = now()->toISOString();
                $updated = true;
            } else {
                // Storage path exists in metadata, check if the actual file/directory exists
                $existingPath = $metadata['storage_path'];
                
                if (!file_exists($existingPath)) {
                    // Path doesn't exist, create new storage
                    $storage = Storage::driver($metadata['storage_driver'])
                        ->createOrFindStore($storeName);

                    $metadata['storage_path'] = $storage->getStorePath();
                    $metadata['storage_created_at'] = now()->toISOString();
                    $updated = true;
                } else {
                    // Path exists, verify it's accessible and writable
                    if (!is_writable($existingPath) || !is_readable($existingPath)) {
                        // Path exists but not accessible, create new storage
                        $storage = Storage::driver($metadata['storage_driver'])
                            ->createOrFindStore($storeName);

                        $metadata['storage_path'] = $storage->getStorePath();
                        $metadata['storage_created_at'] = now()->toISOString();
                        $updated = true;
                    }
                    // Path exists and is accessible, use existing storage
                }
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
                    'metadata' => $metadata
                ]);
            }

            // Test the connection
            DB::connection($connectionName)->statement('CREATE TABLE IF NOT EXISTS connection_test (id INTEGER)');
            DB::connection($connectionName)->statement('DROP TABLE connection_test');

    }
    
    public function execute(): bool
    {
        $record = $this->getRecord();
        
	    $this->createStorage($record);

	    $this->transitionToNextState($record);

		return true;
    }

    private function getStoreName(ImportContract $import): string
    {
        return "import_{$import->getKey()}";
    }

}