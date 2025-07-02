<?php

namespace Crumbls\Importer\Exceptions;

class FileNotReadableException extends FileException
{
    protected array $recoveryOptions = [
        'Check file permissions',
        'Verify the file is not corrupted',
        'Try running with elevated permissions'
    ];
    
    public function __construct(string $filePath, ?\Throwable $previous = null)
    {
        parent::__construct(
            "File is not readable: {$filePath}",
            $filePath,
            $previous
        );
    }
}