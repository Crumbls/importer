<?php

namespace Crumbls\Importer\Resolvers;

use Crumbls\Importer\Contracts\SourceResolverContract;
use Illuminate\Support\Facades\Storage;

class FileSourceResolver implements SourceResolverContract
{
    protected string $sourceType;
    protected string $sourceDetail;

    public function __construct(string $sourceType, string $sourceDetail)
    {
        $this->sourceType = $sourceType;
        $this->sourceDetail = $sourceDetail;
    }

    public function canHandle(string $sourceType): bool
    {
        return str_starts_with($sourceType, 'file::') || str_starts_with($sourceType, 'disk::');
    }

    public function resolve(): string
    {
        if ($this->sourceType === 'file::absolute') {
            if (!file_exists($this->sourceDetail)) {
                throw new \InvalidArgumentException("File not found: {$this->sourceDetail}");
            }
            return $this->sourceDetail;
        }

        if (str_starts_with($this->sourceType, 'disk::')) {
            $diskName = substr($this->sourceType, 6); // Remove 'disk::'
            
            if (!Storage::disk($diskName)->exists($this->sourceDetail)) {
                throw new \InvalidArgumentException("File not found on disk '{$diskName}': {$this->sourceDetail}");
            }
            
            return Storage::disk($diskName)->path($this->sourceDetail);
        }

        throw new \InvalidArgumentException("Unsupported source type: {$this->sourceType}");
    }

    public function getMetadata(): array
    {
        $filePath = $this->resolve();
        
        return [
            'path' => $filePath,
            'size' => filesize($filePath),
            'type' => mime_content_type($filePath) ?: 'unknown',
            'readable' => is_readable($filePath),
            'modified' => filemtime($filePath),
        ];
    }
}