<?php

namespace Crumbls\Importer\Filament\Resources\ImportResource\Pages;

use Crumbls\Importer\Filament\Resources\ImportResource;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Import')
                ->icon('heroicon-o-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            // Can add import statistics widgets here
        ];
    }


    protected function canEditRecord(ImportContract $record): bool
    {
        // Only allow editing if import is in pending state
        return str_contains($record->state ?? '', 'Pending');
    }

    protected function canDeleteRecord(ImportContract $record): bool
    {
        // Allow deletion if import is not currently processing
        return !str_contains($record->state ?? '', 'Processing');
    }

    public function getTitle(): string
    {
        return 'Imports';
    }

    public function getHeading(): string
    {
        return 'Manage Imports';
    }

    public function getSubheading(): ?string
    {
        return 'View and manage all data imports';
    }
}