<?php

namespace Crumbls\Importer\Contracts;

class MigrationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $migrationId,
        public readonly array $summary,
        public readonly array $statistics,
        public readonly array $errors = [],
        public readonly array $metadata = []
    ) {}
    
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    public function getMigrationId(): string
    {
        return $this->migrationId;
    }
    
    public function getSummary(): array
    {
        return $this->summary;
    }
    
    public function getStatistics(): array
    {
        return $this->statistics;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'migration_id' => $this->migrationId,
            'summary' => $this->summary,
            'statistics' => $this->statistics,
            'errors' => $this->errors,
            'metadata' => $this->metadata
        ];
    }
}