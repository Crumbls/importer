<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt\Concerns;

use Crumbls\Importer\Models\ImportModelMap;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait ValidatesMapping
{
    /**
     * Validate a mapping configuration and return issues
     */
    protected function validateMapping(ImportModelMap $map): array
    {
        $issues = [];
        
        // Core validation checks
        $issues = array_merge($issues, $this->validateDestination($map));
        $issues = array_merge($issues, $this->validateColumnMappings($map));
        $issues = array_merge($issues, $this->validateColumnTypes($map));
        $issues = array_merge($issues, $this->validateRelationships($map));
        $issues = array_merge($issues, $this->validateNamingConventions($map));
        $issues = array_merge($issues, $this->validateConstraints($map));
        
        return $issues;
    }

    /**
     * Validate destination table/model configuration
     */
    protected function validateDestination(ImportModelMap $map): array
    {
        $issues = [];
        
        if (!$map->destination_table) {
            $issues[] = [
                'type' => 'error',
                'category' => 'destination',
                'message' => 'No destination table specified',
                'fix' => 'Set a destination table name'
            ];
        }
        
        return $issues;
    }

    /**
     * Validate column mappings completeness
     */
    protected function validateColumnMappings(ImportModelMap $map): array
    {
        $issues = [];
        $sourceColumns = $this->getSourceColumns($map);
        $mappings = $map->column_mappings ?? [];
        
        // Check for unmapped source columns
        foreach ($sourceColumns as $sourceCol) {
            $columnName = $sourceCol['name'];
            
            if (!isset($mappings[$columnName])) {
                $issues[] = [
                    'type' => 'warning',
                    'category' => 'mapping',
                    'message' => "Source column '{$columnName}' is not mapped",
                    'fix' => 'Add column mapping or exclude from import'
                ];
                continue;
            }
            
            $mapping = $mappings[$columnName];
            
            // Check for missing destination column
            if (empty($mapping['destination_column'])) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'mapping',
                    'message' => "Source column '{$columnName}' has no destination column",
                    'fix' => 'Specify destination column name'
                ];
            }
        }
        
        return $issues;
    }

    /**
     * Validate column type compatibility
     */
    protected function validateColumnTypes(ImportModelMap $map): array
    {
        $issues = [];
        $mappings = $map->column_mappings ?? [];
        
        // Validate type consistency for new tables
        foreach ($mappings as $sourceCol => $mapping) {
            $castType = $mapping['cast_type'] ?? 'string';
            
            if (!$this->isValidCastType($castType)) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'invalid_type',
                    'message' => "Invalid cast type '{$castType}' for column '{$sourceCol}'",
                    'fix' => 'Use a valid Laravel cast type'
                ];
            }
        }
        
        return $issues;
    }

    /**
     * Validate relationship configurations
     */
    protected function validateRelationships(ImportModelMap $map): array
    {
        $issues = [];
        $relationships = $map->relationships ?? [];
        $mappings = $map->column_mappings ?? [];
        
        foreach ($relationships as $relationship) {
            $type = $relationship['type'] ?? '';
            $foreignKey = $relationship['foreign_key'] ?? '';
            
            // Validate relationship type
            if (!in_array($type, ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany'])) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'relationship',
                    'message' => "Invalid relationship type '{$type}'",
                    'fix' => 'Use valid Eloquent relationship type'
                ];
            }
            
            // Validate foreign key exists
            if ($foreignKey && !isset($mappings[$foreignKey])) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'relationship',
                    'message' => "Relationship foreign key '{$foreignKey}' is not mapped",
                    'fix' => 'Map the foreign key column or remove relationship'
                ];
            }
        }
        
        return $issues;
    }

    /**
     * Validate naming conventions
     */
    protected function validateNamingConventions(ImportModelMap $map): array
    {
        $issues = [];
        $mappings = $map->column_mappings ?? [];
        
        foreach ($mappings as $sourceCol => $mapping) {
            $destCol = $mapping['destination_column'] ?? '';
            
            if (!$destCol) continue;
            
            // Check for Laravel reserved words
            if ($this->isReservedWord($destCol)) {
                $issues[] = [
                    'type' => 'error',
                    'category' => 'naming',
                    'message' => "Destination column '{$destCol}' is a reserved word",
                    'fix' => 'Choose a different column name'
                ];
            }
        }
        
        return $issues;
    }

    /**
     * Validate database constraints
     */
    protected function validateConstraints(ImportModelMap $map): array
    {
        $issues = [];
        $mappings = $map->column_mappings ?? [];
        
        // Check for missing primary key
        $hasPrimaryKey = $this->hasPrimaryKey($mappings);
        
        if (!$hasPrimaryKey && !$this->isLockedToExisting($map)) {
            $issues[] = [
                'type' => 'info',
                'category' => 'constraints',
                'message' => 'No primary key column found - Laravel will auto-create an "id" column',
                'fix' => 'Laravel automatically adds an auto-incrementing "id" primary key'
            ];
        }
        
        return $issues;
    }

    /**
     * Check if mapping has a primary key defined
     */
    protected function hasPrimaryKey(array $mappings): bool
    {
        foreach ($mappings as $mapping) {
            $destCol = $mapping['destination_column'] ?? '';
            $isPrimary = $mapping['primary'] ?? false;
            
            // Check if explicitly marked as primary or is an 'id' column
            if ($isPrimary || $destCol === 'id') {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Helper methods for validation
     */
    protected function getSourceColumns(ImportModelMap $map): array
    {
        // This would need to be implemented based on how to access the storage driver
        // For now, return empty array to prevent errors
        return [];
    }

    protected function isValidCastType(string $type): bool
    {
        $validTypes = [
            'string', 'integer', 'float', 'boolean', 'array', 'object',
            'datetime', 'timestamp', 'date', 'json', 'decimal', 'bigint'
        ];
        
        return in_array($type, $validTypes);
    }

    protected function isLockedToExisting(ImportModelMap $map): bool
    {
        return !empty($map->metadata['locked_to_existing']);
    }

    protected function isReservedWord(string $word): bool
    {
        $reserved = [
            'class', 'function', 'new', 'extends', 'implements', 'public', 'private', 'protected',
            'static', 'final', 'abstract', 'interface', 'trait', 'namespace', 'use'
        ];
        
        return in_array(strtolower($word), $reserved);
    }

    /**
     * Display validation results to user
     */
    protected function displayValidationResults(array $issues): void
    {
        if (empty($issues)) {
            $this->command->info('✅ All validations passed!');
            return;
        }
        
        $errors = collect($issues)->where('type', 'error');
        $warnings = collect($issues)->where('type', 'warning');
        
        if ($errors->isNotEmpty()) {
            $this->command->error("❌ {$errors->count()} Error(s) Found:");
            foreach ($errors as $issue) {
                $this->command->line("  • {$issue['message']}");
                if (isset($issue['fix'])) {
                    $this->command->line("    Fix: {$issue['fix']}");
                }
            }
            $this->command->newLine();
        }
        
        if ($warnings->isNotEmpty()) {
            $this->command->getOutput()->writeln("<fg=yellow>⚠️  {$warnings->count()} Warning(s):</>");
            foreach ($warnings as $issue) {
                $this->command->line("  • {$issue['message']}");
            }
            $this->command->newLine();
        }
    }
}
