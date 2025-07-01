<?php

namespace Crumbls\Importer\Exceptions;

class ConnectionException extends MigrationException
{
    public function __construct(
        string $message,
        string $migrationId,
        public readonly string $connectionType = 'database',
        public readonly int $attemptNumber = 1,
        public readonly int $maxAttempts = 3,
        string $entityType = 'unknown',
        array $context = []
    ) {
        $recoveryOptions = [];
        
        if ($attemptNumber < $maxAttempts) {
            $recoveryOptions['retry'] = "Retry connection (attempt {$attemptNumber}/{$maxAttempts})";
            $recoveryOptions['retry_with_backoff'] = 'Retry with exponential backoff';
        }
        
        $recoveryOptions['switch_connection'] = 'Try alternative connection';
        $recoveryOptions['abort_migration'] = 'Abort migration and preserve current state';
        
        parent::__construct(
            $message,
            $migrationId,
            $entityType,
            array_merge($context, [
                'connection_type' => $connectionType,
                'attempt_number' => $attemptNumber,
                'max_attempts' => $maxAttempts
            ]),
            $recoveryOptions
        );
    }
    
    public function canRetry(): bool
    {
        return $this->attemptNumber < $this->maxAttempts;
    }
    
    public function getNextAttemptDelay(): int
    {
        // Exponential backoff: 2^attempt seconds
        return min(pow(2, $this->attemptNumber), 60);
    }
}