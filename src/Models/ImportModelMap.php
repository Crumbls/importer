<?php

namespace Crumbls\Importer\Models;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportModelMap extends Model implements ImportModelMapContract
{
    protected $fillable = [
        'import_id',
        'entity_type',
        'source_table',
        'source_type',
        'target_model',
        'target_table',
        'field_mappings',
        'transformation_rules',
        'is_active',
        'priority',
        'metadata',
        // New comprehensive fields
        'source_info',
        'destination_info',
        'schema_mapping',
        'relationships',
        'conflict_resolution',
        'data_validation',
        'model_metadata',
        'performance_config',
        'migration_metadata'
    ];

    protected $casts = [
        'field_mappings' => 'array',
        'transformation_rules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
        // New comprehensive fields
        'source_info' => 'array',
        'destination_info' => 'array',
        'schema_mapping' => 'array',
        'relationships' => 'array',
        'conflict_resolution' => 'array',
        'data_validation' => 'array',
        'model_metadata' => 'array',
        'performance_config' => 'array',
        'migration_metadata' => 'array'
    ];

    protected $attributes = [
        'is_active' => true,
        'priority' => 100,
        'field_mappings' => '[]',
        'transformation_rules' => '[]',
        'metadata' => '{}',
        // New comprehensive defaults
        'source_info' => '{}',
        'destination_info' => '{}',
        'schema_mapping' => '{}',
        'relationships' => '{}',
        'conflict_resolution' => '{}',
        'data_validation' => '{}',
        'model_metadata' => '{}',
        'performance_config' => '{}',
        'migration_metadata' => '{}'
    ];

    /**
     * Get the import that owns this mapping
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::import());
    }

    /**
     * Get the target model class name
     */
    public function getTargetModelClass(): string
    {
        return $this->target_model;
    }

    /**
     * Get the source table name
     */
    public function getSourceTable(): string
    {
        return $this->source_table;
    }

    /**
     * Get field mappings with defaults
     */
    public function getFieldMappings(): array
    {
        return $this->field_mappings ?? [];
    }

    /**
     * Get transformation rules
     */
    public function getTransformationRules(): array
    {
        return $this->transformation_rules ?? [];
    }

    /**
     * Set field mapping
     */
    public function setFieldMapping(string $sourceField, string $targetField): self
    {
        $mappings = $this->getFieldMappings();
        $mappings[$sourceField] = $targetField;
        $this->field_mappings = $mappings;
        
        return $this;
    }

    /**
     * Remove field mapping
     */
    public function removeFieldMapping(string $sourceField): self
    {
        $mappings = $this->getFieldMappings();
        unset($mappings[$sourceField]);
        $this->field_mappings = $mappings;
        
        return $this;
    }

    /**
     * Set transformation rule
     */
    public function setTransformationRule(string $field, array $rule): self
    {
        $rules = $this->getTransformationRules();
        $rules[$field] = $rule;
        $this->transformation_rules = $rules;
        
        return $this;
    }

    /**
     * Get transformation rule for a field
     */
    public function getTransformationRule(string $field): ?array
    {
        $rules = $this->getTransformationRules();
        return $rules[$field] ?? null;
    }

    /**
     * Check if this mapping is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Activate this mapping
     */
    public function activate(): self
    {
        $this->is_active = true;
        return $this;
    }

    /**
     * Deactivate this mapping
     */
    public function deactivate(): self
    {
        $this->is_active = false;
        return $this;
    }

    /**
     * Scope to only active mappings
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to specific driver
     */
    public function scopeForDriver($query, string $driver)
    {
        return $query->where('driver', $driver);
    }

    /**
     * Scope to specific source table
     */
    public function scopeForSourceTable($query, string $table) : Builder
    {
        return $query->where('source_table', $table);
    }

    /**
     * Order by priority
     */
    public function scopeOrderedByPriority($query) : Builder
    {
        return $query->orderBy('priority', 'asc');
    }

    // =============================================
    // COMPREHENSIVE IMPORTMODELMAP V2.0 METHODS
    // =============================================
    
    /**
     * Build complete ImportModelMap structure from our comprehensive design
     */
    public function buildCompleteStructure(): array
    {
        return [
            // Basic identification
            'import_id' => $this->import_id,
            'entity_type' => $this->entity_type,
            
            // Source information (driver controls this)
            'source_info' => $this->source_info ?? [],
            
            // Destination information
            'destination_info' => $this->destination_info ?? [],
            
            // Detailed schema mapping with Laravel column types
            'schema_mapping' => $this->schema_mapping ?? [],
            
            // Relationship mapping
            'relationships' => $this->relationships ?? [],
            
            // Conflict resolution (configurable)
            'conflict_resolution' => $this->conflict_resolution ?? [],
            
            // Data validation & quality
            'data_validation' => $this->data_validation ?? [],
            
            // Transformation rules
            'transformation_rules' => $this->transformation_rules ?? [],
            
            // Model generation metadata
            'model_metadata' => $this->model_metadata ?? [],
            
            // Performance configuration
            'performance_config' => $this->performance_config ?? [],
            
            // Migration metadata
            'migration_metadata' => $this->migration_metadata ?? []
        ];
    }
    
    /**
     * Set source information (driver-specific)
     */
    public function setSourceInfo(array $sourceInfo): self
    {
        $this->source_info = $sourceInfo;
        return $this;
    }
    
    /**
     * Set destination information
     */
    public function setDestinationInfo(array $destinationInfo): self
    {
        $this->destination_info = $destinationInfo;
        return $this;
    }
    
    /**
     * Set schema mapping with Laravel column types
     */
    public function setSchemaMapping(array $schemaMapping): self
    {
        $this->schema_mapping = $schemaMapping;
        return $this;
    }
    
    /**
     * Add a column to schema mapping
     */
    public function addColumnMapping(string $columnName, array $mapping): self
    {
        $schema = $this->schema_mapping ?? [];
        $schema['columns'][$columnName] = $mapping;
        $this->schema_mapping = $schema;
        return $this;
    }
    
    /**
     * Set relationship mapping
     */
    public function setRelationships(array $relationships): self
    {
        $this->relationships = $relationships;
        return $this;
    }
    
    /**
     * Set conflict resolution strategy
     */
    public function setConflictResolution(array $conflictResolution): self
    {
        $this->conflict_resolution = $conflictResolution;
        return $this;
    }
    
    /**
     * Set data validation rules
     */
    public function setDataValidation(array $dataValidation): self
    {
        $this->data_validation = $dataValidation;
        return $this;
    }
    
    /**
     * Set model generation metadata
     */
    public function setModelMetadata(array $modelMetadata): self
    {
        $this->model_metadata = $modelMetadata;
        return $this;
    }
    
    /**
     * Set performance configuration
     */
    public function setPerformanceConfig(array $performanceConfig): self
    {
        $this->performance_config = $performanceConfig;
        return $this;
    }
    
    /**
     * Set migration metadata
     */
    public function setMigrationMetadata(array $migrationMetadata): self
    {
        $this->migration_metadata = $migrationMetadata;
        return $this;
    }
    
    /**
     * Get columns from schema mapping
     */
    public function getColumns(): array
    {
        return $this->schema_mapping['columns'] ?? [];
    }
    
    /**
     * Get relationships by type
     */
    public function getRelationshipsByType(string $type): array
    {
        return $this->relationships[$type] ?? [];
    }
    
    /**
     * Check if model conflict detected
     */
    public function hasModelConflict(): bool
    {
        return $this->conflict_resolution['conflict_detected'] ?? false;
    }
    
    /**
     * Get conflict resolution strategy
     */
    public function getConflictStrategy(): string
    {
        return $this->conflict_resolution['strategy'] ?? 'smart_extension';
    }
    
    /**
     * Get target model name from destination info
     */
    public function getTargetModelName(): ?string
    {
        return $this->destination_info['model_name'] ?? $this->target_model;
    }
    
    /**
     * Get target table name from destination info
     */
    public function getTargetTableName(): ?string
    {
        return $this->destination_info['table_name'] ?? $this->target_table;
    }

    /**
     * Get a summary of this mapping (enhanced)
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'source_table' => $this->source_table,
            'source_type' => $this->source_type,
            'target_model' => $this->getTargetModelName(),
            'target_table' => $this->getTargetTableName(),
            'column_count' => count($this->getColumns()),
            'field_count' => count($this->getFieldMappings()),
            'transformation_count' => count($this->getTransformationRules()),
            'relationship_count' => count($this->relationships ?? []),
            'has_conflict' => $this->hasModelConflict(),
            'conflict_strategy' => $this->getConflictStrategy(),
            'is_active' => $this->isActive(),
            'is_ready' => $this->isReady(),
            'priority' => $this->priority,
            'driver' => $this->driver
        ];
    }

	public function isReady() : bool {
		$required = [
			'destination_info',
			'target_model', 'target_table', 'schema_mapping'];


		foreach ($required as $field) {
			if (empty($this->$field)) {
				return false;
			}
		}

		$conflictResolution = $this->conflict_resolution ?? [];
		$hasConflict = $conflictResolution['conflict_detected'] ?? false;

		if ($hasConflict) {
			return false;
		}

		return true;
	}
}