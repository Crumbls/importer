<?php

namespace Crumbls\Importer\Contracts;

class DryRunResult
{
    public function __construct(
        public readonly array $summary,
        public readonly array $operations,
        public readonly array $changes,
        public readonly array $conflicts = [],
        public readonly array $statistics = []
    ) {}
    
    public function getSummary(): array
    {
        return $this->summary;
    }
    
    public function getOperations(): array
    {
        return $this->operations;
    }
    
    public function getChanges(): array
    {
        return $this->changes;
    }
    
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
    
    public function getStatistics(): array
    {
        return $this->statistics;
    }
    
    public function hasConflicts(): bool
    {
        return !empty($this->conflicts);
    }
    
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'operations' => $this->operations,
            'changes' => $this->changes,
            'conflicts' => $this->conflicts,
            'statistics' => $this->statistics
        ];
    }
}