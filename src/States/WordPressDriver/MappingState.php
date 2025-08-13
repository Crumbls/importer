<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\States\Concerns\AnalyzesValues;
use Crumbls\Importer\States\Concerns\StreamingAnalyzesValues;
use Crumbls\Importer\States\Concerns\HasStorageDriver;
use Crumbls\Importer\States\Concerns\HasSchemaAnalysis;
use Crumbls\Importer\Support\MemoryManager;
use Crumbls\Importer\Facades\Storage;
use Crumbls\StateMachine\State;
use Exception;
use Crumbls\Importer\States\Shared\MappingState as BaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MappingState extends BaseState
{

	public function getExcludedTables() : array {
		return [
			'posts',
			'postmeta',
			'comments',
			'terms',
			'term_relationships'
		];
	}

    /**
     * Prepare analysis data for the mapping state
     */
    protected function prepareAnalysisForMappingState(array $metadata): void
    {
        $dataMap = $metadata['data_map'] ?? [];
        
        // Transform the data structure for the mapping state
        $analysisData = [
            'post_types' => $this->extractPostTypes($dataMap),
            'meta_fields' => $this->extractMetaFields($dataMap),
            'post_columns' => $this->extractPostColumns($dataMap),
            'field_analysis' => $dataMap, // Keep original for reference
            'extraction_stats' => $metadata['parsing_stats'] ?? [],
        ];
        
        // Store in state data for next states
        $this->setStateData('analysis', $analysisData);
    }
    
    /**
     * Extract post types from data map
     */
    protected function extractPostTypes(array $dataMap): array
    {
        $postTypes = [];
        
        // Get the storage driver to analyze post types
        $storage = $this->getStorageDriver();
        
        if (isset($storage) && method_exists($storage, 'db')) {
            $connection = $storage->db();
            
            // Get post type counts
            $postTypeCounts = $connection
	            ->table('posts')
                ->select('post_type')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('post_type')
                ->get()
                ->pluck('count', 'post_type')
                ->toArray();
                
            foreach ($postTypeCounts as $postType => $count) {
                $postTypes[$postType] = [
                    'count' => $count,
                    'type' => $postType,
                    'description' => ucfirst($postType) . ' content type',
                ];
            }
        }
        
        return $postTypes;
    }
    
    /**
     * Extract meta fields from data map
     */
    protected function extractMetaFields(array $dataMap): array
    {
        $metaFields = [];
        
        foreach ($dataMap as $field) {
            if (($field['field_type'] ?? '') === 'meta_field') {
                $metaFields[] = $field;
            }
        }
        
        return $metaFields;
    }
    
    /**
     * Extract post columns from data map
     */
    protected function extractPostColumns(array $dataMap): array
    {
        $postColumns = [];
        
        foreach ($dataMap as $field) {
            if (($field['field_type'] ?? '') === 'post_column') {
                $postColumns[] = $field;
            }
        }
        
        return $postColumns;
    }
    
    /**
     * Generate model maps using comprehensive schema analysis
     */
    public function exclude_getModelMaps(): array
    {
        $modelMaps = [];
        
        // Get all tables from storage
        $tableNames = $this->getStorageTables();
        
        foreach ($tableNames as $tableName) {
            // Skip excluded tables (they're handled elsewhere)
            if (in_array($tableName, $this->getExcludedTables())) {
                continue;
            }
            
            // Analyze the table schema comprehensively
            $schema = $this->analyzeTableSchema($tableName);
            
            $modelMaps[$tableName] = [
                'table_name' => $tableName,
                'suggested_model_name' => $this->generateModelName($tableName),
                'schema' => $schema,
                'record_count' => $this->countStorageRecords($tableName),
                'migration_columns' => $this->generateMigrationColumns($schema),
                'recommended_indexes' => $this->suggestIndexes($schema),
            ];
        }
        
        // Handle special WordPress tables with custom analysis
        $modelMaps = array_merge($modelMaps, $this->getWordPressSpecialTables());
        
        return $modelMaps;
    }
    
    /**
     * Generate model name from table name
     */
    protected function generateModelName(string $tableName): string
    {
        return str($tableName)->studly()->singular()->toString();
    }
    
    /**
     * Generate migration column definitions from schema
     */
    protected function generateMigrationColumns(array $schema): array
    {
        $columns = [];
        
        foreach ($schema as $columnName => $analysis) {
            $columns[$columnName] = $this->generateMigrationColumn($analysis);
        }
        
        return $columns;
    }
    
    /**
     * Suggest database indexes based on data patterns
     */
    protected function suggestIndexes(array $schema): array
    {
        $indexes = [];
        
        foreach ($schema as $columnName => $analysis) {
            // Suggest indexes for high-uniqueness columns
            if (isset($analysis['unique_count']) && isset($analysis['total_records'])) {
                $uniqueness = $analysis['unique_count'] / max(1, $analysis['total_records']);
                
                if ($uniqueness > 0.8) {
                    $indexes[] = [
                        'type' => 'index',
                        'columns' => [$columnName],
                        'reason' => 'High uniqueness ratio: ' . round($uniqueness * 100, 1) . '%'
                    ];
                } elseif ($uniqueness < 0.1 && $analysis['total_records'] > 1000) {
                    $indexes[] = [
                        'type' => 'index', 
                        'columns' => [$columnName],
                        'reason' => 'Low uniqueness, good for filtering large dataset'
                    ];
                }
            }
            
            // Suggest primary key for ID-like columns
            if (str_contains(strtolower($columnName), 'id') && 
                ($analysis['type'] ?? '') === 'bigInteger' &&
                isset($analysis['unique_count']) && isset($analysis['total_records']) &&
                $analysis['unique_count'] === $analysis['total_records']) {
                
                $indexes[] = [
                    'type' => 'primary',
                    'columns' => [$columnName],
                    'reason' => 'Unique ID column'
                ];
            }
        }
        
        return $indexes;
    }
    
    /**
     * Handle special WordPress tables with custom analysis
     */
    protected function getWordPressSpecialTables(): array
    {
        $specialTables = [];
        
        // Analyze postmeta key-value data
        if ($this->storageTableExists('postmeta')) {
            $postmetaAnalysis = $this->analyzeKeyValueData('postmeta', 'meta_key', 'meta_value');
            
            $specialTables['postmeta_fields'] = [
                'table_name' => 'postmeta',
                'type' => 'key_value_analysis',
                'suggested_model_name' => 'PostMeta',
                'key_analysis' => $postmetaAnalysis,
                'top_keys' => $this->getTopMetaKeys('postmeta', 'meta_key'),
                'migration_suggestions' => $this->generateMetaFieldMigrations($postmetaAnalysis),
            ];
        }
        
        // Analyze posts table with post type breakdown
        if ($this->storageTableExists('posts')) {
            $postTypeAnalysis = $this->analyzePostTypeData();
            
            $specialTables['post_types'] = [
                'table_name' => 'posts',
                'type' => 'post_type_analysis', 
                'post_types' => $postTypeAnalysis,
                'suggested_models' => $this->generatePostTypeModels($postTypeAnalysis),
            ];
        }
        
        return $specialTables;
    }
    
    /**
     * Get the most common meta keys
     */
    protected function getTopMetaKeys(string $tableName, string $keyColumn, int $limit = 20): array
    {
        $data = $this->selectFromStorage($tableName);
        $keyCounts = [];
        
        foreach ($data as $row) {
            $key = $row[$keyColumn] ?? '';
            $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
        }
        
        arsort($keyCounts);
        return array_slice($keyCounts, 0, $limit, true);
    }
    
    /**
     * Generate migration suggestions for meta fields
     */
    protected function generateMetaFieldMigrations(array $metaAnalysis): array
    {
        $migrations = [];
        
        foreach ($metaAnalysis as $metaKey => $analysis) {
            if (($analysis['total_records'] ?? 0) > 100) { // Only suggest for common fields
                $migrations[$metaKey] = [
                    'column_name' => str($metaKey)->snake()->toString(),
                    'column_type' => $analysis['type'] ?? 'string',
                    'nullable' => $analysis['nullable'] ?? true,
                    'migration_line' => $this->generateMigrationColumn($analysis),
                    'usage_count' => $analysis['total_records'] ?? 0,
                ];
            }
        }
        
        return $migrations;
    }
    
    /**
     * Analyze post data by post type
     */
    protected function analyzePostTypeData(): array
    {
        $data = $this->selectFromStorage('posts');
        $postTypes = [];
        
        foreach ($data as $row) {
            $postType = $row['post_type'] ?? 'unknown';
            
            if (!isset($postTypes[$postType])) {
                $postTypes[$postType] = [
                    'count' => 0,
                    'sample_data' => [],
                ];
            }
            
            $postTypes[$postType]['count']++;
            
            // Keep sample data for analysis
            if (count($postTypes[$postType]['sample_data']) < 10) {
                $postTypes[$postType]['sample_data'][] = $row;
            }
        }
        
        return $postTypes;
    }
    
    /**
     * Generate suggested models for different post types
     */
    protected function generatePostTypeModels(array $postTypeAnalysis): array
    {
        $models = [];
        
        foreach ($postTypeAnalysis as $postType => $data) {
            if ($data['count'] > 50) { // Only suggest models for substantial post types
                $models[$postType] = [
                    'model_name' => str($postType)->studly()->toString(),
                    'table_name' => 'posts',
                    'scope_conditions' => ['post_type' => $postType],
                    'record_count' => $data['count'],
                    'percentage' => round($data['count'] / array_sum(array_column($postTypeAnalysis, 'count')) * 100, 1),
                ];
            }
        }
        
        return $models;
    }

    /**
     * TODO: Make this work with any of the storage drivers.  This is a late game task.
     * @param $storage
     * @return array
     * @throws \Exception
     */
    protected function analyzePostTypes($storage): array
    {
        if (!method_exists($storage, 'db')) {
            throw new Exception('Storage driver is not yet valid.');
        }

        $connection = $storage->db();

        // Get the actual column names from the table structure  
        $defaultFields = array_keys((array)$connection
            ->table('posts')
            ->take(1)
            ->inRandomOrder()
            ->get()
            ->first());

        $results = [];

        // Analyze default post table columns
        foreach ($defaultFields as $fieldName) {
            $analysis = $this->analyzePostTableColumn($connection, $fieldName, null);
            $analysis['field_name'] = $fieldName;
            $analysis['field_type'] = 'post_column';
            $results[] = $analysis;
        }

        // Get all meta keys and analyze them
        $metaKeys = $connection
            ->table('postmeta')
            ->select('meta_key')
            ->distinct()
            ->pluck('meta_key');

        // Continue adding to existing results array
        foreach ($metaKeys as $metaKey) {
            $analysis = $this->analyzeMetaFieldEfficiently($connection, $metaKey);
            $analysis['field_name'] = $metaKey;
            $analysis['field_type'] = 'meta_field';
            $results[] = $analysis;
        }

        return $results;
    }
    
    /**
     * Analyze a post table column efficiently using sampling
     */
    private function analyzePostTableColumn($connection, string $fieldName, ?string $postType): array
    {
        // Use chunked processing for large datasets
        $chunkSize = 100;
        $maxSamples = 1000; // Limit total samples for analysis
        $samples = collect();
        
        $query = $connection->table('posts')
            ->whereNotNull($fieldName)
            ->where($fieldName, '!=', '')
            ->select($fieldName)
            ->orderBy($fieldName);
            
        // Add post type filter if specified
        if ($postType !== null) {
            $query->where('post_type', $postType);
        }
        
        $query->chunk($chunkSize, function ($chunk) use (&$samples, $maxSamples, $fieldName) {
            foreach ($chunk as $row) {
                if ($samples->count() >= $maxSamples) {
                    return false; // Stop chunking
                }
                $samples->push($row->$fieldName);
            }
        });
        
        return $this->analyzeValues($samples);
    }
    
    /**
     * 🚀 MEMORY OPTIMIZED: Analyze meta field using streaming analysis
     */
    private function analyzeMetaFieldEfficiently($connection, string $metaKey): array
    {
        // Get basic statistics first
        $stats = $connection->table('postmeta')
            ->where('meta_key', $metaKey)
            ->selectRaw('COUNT(*) as total_count, COUNT(DISTINCT meta_value) as unique_count')
            ->first();
        
        $totalCount = $stats->total_count;
        $uniqueCount = $stats->unique_count;
        
        // Initialize memory manager for large datasets
        $memoryManager = null;
        if ($totalCount > 10000) {
            $memoryLimit = $this->parseMemoryLimit('256M'); // Convert to bytes
            $initialBatchSize = 1000;
            
            $memoryManager = new MemoryManager($memoryLimit, $initialBatchSize, [
                'warning_threshold' => 0.8,   // 80% of memory limit
                'critical_threshold' => 0.9,  // 90% of memory limit
                'emergency_threshold' => 0.95, // 95% of memory limit
                'min_batch_size' => 100,       // Don't go below 100
                'max_batch_size' => 2000,      // Don't exceed 2000
            ]);
        }
        
        // For small datasets, use fast original method
        if ($totalCount <= 1000) {
            $values = $connection->table('postmeta')
                ->where('meta_key', $metaKey)
                ->pluck('meta_value');
            
            return $this->analyzeValues($values);
        }
        
        // For large datasets, use streaming analysis
        $maxSamples = min(5000, max(1000, $totalCount * 0.05)); // 5% sample, min 1K, max 5K
        
        // Create data provider that yields batches
        $dataProvider = function() use ($connection, $metaKey, $memoryManager) {
            $batchSize = $memoryManager ? $memoryManager->getCurrentBatchSize() : 1000;
            $offset = 0;
            
            while (true) {
                $chunk = $connection->table('postmeta')
                    ->where('meta_key', $metaKey)
                    ->whereNotNull('meta_value')
                    ->where('meta_value', '!=', '')
                    ->orderBy('meta_id') // Use indexed column for consistent ordering
                    ->skip($offset)
                    ->take($batchSize)
                    ->get();
                
                if ($chunk->isEmpty()) {
                    break; // No more data
                }
                
                // Monitor memory pressure during processing
                if ($memoryManager) {
                    $monitoring = $memoryManager->monitor();
                    
                    // Adjust batch size based on memory pressure
                    if ($monitoring['pressure_level'] === 'critical' || $monitoring['pressure_level'] === 'emergency') {
                        $batchSize = $memoryManager->getCurrentBatchSize();
                        Log::info('Memory pressure detected, batch size adjusted', [
                            'pressure_level' => $monitoring['pressure_level'],
                            'new_batch_size' => $batchSize,
                            'memory_usage' => $monitoring['usage_percentage'] . '%'
                        ]);
                    }
                }
                
                // Yield the values from this chunk
                yield $chunk->pluck('meta_value');
                
                $offset += $batchSize;
            }
        };
        
        // Use streaming analysis
        $analysis = $this->analyzeValuesStreaming($dataProvider, $maxSamples, $memoryManager);
        
        // Add metadata about the analysis
        $analysis['sampling_info'] = [
            'total_records' => $totalCount,
            'unique_records' => $uniqueCount,
            'sample_size' => $analysis['breakdown']['total_count'] ?? 0,
            'is_sampled' => $totalCount > 1000,
            'uniqueness_ratio' => $totalCount > 0 ? round($uniqueCount / $totalCount * 100, 2) : 0,
            'memory_optimized' => $totalCount > 10000,
            'memory_stats' => $memoryManager ? $memoryManager->getMemoryEfficiencyReport() : null,
        ];
        
        // Memory manager cleanup (note: no explicit cleanup method needed)
        if ($memoryManager) {
            Log::info('Memory manager processing complete', $memoryManager->getMemoryEfficiencyReport());
        }
        
        return $analysis;
    }
    
    /**
     * Parse memory limit string to bytes
     */
    protected function parseMemoryLimit(string $limit): int
    {
        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));
        
        return match($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value
        };
    }
}