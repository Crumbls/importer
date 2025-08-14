<?php

namespace Crumbls\Importer\Filament\Components;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileBrowser extends Field
{
    protected string $view = 'crumbls-importer::components.file-browser';

    protected array $allowedExtensions = ['csv', 'xlsx', 'xls', 'json', 'txt', 'xml'];

	public string $currentPath = '';

	// Allow static string OR Closure, and evaluate it later (v4 pattern)
	protected string|Closure|null $storageName = null;

	public static function make(?string $name = null): static
	{
		return parent::make($name)
			// Only needed if *this* field should send updates as you interact with it:
			->live()
			// This runs when THIS field changes. Use $get() to read the other field:
			->afterStateUpdated(function (Get $get, FileBrowser $component, $state) {
				// Get the storage disk name from the referenced field
				$diskName = $get($component->getReferencedStatePath());
				
				// Handle folder navigation
				if (is_array($state) && isset($state['current_path'])) {
					// Update the component's current path for navigation
					$component->currentPath = $state['current_path'];
					// Clear the cache when navigating to refresh directory contents
					$component->dirCache = [];
				}
				
			});
	}

    public function allowedExtensions(array $extensions): static
    {
        $this->allowedExtensions = $extensions;
        return $this;
    }
	public function references(string|Closure|null $storageName): static
	{
		$this->storageName = $storageName;

		return $this;
	}

	public function getReferencedStatePath(): ?string
	{
		return $this->evaluate($this->storageName);
	}

	public function getStorageManager(): ?Filesystem
	{
		$diskName = $this->evaluate($this->storageName);

		if (! $diskName) {
			return null;
		}

		try {
			return Storage::disk($diskName);
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected array $dirCache = [];

	public function getCachedDirectoryContents(): ?array
	{
		// Get the actual drive name from the referenced field
		$form = $this->getContainer();
		$disk = $form->getState()[$this->getReferencedStatePath()] ?? null;
		$path = $this->currentPath ?? '';

		if (! $disk) {
			return null;
		}

		$cacheKey = $disk . '|' . $path;

		if (! array_key_exists($cacheKey, $this->dirCache)) {
			try {
				$storage = Storage::disk($disk);
				$this->dirCache[$cacheKey] = $this->getDirectoryContents($disk, $path);
			} catch (\Throwable $e) {
				$this->dirCache[$cacheKey] = null;
				return null;
			}
		}

		return $this->dirCache[$cacheKey];
	}

	public function getViewData(): array
	{
		// Get the actual drive name from the referenced field
		$form = $this->getContainer();
		$driveName = $form->getState()[$this->getReferencedStatePath()] ?? null;
		
		return [
			'availableDisks'    => $this->getAvailableDisks(),
			'allowedExtensions' => $this->allowedExtensions,
			'storage'           => $this->getStorageManager(),
			'contents'          => $this->getCachedDirectoryContents(),
			'currentPath'       => $this->currentPath,
			'driveName'         => $driveName,
		];
	}
	// TODO: Maybe lose what's below

    public function getState(): mixed
    {
        $state = parent::getState();
        
        // Handle both string format and array format
        if (is_array($state)) {
            // Update current path if it's in the state
            if (isset($state['current_path'])) {
                $this->currentPath = $state['current_path'];
            }
            
            // Return disk:path format for the main field value
            if (isset($state['disk'], $state['path'])) {
                return $state['disk'] . '::' . $state['path'];
            }
        }
        
        return $state;
    }

    public function setState($state): static
    {
        // Handle different state formats
        if (is_string($state) && str_contains($state, '::')) {
            // Parse format: {disk}::{path}
            [$disk, $path] = explode('::', $state, 2);
            $state = ['disk' => $disk, 'path' => $path];
        } elseif (is_array($state) && isset($state['current_path'])) {
            // Update current path for navigation
            $this->currentPath = $state['current_path'];
        }
        
        return parent::setState($state);
    }

    public function getAvailableDisks(): array
    {
        $disks = config('filesystems.disks', []);
        
        // Filter out disks that might not be suitable for file browsing
        $browsableDisks = [];
        foreach ($disks as $name => $config) {
            if (in_array($config['driver'] ?? '', ['local', 's3', 'ftp', 'sftp'])) {
                $browsableDisks[$name] = ucfirst($name);
            }
        }
        
        return $browsableDisks;
    }

	public function navigateTo(string $path): void
	{
		$this->currentPath = $path;
	}

    public function getDirectoryContents(string $disk, string $path = ''): array
    {
        try {
            $storage = Storage::disk($disk);
            $path = ltrim($path, '/');
            
            // Get raw directories and files first
            $rawDirectories = $storage->directories($path);
            $rawFiles = $storage->files($path);
            
            $directories = collect($rawDirectories)
                ->map(fn($dir) => [
                    'name' => basename($dir),
                    'path' => $dir,
                    'type' => 'directory',
                    'size' => null,
                    'modified' => null,
                ])
                ->toArray();

            $files = collect($rawFiles)
                ->filter(fn($file) => $this->isValidFile($file))
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
                'parent_path' => $path && $path !== '.' && $path !== '' ? dirname($path) : null,
                'directories' => $directories,
                'files' => $files,
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected function isValidFile(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedExtensions);
    }
}
