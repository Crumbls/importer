<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Models\Contracts\ImportContract;
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
    // Filament UI Implementation
    public function getFilamentTitle(ImportContract $record): string
    {
        return 'Setting Up Storage';
    }

    public function getFilamentHeading(ImportContract $record): string
    {
        return 'Creating Temporary Storage';
    }

    public function getFilamentSubheading(ImportContract $record): ?string
    {
        return 'Setting up optimized SQLite database for processing your import...';
    }

    public function hasFilamentForm(): bool
    {
        return true;
    }

    public function getFilamentForm(Schema $schema, ImportContract $record): Schema
    {
        return $schema->schema([]);
    }

    public function getFilamentHeaderActions(ImportContract $record): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(fn() => route('filament.admin.resources.imports.index')),
        ];
    }

    public function handleFilamentFormSave(array $data, $record): void
    {
        // This method is called when the form is auto-submitted
        $this->createStorage($record);
        $this->transitionToNextState($record);
    }

    public function handleFilamentSaveComplete($page): void
    {
        // The transition already happened in handleFilamentFormSave
        // Just refresh the page to show the new state
        $page->redirect($page->getResourceUrl('step', ['record' => $page->record]));
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

    }

    public function getFilamentAutoSubmitDelay(): int
    {
        return 2000; // 2 seconds
    }

    public function onEnter(): void
    {
        // Don't run storage creation in onEnter anymore - let the UI handle it
        // This allows the user to see the storage creation progress
    }

    private function getStoreName(ImportContract $import): string
    {
        return "import_{$import->getKey()}";
    }

    // Polling-based workflow for storage creation monitoring
    public function getFilamentPollingInterval(): ?int
    {
        return 1000; // Poll every 1 second during storage creation
    }

    public function shouldAutoTransition(ImportContract $record): bool
    {
        // Check if storage has been created successfully
        $metadata = $record->metadata ?? [];
        return isset($metadata['storage_path']) && 
               isset($metadata['storage_created_at']) && 
               file_exists($metadata['storage_path']);
    }
}