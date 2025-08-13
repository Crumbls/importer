<?php

namespace Crumbls\Importer\Filament\Resources\Imports\Pages;

use Crumbls\Importer\Filament\Resources\Imports\ImportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImporter extends EditRecord
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
