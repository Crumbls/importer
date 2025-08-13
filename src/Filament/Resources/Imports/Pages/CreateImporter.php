<?php

namespace Crumbls\Importer\Filament\Resources\Imports\Pages;

use Crumbls\Importer\Filament\Resources\Imports\ImportResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Crumbls\Importer\Filament\Traits\HandlesFileBrowser;

class CreateImporter extends CreateRecord
{
    use HandlesFileBrowser;
    
    protected static string $resource = ImportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
		if ($temp = Arr::get($data, 'file_browser')) {
			$data['source_type'] = 'file';
			$data['source_detail'] = $temp;
    } else if (!isset($data['source_type'])) {
            if ($temp = Arr::get($data, 'source_detail_database')) {
                $data['source_type'] = 'database';
                $data['source_detail'] = $temp;
            } elseif ($temp = Arr::get($data, 'source_detail_file')) {
                $data['source_type'] = 'file';
                // For file uploads, combine disk and file path
                $disk = Arr::get($data, 'storage_disk', 'local');
                // If storage_disk is 'upload', use the default disk
                if ($disk === 'upload') {
                    $disk = config('filesystems.default');
                }
                $data['source_detail'] = $disk . ':' . $temp;
            } elseif ($temp = Arr::get($data, 'selected_file_path')) {
                $data['source_type'] = 'file';
                // For file browser selection, combine disk and selected file path
                $disk = Arr::get($data, 'storage_disk', 'local');
                $data['source_detail'] = $disk . ':' . $temp;
            } else {
                dd(__LINE__);
            }
        }

        unset($data['source_detail_file'], $data['source_detail_database'], $data['storage_disk'], $data['selected_file_path']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
