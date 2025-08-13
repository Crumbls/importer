<?php

namespace Crumbls\Importer\Filament\Traits;

use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait HandlesFileBrowser
{
    public function getDirectoryContents(string $disk, string $path = ''): array
    {
        try {
            $storage = Storage::disk($disk);
            $path = ltrim($path, '/');
            
            $directories = collect($storage->directories($path))
                ->map(fn($dir) => [
                    'name' => basename($dir),
                    'path' => $dir,
                    'type' => 'directory',
                    'size' => null,
                    'modified' => null,
                ])
                ->toArray();

            $files = collect($storage->files($path))
                ->filter(fn($file) => $this->isValidFileForBrowser($file))
                ->map(fn($file) => [
                    'name' => basename($file),
                    'path' => $file,
                    'type' => 'file',
                    'size' => $storage->size($file),
                    'modified' => $storage->lastModified($file),
                    'extension' => pathinfo($file, PATHINFO_EXTENSION),
                ])
                ->toArray();

            return [
                'current_path' => $path,
                'parent_path' => $path ? dirname($path) : null,
                'directories' => $directories,
                'files' => $files,
            ];
        } catch (\Exception $e) {
            return [
                'current_path' => '',
                'parent_path' => null,
                'directories' => [],
                'files' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function uploadFileToDisk(TemporaryUploadedFile $file, string $disk, string $currentPath = ''): string
    {
        $storage = Storage::disk($disk);
        $filename = $file->getClientOriginalName();
        $path = $currentPath ? $currentPath . '/' . $filename : $filename;
        
        // Validate file extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExtensions = ['csv', 'xlsx', 'xls', 'json', 'txt'];
        
        if (!in_array($extension, $allowedExtensions)) {
            throw new \InvalidArgumentException('File type not allowed. Allowed types: ' . implode(', ', $allowedExtensions));
        }
        
        // Ensure unique filename if file already exists
        $counter = 1;
        $originalPath = $path;
        while ($storage->exists($path)) {
            $pathInfo = pathinfo($originalPath);
            $path = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
            $counter++;
        }
        
        $file->storeAs($currentPath, basename($path), $disk);
        
        return $path;
    }

    protected function isValidFileForBrowser(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExtensions = ['csv', 'xlsx', 'xls', 'json', 'txt'];
        return in_array($extension, $allowedExtensions);
    }
}
