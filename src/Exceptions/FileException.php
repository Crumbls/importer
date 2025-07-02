<?php

namespace Crumbls\Importer\Exceptions;

abstract class FileException extends ImporterException
{
    protected string $filePath;
    
    public function __construct(string $message, string $filePath, ?\Throwable $previous = null)
    {
        $this->filePath = $filePath;
        parent::__construct($message, 0, $previous);
    }
    
    public function getFilePath(): string
    {
        return $this->filePath;
    }
    
    public function getContext(): array
    {
        return array_merge(parent::getContext(), [
            'file_path' => $this->filePath,
            'file_exists' => file_exists($this->filePath),
            'file_size' => file_exists($this->filePath) ? filesize($this->filePath) : null
        ]);
    }
}