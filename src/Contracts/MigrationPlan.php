<?php

namespace Crumbls\Importer\Contracts;

class MigrationPlan
{
    public function __construct(
        public readonly string $id,
        public readonly array $summary,
        public readonly array $operations,
        public readonly array $relationships,
        public readonly array $conflicts = [],
        public readonly array $metadata = []
    ) {}
    
    public function getId(): string
    {
        return $this->id;
    }
    
    public function getSummary(): array
    {
        return $this->summary;
    }
    
    public function getOperations(): array
    {
        return $this->operations;
    }
    
    public function getRelationships(): array
    {
        return $this->relationships;
    }
    
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
    
    public function hasConflicts(): bool
    {
        return !empty($this->conflicts);
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'summary' => $this->summary,
            'operations' => $this->operations,
            'relationships' => $this->relationships,
            'conflicts' => $this->conflicts,
            'metadata' => $this->metadata
        ];
    }
}