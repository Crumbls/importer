<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Traits\IsDiskAware;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use function Laravel\Prompts\select;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;

class FileBrowserPrompt
{
	use IsDiskAware;

    protected string $currentDisk;
    protected string $currentPath;
    protected array $availableDisks = [];
    protected ?string $selectedFile = null;

    public function __construct(protected Command $command)
    {
    }

    public function browse(string $initialDisk = 'local'): ?string
    {

        if (in_array($initialDisk, $this->availableDisks)) {
            $this->currentDisk = $initialDisk;
        }

        $this->runBrowserLoop();

        return $this->currentDisk.'::'.$this->selectedFile;
    }

    protected function runBrowserLoop(): void
    {

		$response = null;

	    if (!isset($this->currentDisk) || !$this->currentDisk) {
			$options = $this->getAvailableDisks();
		    $response = select(__('importer::importer.command.select_disk'), $options);
			$this->currentDisk = $response;
	    }

		if (!isset($this->currentPath) || !$this->currentPath) {
			$this->currentPath = DIRECTORY_SEPARATOR;
		}

		do {
			$storage = Storage::disk($this->currentDisk);

			$path = isset($this->currentPath) && $this->currentPath ? rtrim($this->currentPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : DIRECTORY_SEPARATOR;
			$directories = $storage->directories($path);

			$directoryKeys = array_map(function($directory) use ($path){
				return $path.$directory;
			}, $directories);
			$directoryNames = array_map(function($directory) {
				return basename($directory).DIRECTORY_SEPARATOR;
			}, $directories);
			$directories = array_combine($directoryKeys, $directoryNames);

			$files = $storage->files($path);
			$files = array_filter($files, function($file){
				return $file[0] !== '.';
			});

			$fileNames = array_map(function($file) {
				return basename($file);
			}, $files);
			$fileKeys = array_map(function($file) use ($path) {
				return $path.$file;
			}, $fileNames);
			$files = array_combine($fileKeys, $fileNames);

			$options = array_merge($directories, $files);

			$title = __('importer::importer.command.select_folder_directory').(isset($this->currentPath) && $this->currentPath ? ' - '.$this->currentPath : '');
			$selected = select($title, $options);

			if ($storage->directoryExists($selected)) {
				$this->currentPath = $selected;
			} else if ($storage->fileExists($selected)) {
				$this->selectedFile = $selected;
				break;
			} else {
				// Invalid selection, continue loop
				continue;
			}
		} while (!$this->selectedFile);
    }
}