<?php

namespace Crumbls\Importer\Contracts;

interface DataValidator
{
    /**
     * Validate a single record
     */
    public function validateRecord(array $record, string $entityType): ValidationResult;
    
    /**
     * Validate a batch of records
     */
    public function validateBatch(array $records, string $entityType): ValidationResult;
    
    /**
     * Validate relationships between entities
     */
    public function validateRelationships(array $data): ValidationResult;
    
    /**
     * Validate data integrity across the entire dataset
     */
    public function validateIntegrity(array $data): ValidationResult;
    
    /**
     * Get validation rules for an entity type
     */
    public function getRules(string $entityType): array;
    
    /**
     * Add custom validation rule
     */
    public function addRule(string $entityType, string $field, callable $rule): self;
    
    /**
     * Get configuration
     */
    public function getConfig(): array;
    
    /**
     * Set configuration
     */
    public function setConfig(array $config): self;
}