<?php

namespace Crumbls\Importer\Resolvers;

use Crumbls\Importer\Resolvers\Contracts\SourceResolverContract;
use Crumbls\Importer\Traits\IsDiskAware;
use Illuminate\Support\Facades\Storage;

class FileSourceResolver implements SourceResolverContract
{
	use IsDiskAware;

    public function __construct(protected string $sourceType, protected string $sourceDetail) {
    }

	public function canHandle(string $driver, string $sourceDetail): bool
	{
		if ($driver != 'storage') {
			return false;
		}

		[$sourceType, $filename] = explode('::', $sourceDetail, 2);

		if (!$this->isDiskSupported($sourceType)) {
			return false;
		}

		return Storage::disk($sourceType)->fileExists($filename);
    }

    public function resolve(): string
    {
	    [$sourceType, $filename] = explode('::', $this->sourceDetail, 2);

	    if (!Storage::disk($sourceType)->exists($filename)) {
		    throw new \InvalidArgumentException("File not found on disk '{$sourceType}': {$this->sourceDetail}");
	    }

		return Storage::disk($sourceType)->path($filename);
    }

    public function getMetadata(): array
    {
        $filePath = $this->resolve();
        
        return [
            'path' => $filePath,
            'size' => filesize($filePath),
            'type' => mime_content_type($filePath) ?: 'unknown',
            'readable' => is_readable($filePath),
            'modified_at' => filemtime($filePath),
        ];
    }
}