<?php

namespace Crumbls\Importer\Filament\Resources\ImportResource\Pages;

use Crumbls\Importer\Filament\Resources\ImportResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditImport extends EditRecord
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}