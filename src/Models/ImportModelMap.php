<?php

namespace Crumbls\Importer\Models;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportModelMap extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'import_id',
        'source_table',
        'source_type',
        'target_model',
        'target_table',
        'field_mappings',
        'transformation_rules',
        'driver',
        'is_active',
        'priority',
        'metadata'
    ];

    protected $casts = [
        'field_mappings' => 'array',
        'transformation_rules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer'
    ];

    protected $attributes = [
        'is_active' => true,
        'priority' => 100,
        'field_mappings' => '[]',
        'transformation_rules' => '[]',
        'metadata' => '{}'
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
    public function scopeForSourceTable($query, string $table)
    {
        return $query->where('source_table', $table);
    }

    /**
     * Order by priority
     */
    public function scopeOrderedByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    /**
     * Get a summary of this mapping
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'source_table' => $this->source_table,
            'source_type' => $this->source_type,
            'target_model' => $this->target_model,
            'target_table' => $this->target_table,
            'field_count' => count($this->getFieldMappings()),
            'transformation_count' => count($this->getTransformationRules()),
            'is_active' => $this->isActive(),
            'priority' => $this->priority,
            'driver' => $this->driver
        ];
    }
}