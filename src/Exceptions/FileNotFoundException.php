<?php

namespace Crumbls\Importer\Exceptions;

class FileNotFoundException extends FileException
{
    protected array $recoveryOptions = [
        'Verify the file path is correct',
        'Check if the file exists in the expected location',
        'Ensure you have permission to access the directory'
    ];
    
    public function __construct(string $filePath, ?\Throwable $previous = null)
    {
        parent::__construct(
            "File not found: {$filePath}",
            $filePath,
            $previous
        );
    }
}