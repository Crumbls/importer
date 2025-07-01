<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class MigrationException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $migrationId,
        public readonly string $entityType = 'unknown',
        public readonly array $context = [],
        public readonly ?array $recoveryOptions = null,
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
    
    public function getMigrationId(): string
    {
        return $this->migrationId;
    }
    
    public function getEntityType(): string
    {
        return $this->entityType;
    }
    
    public function getContext(): array
    {
        return $this->context;
    }
    
    public function getRecoveryOptions(): ?array
    {
        return $this->recoveryOptions;
    }
    
    public function hasRecoveryOptions(): bool
    {
        return !empty($this->recoveryOptions);
    }
    
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'migration_id' => $this->migrationId,
            'entity_type' => $this->entityType,
            'context' => $this->context,
            'recovery_options' => $this->recoveryOptions,
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }
}