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

    /**
     * Use the form page for auto-transitions
     */
    public function getRecommendedPageClass(): string
    {
        return GenericFormPage::class;
    }

    // Filament UI Implementation
    public function getTitle(ImportContract $record): string
    {
        return 'Setting Up Storage';
    }

    public function getHeading(ImportContract $record): string
    {
        return 'Creating Temporary Storage';
    }

    public function getSubheading(ImportContract $record): ?string
    {
        return 'Setting up optimized SQLite database for processing your import...';
    }

    public function hasFilamentForm(): bool
    {
        return true;
    }

    public function buildForm(Schema $schema, ImportContract $record): Schema
    {
        return $schema->schema([]);
    }

    public function getHeaderActions(ImportContract $record): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(fn() => route('filament.admin.resources.imports.index')),
        ];
    }

    public function handleSave(array $data, ImportContract $record): void
    {
        // This method is called when the form is auto-submitted
        $this->createStorage($record);
        $this->transitionToNextState($record);
    }

    private function createStorage($import): void
    {
        try {
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

            Notification::make()
                ->title('Storage Created Successfully!')
                ->body('Temporary SQLite database is ready for processing.')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Storage Creation Failed')
                ->body('Failed to create storage: ' . $e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    protected function transitionToNextState($record): void
    {
        try {
            // Get the driver and its preferred transitions
            $driver = $record->getDriver();
            $config = $driver->config();
            
            // Get the next preferred state from current state
            $nextState = $config->getPreferredTransition(static::class);
            
            if ($nextState) {
                // Get the state machine and transition
                $stateMachine = $record->getStateMachine();
                $stateMachine->transitionTo($nextState);
                
                // Update the record with new state
                $record->update(['state' => $nextState]);
                
                Notification::make()
                    ->title('Storage Created!')
                    ->body('Temporary storage created successfully.')
                    ->success()
                    ->send();
            } else {
                throw new \Exception('No preferred transition found from CreateStorageState');
            }
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Transition Failed')
                ->body('Failed to proceed to next state: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function onEnter(): void
    {
        // For auto-transitions, we need to create storage immediately
        if ($this->hasAutoTransition()) {
            $this->performStorageCreation();
        }
    }
    
    protected function performStorageCreation(): void
    {
        $import = $this->getImport();
        
        // Create the storage
        $storeName = $this->getStoreName($import);
        $storage = Storage::driver('sqlite')->createOrFindStore($storeName);
        
        // Update metadata with storage driver info
        $metadata = $import->metadata ?? [];
        $metadata['storage_driver'] = 'sqlite';
        $metadata['storage_path'] = $storage->getStorePath();
        $metadata['storage_connection'] = 'import_' . uniqid();
        $metadata['storage_created_at'] = now()->toISOString();
        
        $import->update(['metadata' => $metadata]);
    }
    
    /**
     * Override shouldAutoTransition to check if storage creation is complete
     */
    public function shouldAutoTransition(ImportContract $record): bool
    {
        if (!$this->hasAutoTransition()) {
            return false;
        }
        
        // Check if storage was created successfully
        $metadata = $record->metadata ?? [];
        $storageCreated = isset($metadata['storage_driver']) && !empty($metadata['storage_driver']);
        
        if (!$storageCreated) {
            return false;
        }

		return true;
    }

    private function getStoreName(ImportContract $import): string
    {
        return "import_{$import->getKey()}";
    }

}