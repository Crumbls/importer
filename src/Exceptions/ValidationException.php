<?php

namespace Crumbls\Importer\Exceptions;

class ValidationException extends MigrationException
{
    public function __construct(
        string $message,
        string $migrationId,
        public readonly array $validationErrors = [],
        public readonly array $failedRecords = [],
        string $entityType = 'unknown',
        array $context = []
    ) {
        $recoveryOptions = [
            'skip_invalid_records' => 'Continue migration, skipping invalid records',
            'fix_and_retry' => 'Attempt to fix validation errors and retry',
            'abort_migration' => 'Stop migration and rollback changes'
        ];
        
        parent::__construct(
            $message,
            $migrationId,
            $entityType,
            array_merge($context, [
                'validation_errors' => $validationErrors,
                'failed_records_count' => count($failedRecords)
            ]),
            $recoveryOptions
        );
    }
    
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
    
    public function getFailedRecords(): array
    {
        return $this->failedRecords;
    }
    
    public function getFailedRecordsCount(): int
    {
        return count($this->failedRecords);
    }
}