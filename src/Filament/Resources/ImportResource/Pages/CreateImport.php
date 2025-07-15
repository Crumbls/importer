<?php

namespace Crumbls\Importer\Filament\Resources\ImportResource\Pages;

use Crumbls\Importer\Filament\Resources\ImportResource;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Services\ModelResolver;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateImport extends CreateRecord
{
    protected static string $resource = ImportResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Import created successfully';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set the user who created the import
        $data['user_id'] = auth()->id();
        
        // Set default state if not provided
        if (!isset($data['state'])) {
            $data['state'] = config('importer.default_state', 'pending');
        }
        
        // Initialize progress if not set
        if (!isset($data['progress'])) {
            $data['progress'] = 0;
        }
        
        // Ensure metadata is an array
        if (!isset($data['metadata'])) {
            $data['metadata'] = [];
        }
        
        return $data;
    }

    protected function afterCreate(): void
    {
        // Perform any post-creation logic here
        $record = $this->getRecord();
        
        if ($record instanceof ImportContract) {
            // Log the import creation
            logger()->info('Import created', [
                'import_id' => $record->id,
                'driver' => $record->driver,
                'source_type' => $record->source_type,
                'user_id' => $record->user_id,
            ]);
            
            // Optionally queue the import for processing
            if (config('importer.auto_process', false)) {
                $this->queueImport($record);
            }
        }
    }

    protected function queueImport(ImportContract $record): void
    {
        // Queue the import for background processing
        // This would integrate with your import processing system
        logger()->info('Import queued for processing', [
            'import_id' => $record->id,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel')
                ->label('Cancel')
                ->url($this->getResource()::getUrl('index'))
                ->color('gray'),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreateAnotherFormAction(): Actions\Action
    {
        return Actions\Action::make('createAnother')
            ->label('Create & create another')
            ->action('createAnother')
            ->keyBindings(['mod+shift+s'])
            ->color('gray');
    }

    public function createAnother(): void
    {
        $this->create(shouldCreateAnother: true);
    }

    public function getTitle(): string
    {
        return 'Create Import';
    }

    public function getHeading(): string
    {
        return 'Create New Import';
    }

    public function getSubheading(): ?string
    {
        return 'Configure and create a new data import';
    }
}