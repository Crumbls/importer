<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Crumbls\Importer\Storage\StorageReader;

/**
 * Universal Schema Analyzer
 * 
 * Works with ANY storage format (CSV, XML, WordPress, etc.)
 * Analyzes data structure regardless of original source format
 */
class UniversalSchemaAnalyzer
{
    protected array $typeDetectors;
    protected array $patternAnalyzers;
    
    public function __construct()
    {
        $this->initializeDetectors();
    }
    
    public function analyze(PipelineContext $context): array
    {
        $storage = $context->get('temporary_storage');
        if (!$storage) {
            throw new \RuntimeException('No storage available for schema analysis');
        }
        
        $reader = new StorageReader($storage);
        $headers = $reader->getHeaders();
        
        if (empty($headers)) {
            throw new \RuntimeException('No headers available for schema analysis');
        }
        
        // Analyze data types by sampling records
        $analysis = $this->analyzeDataStructure($reader, $headers, $context);
        
        // Determine table/model naming
        $naming = $this->determineNaming($context);
        
        // Build complete schema analysis
        return [
            'source_type' => $this->detectSourceType($context),
            'table_name' => $naming['table_name'],
            'model_name' => $naming['model_name'],
            'fields' => $analysis['fields'],
            'relationships' => $analysis['relationships'],
            'indexes' => $analysis['suggested_indexes'],
            'fillable' => $analysis['fillable_fields'],
            'casts' => $analysis['type_casts'],
            'validation_rules' => $analysis['validation_rules'],
            'metadata' => [
                'total_records' => $reader->count(),
                'sample_size' => $analysis['sample_size'],
                'analysis_confidence' => $analysis['confidence_score'],
                'detected_patterns' => $analysis['patterns']
            ]
        ];
    }
    
    protected function analyzeDataStructure(StorageReader $reader, array $headers, PipelineContext $context): array
    {
        $sampleSize = $this->getSampleSize($context);
        $fieldAnalysis = $this->initializeFieldAnalysis($headers);
        
        // Sample data for comprehensive analysis
        $sampledCount = 0;
        $reader->chunk(100, function($rows) use (&$fieldAnalysis, &$sampledCount, $sampleSize) {
            foreach ($rows as $row) {
                if ($sampledCount >= $sampleSize) {
                    return false; // Stop sampling
                }
                
                $this->analyzeRow($row, $fieldAnalysis);
                $sampledCount++;
            }
        });
        
        // Finalize analysis with confidence scoring
        return $this->finalizeAnalysis($fieldAnalysis, $sampledCount);
    }
    
    protected function initializeFieldAnalysis(array $headers): array
    {
        $analysis = [];
        
        foreach ($headers as $header) {
            $analysis[$header] = [
                'samples' => [],
                'null_count' => 0,
                'empty_count' => 0,
                'max_length' => 0,
                'min_length' => PHP_INT_MAX,
                'total_length' => 0,
                'detected_types' => [],
                'pattern_matches' => [],
                'unique_values' => [],
                'value_frequency' => [],
                'is_unique' => true,
                'seen_values' => []
            ];
        }
        
        return $analysis;
    }
    
    protected function analyzeRow(array $row, array &$fieldAnalysis): void
    {
        foreach ($row as $field => $value) {
            if (!isset($fieldAnalysis[$field])) {
                continue;
            }
            
            $analysis = &$fieldAnalysis[$field];
            
            // Handle null/empty values
            if ($value === null) {
                $analysis['null_count']++;
                continue;
            }
            
            if (empty($value) || $value === '') {
                $analysis['empty_count']++;
                continue;
            }
            
            // Store sample and analyze
            if (count($analysis['samples']) < 50) {
                $analysis['samples'][] = $value;
            }
            
            // Length analysis
            $length = strlen($value);
            $analysis['max_length'] = max($analysis['max_length'], $length);
            $analysis['min_length'] = min($analysis['min_length'], $length);
            $analysis['total_length'] += $length;
            
            // Type detection
            foreach ($this->typeDetectors as $type => $detector) {
                if ($detector($value)) {
                    $analysis['detected_types'][$type] = ($analysis['detected_types'][$type] ?? 0) + 1;
                }
            }
            
            // Pattern analysis
            foreach ($this->patternAnalyzers as $pattern => $analyzer) {
                if ($analyzer($value)) {
                    $analysis['pattern_matches'][$pattern] = ($analysis['pattern_matches'][$pattern] ?? 0) + 1;
                }
            }
            
            // Uniqueness tracking
            if (in_array($value, $analysis['seen_values'])) {
                $analysis['is_unique'] = false;
            } else {
                $analysis['seen_values'][] = $value;
            }
            
            // Value frequency (for enum detection)
            $analysis['value_frequency'][$value] = ($analysis['value_frequency'][$value] ?? 0) + 1;
        }
    }
    
    protected function finalizeAnalysis(array $fieldAnalysis, int $sampledCount): array
    {
        $fields = [];
        $relationships = [];
        $indexes = [];
        $fillable = [];
        $casts = [];
        $validationRules = [];
        $patterns = [];
        $confidenceScore = 0;
        
        foreach ($fieldAnalysis as $fieldName => $analysis) {
            // Determine primary type
            $primaryType = $this->determinePrimaryType($analysis, $sampledCount);
            
            // Generate field definition
            $field = [
                'name' => $fieldName,
                'type' => $primaryType['laravel_type'],
                'php_type' => $primaryType['php_type'],
                'nullable' => $this->isNullable($analysis, $sampledCount),
                'length' => $this->determineLength($analysis, $primaryType),
                'unique' => $this->isUnique($analysis, $sampledCount),
                'index' => $this->shouldIndex($fieldName, $analysis, $primaryType),
                'confidence' => $primaryType['confidence']
            ];
            
            $fields[] = $field;
            
            // Build other arrays
            if (!in_array($fieldName, ['id', 'created_at', 'updated_at'])) {
                $fillable[] = $fieldName;
            }
            
            if ($cast = $this->determineCast($primaryType)) {
                $casts[$fieldName] = $cast;
            }
            
            $validationRules[$fieldName] = $this->generateValidationRules($field, $analysis);
            
            // Relationship detection
            if ($relationship = $this->detectRelationship($fieldName, $primaryType)) {
                $relationships[] = $relationship;
            }
            
            // Index suggestions
            if ($field['index'] || $field['unique']) {
                $indexes[] = [
                    'field' => $fieldName,
                    'type' => $field['unique'] ? 'unique' : 'index',
                    'reason' => $this->getIndexReason($field, $analysis)
                ];
            }
            
            // Pattern tracking
            if (!empty($analysis['pattern_matches'])) {
                $patterns[$fieldName] = array_keys($analysis['pattern_matches']);
            }
            
            $confidenceScore += $field['confidence'];
        }
        
        return [
            'fields' => $fields,
            'relationships' => $relationships,
            'suggested_indexes' => $indexes,
            'fillable_fields' => $fillable,
            'type_casts' => $casts,
            'validation_rules' => $validationRules,
            'sample_size' => $sampledCount,
            'confidence_score' => round($confidenceScore / count($fieldAnalysis), 2),
            'patterns' => $patterns
        ];
    }
    
    protected function initializeDetectors(): void
    {
        $this->typeDetectors = [
            'integer' => fn($v) => ctype_digit($v) || (is_numeric($v) && (int)$v == $v),
            'decimal' => fn($v) => is_numeric($v) && (float)$v != (int)$v,
            'boolean' => fn($v) => in_array(strtolower($v), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off']),
            'email' => fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
            'url' => fn($v) => filter_var($v, FILTER_VALIDATE_URL) !== false,
            'date' => fn($v) => strtotime($v) !== false && preg_match('/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}/', $v),
            'json' => function($v) {
                json_decode($v);
                return json_last_error() === JSON_ERROR_NONE;
            },
            'uuid' => fn($v) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v),
            'phone' => fn($v) => preg_match('/[\(\)\-\s\d\+]{10,}/', $v),
            'ip_address' => fn($v) => filter_var($v, FILTER_VALIDATE_IP) !== false,
        ];
        
        $this->patternAnalyzers = [
            'foreign_key' => fn($v) => preg_match('/^\d+$/', $v) && strlen($v) <= 11, // Looks like an ID
            'slug' => fn($v) => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v),
            'name_pattern' => fn($v) => preg_match('/^[A-Z][a-z]+(?: [A-Z][a-z]+)*$/', $v),
            'code_pattern' => fn($v) => preg_match('/^[A-Z0-9_-]+$/', $v),
            'enum_like' => fn($v) => strlen($v) <= 50 && !is_numeric($v),
        ];
    }
    
    protected function determinePrimaryType(array $analysis, int $totalSamples): array
    {
        $typeScores = [];
        $totalNonEmpty = $totalSamples - $analysis['null_count'] - $analysis['empty_count'];
        
        if ($totalNonEmpty === 0) {
            return ['laravel_type' => 'string', 'php_type' => 'string', 'confidence' => 0];
        }
        
        // Score each detected type
        foreach ($analysis['detected_types'] as $type => $count) {
            $typeScores[$type] = $count / $totalNonEmpty;
        }
        
        // Find the highest scoring type
        arsort($typeScores);
        $primaryType = array_key_first($typeScores) ?: 'string';
        $confidence = $typeScores[$primaryType] ?? 0;
        
        // Map to Laravel types
        $laravelType = match($primaryType) {
            'integer' => 'integer',
            'decimal' => 'decimal',
            'boolean' => 'boolean',
            'email' => 'string',
            'url' => 'text',
            'date' => 'timestamp',
            'json' => 'json',
            'uuid' => 'uuid',
            default => 'string'
        };
        
        $phpType = match($primaryType) {
            'integer' => 'int',
            'decimal' => 'float',
            'boolean' => 'bool',
            'date' => '\Carbon\Carbon',
            'json' => 'array',
            default => 'string'
        };
        
        return [
            'laravel_type' => $laravelType,
            'php_type' => $phpType,
            'confidence' => round($confidence * 100, 2),
            'detected_type' => $primaryType
        ];
    }
    
    protected function detectSourceType(PipelineContext $context): string
    {
        $source = $context->get('source_file', '');
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        
        return match($extension) {
            'csv', 'tsv' => 'csv',
            'xml' => 'xml',
            'json' => 'json',
            default => 'unknown'
        };
    }
    
    protected function determineNaming(PipelineContext $context): array
    {
        // Try explicit configuration first
        $tableName = $context->get('table_name');
        $modelName = $context->get('model_name');
        
        if ($tableName && $modelName) {
            return ['table_name' => $tableName, 'model_name' => $modelName];
        }
        
        // Generate from source
        $source = $context->get('source_file', '');
        $filename = pathinfo($source, PATHINFO_FILENAME);
        
        // Clean filename for table name
        $tableName = $tableName ?: \Illuminate\Support\Str::plural(
            \Illuminate\Support\Str::snake($filename)
        );
        
        $modelName = $modelName ?: \Illuminate\Support\Str::studly(
            \Illuminate\Support\Str::singular($tableName)
        );
        
        return [
            'table_name' => $tableName ?: 'imported_data',
            'model_name' => $modelName ?: 'ImportedData'
        ];
    }
    
    protected function getSampleSize(PipelineContext $context): int
    {
        $config = $context->get('analysis_options', []);
        return $config['sample_size'] ?? 1000;
    }
    
    protected function isNullable(array $analysis, int $totalSamples): bool
    {
        $nullPercentage = ($analysis['null_count'] + $analysis['empty_count']) / $totalSamples;
        return $nullPercentage > 0.1; // If more than 10% null/empty
    }
    
    protected function isUnique(array $analysis, int $totalSamples): bool
    {
        return $analysis['is_unique'] && $totalSamples > 10;
    }
    
    protected function shouldIndex(string $fieldName, array $analysis, array $primaryType): bool
    {
        // Index if unique
        if ($analysis['is_unique']) {
            return true;
        }
        
        // Index common searchable fields
        $searchableFields = ['email', 'username', 'slug', 'code', 'sku'];
        foreach ($searchableFields as $searchable) {
            if (str_contains(strtolower($fieldName), $searchable)) {
                return true;
            }
        }
        
        // Index foreign keys
        if (str_ends_with($fieldName, '_id') && $primaryType['detected_type'] === 'integer') {
            return true;
        }
        
        return false;
    }
    
    protected function determineCast(array $primaryType): ?string
    {
        return match($primaryType['detected_type']) {
            'integer' => 'integer',
            'decimal' => 'decimal:2',
            'boolean' => 'boolean',
            'date' => 'datetime',
            'json' => 'array',
            default => null
        };
    }
    
    protected function determineLength(array $analysis, array $primaryType): ?int
    {
        if ($primaryType['laravel_type'] !== 'string') {
            return null;
        }
        
        $maxLength = $analysis['max_length'];
        
        return match(true) {
            $maxLength <= 50 => 50,
            $maxLength <= 100 => 100,
            $maxLength <= 255 => 255,
            default => null // Use TEXT
        };
    }
    
    protected function detectRelationship(string $fieldName, array $primaryType): ?array
    {
        if (!str_ends_with($fieldName, '_id') || $primaryType['detected_type'] !== 'integer') {
            return null;
        }
        
        $relatedModel = \Illuminate\Support\Str::studly(
            str_replace('_id', '', $fieldName)
        );
        
        return [
            'type' => 'belongsTo',
            'related_model' => $relatedModel,
            'foreign_key' => $fieldName,
            'method_name' => \Illuminate\Support\Str::camel(str_replace('_id', '', $fieldName))
        ];
    }
    
    protected function generateValidationRules(array $field, array $analysis): array
    {
        $rules = [];
        
        // Nullable or required
        if ($field['nullable']) {
            $rules[] = 'nullable';
        } else {
            $rules[] = 'required';
        }
        
        // Type-specific rules
        switch ($field['type']) {
            case 'string':
                if ($field['length']) {
                    $rules[] = 'max:' . $field['length'];
                }
                break;
            case 'integer':
                $rules[] = 'integer';
                break;
            case 'decimal':
                $rules[] = 'numeric';
                break;
            case 'boolean':
                $rules[] = 'boolean';
                break;
            case 'timestamp':
                $rules[] = 'date';
                break;
        }
        
        // Pattern-specific rules
        if (str_contains($field['name'], 'email')) {
            $rules[] = 'email';
        }
        
        if (str_contains($field['name'], 'url')) {
            $rules[] = 'url';
        }
        
        if ($field['unique']) {
            $rules[] = 'unique:' . ($field['table_name'] ?? 'table');
        }
        
        return $rules;
    }
    
    protected function getIndexReason(array $field, array $analysis): string
    {
        if ($field['unique']) {
            return 'Unique values detected';
        }
        
        if (str_contains($field['name'], 'email')) {
            return 'Email field - frequently searched';
        }
        
        if (str_ends_with($field['name'], '_id')) {
            return 'Foreign key relationship';
        }
        
        return 'Frequently queried field';
    }
}