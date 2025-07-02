<?php

namespace Crumbls\Importer\Exceptions;

class FileTooLargeException extends FileException
{
    protected int $fileSize;
    protected int $maxSize;
    protected array $recoveryOptions = [
        'Use chunked processing with smaller batch sizes',
        'Enable temporary storage for large files',
        'Consider splitting the file into smaller parts'
    ];
    
    public function __construct(string $filePath, int $fileSize, int $maxSize, ?\Throwable $previous = null)
    {
        $this->fileSize = $fileSize;
        $this->maxSize = $maxSize;
        
        parent::__construct(
            "File too large: {$filePath} ({$this->formatBytes($fileSize)} exceeds limit of {$this->formatBytes($maxSize)})",
            $filePath,
            $previous
        );
    }
    
    public function getFileSize(): int
    {
        return $this->fileSize;
    }
    
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }
    
    public function getContext(): array
    {
        return array_merge(parent::getContext(), [
            'file_size' => $this->fileSize,
            'max_size' => $this->maxSize,
            'formatted_file_size' => $this->formatBytes($this->fileSize),
            'formatted_max_size' => $this->formatBytes($this->maxSize)
        ]);
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}