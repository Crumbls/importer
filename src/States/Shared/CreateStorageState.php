<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\Console\Prompts\Shared\CreateStoragePrompt;
use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\Concerns\HasStorageDriver;
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
	use HasStorageDriver;

    private function createStorage(ImportContract $import): void
    {
        // Set default storage driver if not already set
        if (!$this->hasStorageDriver()) {
            $this->setStorageDriver(Storage::getDefaultDriver(), [
                'storage_connection' => 'import_' . uniqid(),
                'storage_created_at' => now()->toISOString(),
            ]);
        }

        $storeName = $this->getStoreName($import);
        
        // Create or find the storage store using the concern
        $storage = $this->createOrFindStorageStore($storeName);
        
        // Update metadata with storage path if needed
        $metadata = $import->metadata ?? [];
        if (!isset($metadata['storage_path']) || $metadata['storage_path'] !== $storage->getStorePath()) {
            $metadata['storage_path'] = $storage->getStorePath();
            $metadata['storage_updated_at'] = now()->toISOString();
            $import->update(['metadata' => $metadata]);
        }

        // Set up Laravel database connection for SQLite
        $sqliteDbPath = $storage->getStorePath();
        $connectionName = $metadata['storage_connection'] ?? 'import_' . uniqid();

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

        // Test the connection
        DB::connection($connectionName)->statement('CREATE TABLE IF NOT EXISTS connection_test (id INTEGER)');
        DB::connection($connectionName)->statement('DROP TABLE connection_test');
    }

	public function onEnter() : void {

	}

    public function execute(): bool
    {
        $record = $this->getRecord();
        
	    $this->createStorage($record);

	    $this->transitionToNextState($record);

		return true;
    }

	public function onExit() : void {

	}

    private function getStoreName(ImportContract $import): string
    {
        return "import_{$import->getKey()}";
    }

	public function getPromptClass() : string {
		return CreateStoragePrompt::class;
	}
}