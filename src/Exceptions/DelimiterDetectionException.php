<?php

namespace Crumbls\Importer\Exceptions;

class DelimiterDetectionException extends FileException
{
    protected array $recoveryOptions = [
        'Manually specify the delimiter using ->delimiter()',
        'Check if the file uses a non-standard delimiter',
        'Verify the file contains actual CSV data'
    ];
    protected bool $isRetryable = true;
    
    public function __construct(string $filePath, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Could not automatically detect CSV delimiter in: {$filePath}",
            $filePath,
            $previous
        );
    }
}