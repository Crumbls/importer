<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\Console\Prompts\MappingPrompt\MainPrompt;
use Crumbls\Importer\Models\ImportModelMap;
use Crumbls\Importer\States\AbstractState;

class MappingState extends AbstractState
{
    /**
     * Get the prompt class for viewing this state
     */
    public function getPromptClass(): string
    {
        return MainPrompt::class;
    }

    /**
     * Main execution - create ImportModelMaps for tables that don't exist
     */
    public function execute(): bool
    {
        $existingTables = $this->getExistingMappedTables();
        $sourceTables = $this->getSourceTables();
        $createdMaps = [];

        foreach ($sourceTables as $sourceTable) {
            if (!$existingTables->contains('source_table', $sourceTable)) {
                $map = $this->createMapForTable($sourceTable);
                $createdMaps[] = $map;
            }
        }

        return true;
    }

    /**
     * Get existing mapped tables for this import
     */
    protected function getExistingMappedTables()
    {
        return ImportModelMap::where('import_id', $this->getRecord()->id)
            ->select('source_table')
            ->get();
    }

    /**
     * Get source tables from storage driver
     */
    protected function getSourceTables()
    {
        return collect($this->getStorageDriver()->getTables());
    }

    /**
     * Create a basic ImportModelMap with intelligent defaults
     */
    protected function createMapForTable(string $sourceTable): ImportModelMap
    {
        $record = $this->getRecord();
        $columns = $this->getStorageDriver()->getTableColumns($sourceTable);
        
        return $record->modelMaps()->create([
            'import_id' => $record->id,
            'source_table' => $sourceTable,
            'destination_table' => $this->generateDestinationTableName($sourceTable),
            'connection' => 'mysql',
            'column_mappings' => $this->createBasicColumnMappings($columns),
            'validation_rules' => [],
            'transformations' => [],
            'relationships' => [],
            'is_processed' => false
        ]);
    }

    /**
     * Create intelligent column mappings
     */
    protected function createBasicColumnMappings(array $columns): array
    {
        $mappings = [];
        
        foreach ($columns as $column) {
            $columnName = $column['name'];
            
            $mappings[$columnName] = [
                'destination_column' => $this->normalizeColumnName($columnName),
                'cast_type' => $this->inferBasicType($column),
                'nullable' => $column['nullable'] ?? true,
                'default' => $column['default'] ?? null,
                'primary' => $this->isPrimaryKeyColumn($columnName, $column)
            ];
        }
        
        // Ensure we have a primary key
        if (!$this->hasPrimaryKeyInMappings($mappings)) {
            $mappings['_laravel_id'] = [
                'destination_column' => 'id',
                'cast_type' => 'integer',
                'nullable' => false,
                'primary' => true,
                'auto_increment' => true,
                '_auto_generated' => true,
                '_note' => 'Auto-generated Laravel primary key'
            ];
        }
        
        return $mappings;
    }

    protected function normalizeColumnName(string $columnName): string
    {
        if (in_array(strtolower($columnName), ['id', 'pk', 'primary_key'])) {
            return 'id';
        }
        return $columnName;
    }

    protected function inferBasicType(array $column): string
    {
        $sqliteType = strtolower($column['type'] ?? 'text');
        
        if (str_contains($sqliteType, 'int')) {
            return 'integer';
        }
        if (str_contains($sqliteType, 'real') || str_contains($sqliteType, 'float')) {
            return 'float';
        }
        return 'string';
    }

    protected function isPrimaryKeyColumn(string $columnName, array $column): bool
    {
        if ($column['primary'] ?? false) {
            return true;
        }
        
        $primaryKeyNames = ['id', 'pk', 'primary_key'];
        return in_array(strtolower($columnName), $primaryKeyNames);
    }

    protected function hasPrimaryKeyInMappings(array $mappings): bool
    {
        foreach ($mappings as $mapping) {
            if ($mapping['primary'] ?? false) {
                return true;
            }
        }
        return false;
    }

    protected function generateDestinationTableName(string $sourceTable): string
    {
        return \Illuminate\Support\Str::plural(\Illuminate\Support\Str::snake($sourceTable));
    }
}
