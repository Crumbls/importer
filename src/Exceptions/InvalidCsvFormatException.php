<?php

namespace Crumbls\Importer\Exceptions;

class InvalidCsvFormatException extends FileException
{
    protected array $recoveryOptions = [
        'Verify the file is a valid CSV',
        'Check for proper quoting and escaping',
        'Try specifying a different delimiter or encoding'
    ];
    protected bool $isRetryable = true;
    
    public function __construct(string $filePath, string $reason = '', ?\Throwable $previous = null)
    {
        $message = "Invalid CSV format: {$filePath}";
        if ($reason) {
            $message .= " - {$reason}";
        }
        
        parent::__construct($message, $filePath, $previous);
    }
}