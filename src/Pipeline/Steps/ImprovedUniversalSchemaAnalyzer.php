<?php

declare(strict_types=1);

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Types\SchemaTypes;

/**
 * Improved Universal Schema Analyzer with PHPStan Level 4 compliance
 * 
 * @phpstan-import-type SchemaAnalysis from SchemaTypes
 * @phpstan-import-type FieldDefinition from SchemaTypes
 * @phpstan-import-type FieldAnalysis from SchemaTypes
 * @phpstan-import-type TypeDetector from SchemaTypes
 * @phpstan-import-type PatternAnalyzer from SchemaTypes
 */
class ImprovedUniversalSchemaAnalyzer
{
    /** @var array<string, TypeDetector> */
    private array $typeDetectors;
    
    /** @var array<string, PatternAnalyzer> */
    private array $patternAnalyzers;
    
    private const DEFAULT_SAMPLE_SIZE = 1000;
    private const MIN_CONFIDENCE_THRESHOLD = 0.7;
    private const UNIQUE_THRESHOLD = 0.9;
    
    public function __construct()
    {
        $this->initializeDetectors();
    }
    
    /**
     * @return SchemaAnalysis
     */
    public function analyze(PipelineContext $context): array
    {
        $storage = $context->get('temporary_storage');
        if ($storage === null) {
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
    
    /**
     * @param list<string> $headers
     * @return array{
     *     fields: list<FieldDefinition>,
     *     relationships: list<array{type: string, related_model: string, foreign_key: string, method_name: string}>,
     *     suggested_indexes: list<array{field: string, type: 'unique'|'index', reason: string}>,
     *     fillable_fields: list<string>,
     *     type_casts: array<string, string>,
     *     validation_rules: array<string, list<string>>,
     *     sample_size: int,
     *     confidence_score: float,
     *     patterns: array<string, list<string>>
     * }
     */
    private function analyzeDataStructure(StorageReader $reader, array $headers, PipelineContext $context): array
    {
        $sampleSize = $this->getSampleSize($context);
        $fieldAnalysis = $this->initializeFieldAnalysis($headers);
        
        // Sample data for comprehensive analysis
        $sampledCount = 0;
        $reader->chunk(100, function(array $rows) use (&$fieldAnalysis, &$sampledCount, $sampleSize): bool {
            foreach ($rows as $row) {
                if ($sampledCount >= $sampleSize) {
                    return false; // Stop sampling
                }
                
                $this->analyzeRow($row, $fieldAnalysis);
                $sampledCount++;
            }
            return true;
        });
        
        // Finalize analysis with confidence scoring
        return $this->finalizeAnalysis($fieldAnalysis, $sampledCount);
    }
    
    /**
     * @param list<string> $headers
     * @return array<string, FieldAnalysis>
     */
    private function initializeFieldAnalysis(array $headers): array
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
    
    /**
     * @param array<string, mixed> $row
     * @param array<string, FieldAnalysis> $fieldAnalysis
     */
    private function analyzeRow(array $row, array &$fieldAnalysis): void
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
            
            $stringValue = (string) $value;
            if ($stringValue === '') {
                $analysis['empty_count']++;
                continue;
            }
            
            // Store sample and analyze
            if (count($analysis['samples']) < 50) {
                $analysis['samples'][] = $stringValue;
            }
            
            // Length analysis
            $length = strlen($stringValue);
            $analysis['max_length'] = max($analysis['max_length'], $length);
            $analysis['min_length'] = min($analysis['min_length'], $length);
            $analysis['total_length'] += $length;
            
            // Type detection
            foreach ($this->typeDetectors as $type => $detector) {
                if ($detector($stringValue)) {
                    $analysis['detected_types'][$type] = ($analysis['detected_types'][$type] ?? 0) + 1;
                }
            }
            
            // Pattern analysis
            foreach ($this->patternAnalyzers as $pattern => $analyzer) {
                if ($analyzer($stringValue)) {
                    $analysis['pattern_matches'][$pattern] = ($analysis['pattern_matches'][$pattern] ?? 0) + 1;
                }
            }
            
            // Uniqueness tracking
            if (in_array($stringValue, $analysis['seen_values'], true)) {
                $analysis['is_unique'] = false;
            } else {
                $analysis['seen_values'][] = $stringValue;
            }
            
            // Value frequency (for enum detection)
            $analysis['value_frequency'][$stringValue] = ($analysis['value_frequency'][$stringValue] ?? 0) + 1;
        }
    }
    
    /**
     * @param array<string, FieldAnalysis> $fieldAnalysis
     * @return array{
     *     fields: list<FieldDefinition>,
     *     relationships: list<array{type: string, related_model: string, foreign_key: string, method_name: string}>,
     *     suggested_indexes: list<array{field: string, type: 'unique'|'index', reason: string}>,
     *     fillable_fields: list<string>,
     *     type_casts: array<string, string>,
     *     validation_rules: array<string, list<string>>,
     *     sample_size: int,
     *     confidence_score: float,
     *     patterns: array<string, list<string>>
     * }
     */
    private function finalizeAnalysis(array $fieldAnalysis, int $sampledCount): array
    {
        $fields = [];
        $relationships = [];
        $indexes = [];
        $fillable = [];
        $casts = [];
        $validationRules = [];
        $patterns = [];
        $confidenceScore = 0.0;
        
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
            if (!in_array($fieldName, ['id', 'created_at', 'updated_at'], true)) {
                $fillable[] = $fieldName;
            }
            
            $cast = $this->determineCast($primaryType);
            if ($cast !== null) {
                $casts[$fieldName] = $cast;
            }
            
            $validationRules[$fieldName] = $this->generateValidationRules($field, $analysis);
            
            // Relationship detection
            $relationship = $this->detectRelationship($fieldName, $primaryType);
            if ($relationship !== null) {
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
            'confidence_score' => count($fieldAnalysis) > 0 ? round($confidenceScore / count($fieldAnalysis), 2) : 0.0,
            'patterns' => $patterns
        ];
    }
    
    private function initializeDetectors(): void
    {
        $this->typeDetectors = [
            'integer' => static fn(string $v): bool => ctype_digit($v) || (is_numeric($v) && (int)$v == $v),
            'decimal' => static fn(string $v): bool => is_numeric($v) && (float)$v != (int)$v,
            'boolean' => static fn(string $v): bool => in_array(strtolower($v), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true),
            'email' => static fn(string $v): bool => filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
            'url' => static fn(string $v): bool => filter_var($v, FILTER_VALIDATE_URL) !== false,
            'date' => static fn(string $v): bool => strtotime($v) !== false && preg_match('/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}/', $v) === 1,
            'json' => static function(string $v): bool {
                json_decode($v);
                return json_last_error() === JSON_ERROR_NONE;
            },
            'uuid' => static fn(string $v): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1,
            'phone' => static fn(string $v): bool => preg_match('/[\(\)\-\s\d\+]{10,}/', $v) === 1,
            'ip_address' => static fn(string $v): bool => filter_var($v, FILTER_VALIDATE_IP) !== false,
        ];
        
        $this->patternAnalyzers = [
            'foreign_key' => static fn(string $v): bool => preg_match('/^\d+$/', $v) === 1 && strlen($v) <= 11,
            'slug' => static fn(string $v): bool => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v) === 1,
            'name_pattern' => static fn(string $v): bool => preg_match('/^[A-Z][a-z]+(?: [A-Z][a-z]+)*$/', $v) === 1,
            'code_pattern' => static fn(string $v): bool => preg_match('/^[A-Z0-9_-]+$/', $v) === 1,
            'enum_like' => static fn(string $v): bool => strlen($v) <= 50 && !is_numeric($v),
        ];
    }
    
    /**
     * @param FieldAnalysis $analysis
     * @return array{laravel_type: string, php_type: string, confidence: float, detected_type: string}
     */
    private function determinePrimaryType(array $analysis, int $totalSamples): array
    {
        $typeScores = [];
        $totalNonEmpty = $totalSamples - $analysis['null_count'] - $analysis['empty_count'];
        
        if ($totalNonEmpty === 0) {
            return ['laravel_type' => 'string', 'php_type' => 'string', 'confidence' => 0.0, 'detected_type' => 'string'];
        }
        
        // Score each detected type
        foreach ($analysis['detected_types'] as $type => $count) {
            $typeScores[$type] = $count / $totalNonEmpty;
        }
        
        // Find the highest scoring type
        arsort($typeScores);
        $primaryType = array_key_first($typeScores) ?? 'string';
        $confidence = $typeScores[$primaryType] ?? 0.0;
        
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
    
    private function detectSourceType(PipelineContext $context): string
    {
        $source = $context->get('source_file', '');
        if (!is_string($source)) {
            return 'unknown';
        }
        
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        
        return match($extension) {
            'csv', 'tsv' => 'csv',
            'xml' => 'xml',
            'json' => 'json',
            default => 'unknown'
        };
    }
    
    /**
     * @return array{table_name: string, model_name: string}
     */
    private function determineNaming(PipelineContext $context): array
    {
        // Try explicit configuration first
        $tableName = $context->get('table_name');
        $modelName = $context->get('model_name');
        
        if (is_string($tableName) && is_string($modelName)) {
            return ['table_name' => $tableName, 'model_name' => $modelName];
        }
        
        // Generate from source
        $source = $context->get('source_file', '');
        if (!is_string($source)) {
            return ['table_name' => 'imported_data', 'model_name' => 'ImportedData'];
        }
        
        $filename = pathinfo($source, PATHINFO_FILENAME);
        
        // Clean filename for table name
        $generatedTableName = \Illuminate\Support\Str::plural(
            \Illuminate\Support\Str::snake($filename)
        );
        
        $generatedModelName = \Illuminate\Support\Str::studly(
            \Illuminate\Support\Str::singular($generatedTableName)
        );
        
        return [
            'table_name' => is_string($tableName) ? $tableName : ($generatedTableName ?: 'imported_data'),
            'model_name' => is_string($modelName) ? $modelName : ($generatedModelName ?: 'ImportedData')
        ];
    }
    
    private function getSampleSize(PipelineContext $context): int
    {
        $config = $context->get('analysis_options', []);
        if (!is_array($config)) {
            return self::DEFAULT_SAMPLE_SIZE;
        }
        
        return is_int($config['sample_size'] ?? null) ? $config['sample_size'] : self::DEFAULT_SAMPLE_SIZE;
    }
    
    /**
     * @param FieldAnalysis $analysis
     */
    private function isNullable(array $analysis, int $totalSamples): bool
    {
        if ($totalSamples === 0) {
            return true;
        }
        
        $nullPercentage = ($analysis['null_count'] + $analysis['empty_count']) / $totalSamples;
        return $nullPercentage > 0.1; // If more than 10% null/empty
    }
    
    /**
     * @param FieldAnalysis $analysis
     */
    private function isUnique(array $analysis, int $totalSamples): bool
    {
        return $analysis['is_unique'] && $totalSamples > 10;
    }
    
    /**
     * @param FieldAnalysis $analysis
     * @param array{laravel_type: string, php_type: string, confidence: float, detected_type: string} $primaryType
     */
    private function shouldIndex(string $fieldName, array $analysis, array $primaryType): bool
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
    
    /**
     * @param array{laravel_type: string, php_type: string, confidence: float, detected_type: string} $primaryType
     */
    private function determineCast(array $primaryType): ?string
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
    
    /**
     * @param array{laravel_type: string, php_type: string, confidence: float, detected_type: string} $primaryType
     * @param FieldAnalysis $analysis
     */
    private function determineLength(array $analysis, array $primaryType): ?int
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
    
    /**
     * @param array{laravel_type: string, php_type: string, confidence: float, detected_type: string} $primaryType
     * @return array{type: string, related_model: string, foreign_key: string, method_name: string}|null
     */
    private function detectRelationship(string $fieldName, array $primaryType): ?array
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
    
    /**
     * @param FieldDefinition $field
     * @param FieldAnalysis $analysis
     * @return list<string>
     */
    private function generateValidationRules(array $field, array $analysis): array
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
                if ($field['length'] !== null) {
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
            $rules[] = 'unique:table'; // Will be replaced with actual table name
        }
        
        return $rules;
    }
    
    /**
     * @param FieldDefinition $field
     * @param FieldAnalysis $analysis
     */
    private function getIndexReason(array $field, array $analysis): string
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