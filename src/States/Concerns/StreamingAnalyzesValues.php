<?php

namespace Crumbls\Importer\States\Concerns;

use Crumbls\Importer\Support\MemoryManager;
use Illuminate\Support\Collection;

trait StreamingAnalyzesValues
{
    /**
     * Memory-efficient streaming analysis for large datasets
     * 
     * @param callable $dataProvider Function that yields data batches
     * @param int $maxSamples Maximum samples to analyze
     * @param MemoryManager|null $memoryManager Optional memory monitoring
     * @return array Analysis results
     */
    public function analyzeValuesStreaming(
        callable $dataProvider, 
        int $maxSamples = 5000,
        ?MemoryManager $memoryManager = null
    ): array {
        $analyzer = new StreamingDataAnalyzer($maxSamples, $memoryManager);
        
        // Process data in batches
        foreach ($dataProvider() as $batch) {
            if ($analyzer->processBatch($batch)) {
                break; // Enough samples collected
            }
            
            // Memory pressure check
            if ($memoryManager && $memoryManager->shouldReduceBatchSize()) {
                $memoryManager->triggerCleanup();
            }
        }
        
        return $analyzer->getResults();
    }
    
    /**
     * Memory-optimized version of the original analyzeValues method
     * Uses streaming approach for large collections
     */
    public function analyzeValues(Collection $values): array
    {
        // For small collections, use original fast method
        if ($values->count() <= 1000) {
            return $this->analyzeValuesOriginal($values);
        }
        
        // For large collections, use streaming approach
        return $this->analyzeValuesStreaming(function() use ($values) {
            yield from $values->chunk(500);
        });
    }
    
    /**
     * Original analysis method for small datasets (fast path)
     */
    private function analyzeValuesOriginal(Collection $values): array
    {
        if ($values->isEmpty()) {
            return $this->getEmptyAnalysisResult();
        }

        $totalCount = $values->count();
        $nonEmptyValues = $values->filter(function ($value) {
            return !is_null($value) && $value !== '' && $value !== 0;
        });
        
        $uniqueValues = $nonEmptyValues->unique();
        $uniqueCount = $uniqueValues->count();
        
        // Basic stats
        $emptyCount = $values->filter(function ($value) {
            return $value === '' || $value === 0;
        })->count();
        
        $nullCount = $values->filter(function ($value) {
            return is_null($value);
        })->count();

        $uniquenessRatio = $totalCount > 0 ? ($uniqueCount / $totalCount) * 100 : 0;
        
        // Get sample values (limited to prevent memory issues)
        $sampleValues = $uniqueValues->take(10)->values()->toArray();
        
        // Analyze data types
        $typeAnalysis = $this->performTypeAnalysis($nonEmptyValues->take(1000));
        
        return [
            'type' => $typeAnalysis['primary_type'],
            'confidence' => $typeAnalysis['confidence'],
            'breakdown' => [
                'total_count' => $totalCount,
                'empty_count' => $emptyCount,
                'null_count' => $nullCount,
                'unique_count' => $uniqueCount,
                'uniqueness_ratio' => round($uniquenessRatio, 2),
                'type_analysis' => $typeAnalysis,
            ],
            'sample_values' => $sampleValues,
            'recommendations' => $this->generateRecommendations($typeAnalysis, $uniquenessRatio, $totalCount)
        ];
    }
    
    /**
     * Generate empty analysis result
     */
    private function getEmptyAnalysisResult(): array
    {
        return [
            'type' => 'empty',
            'confidence' => 100,
            'breakdown' => [
                'total_count' => 0,
                'empty_count' => 0,
                'null_count' => 0,
                'unique_count' => 0,
                'uniqueness_ratio' => 0,
            ],
            'sample_values' => [],
            'recommendations' => [
                'primary_type' => 'text',
                'alternatives' => [],
                'notes' => 'No values to analyze'
            ]
        ];
    }
    
    /**
     * Perform type analysis on a collection of values
     */
    private function performTypeAnalysis(Collection $values): array
    {
        $typeCounters = [
            'string' => 0,
            'integer' => 0,
            'float' => 0,
            'boolean' => 0,
            'datetime' => 0,
            'json' => 0,
            'url' => 0,
            'email' => 0,
        ];
        
        $totalAnalyzed = 0;
        $sampleSize = min(1000, $values->count());
        
        foreach ($values->take($sampleSize) as $value) {
            $totalAnalyzed++;
            
            if ($this->isDateTime($value)) {
                $typeCounters['datetime']++;
            } elseif ($this->isJson($value)) {
                $typeCounters['json']++;
            } elseif ($this->isUrl($value)) {
                $typeCounters['url']++;
            } elseif ($this->isEmail($value)) {
                $typeCounters['email']++;
            } elseif ($this->isBoolean($value)) {
                $typeCounters['boolean']++;
            } elseif (is_numeric($value)) {
                if (is_float($value + 0) && !is_int($value + 0)) {
                    $typeCounters['float']++;
                } else {
                    $typeCounters['integer']++;
                }
            } else {
                $typeCounters['string']++;
            }
        }
        
        // Find primary type
        $primaryType = array_keys($typeCounters, max($typeCounters))[0];
        $confidence = $totalAnalyzed > 0 ? round(($typeCounters[$primaryType] / $totalAnalyzed) * 100, 2) : 0;
        
        return [
            'primary_type' => $primaryType,
            'confidence' => $confidence,
            'type_breakdown' => $typeCounters,
            'sample_size' => $totalAnalyzed,
        ];
    }
    
    /**
     * Generate recommendations based on analysis
     */
    private function generateRecommendations(array $typeAnalysis, float $uniquenessRatio, int $totalCount): array
    {
        $primaryType = $typeAnalysis['primary_type'];
        $confidence = $typeAnalysis['confidence'];
        
        $recommendations = [
            'primary_type' => $primaryType,
            'alternatives' => [],
            'notes' => []
        ];
        
        // High uniqueness suggestions
        if ($uniquenessRatio > 80) {
            $recommendations['notes'][] = 'High uniqueness ratio suggests this might be an identifier field';
            if ($primaryType === 'string') {
                $recommendations['alternatives'][] = 'Consider UUID or unique string index';
            }
        }
        
        // Low confidence suggestions
        if ($confidence < 60) {
            $recommendations['notes'][] = 'Mixed data types detected - consider data cleaning';
            $recommendations['alternatives'][] = 'text'; // Safe fallback
        }
        
        // Type-specific recommendations
        switch ($primaryType) {
            case 'datetime':
                $recommendations['notes'][] = 'Consider timezone handling for datetime fields';
                break;
            case 'json':
                $recommendations['notes'][] = 'JSON data detected - consider using JSON column type';
                break;
            case 'url':
                $recommendations['notes'][] = 'URL data detected - consider validation rules';
                break;
            case 'email':
                $recommendations['notes'][] = 'Email data detected - consider email validation';
                break;
        }
        
        return $recommendations;
    }
    
    // Type detection helper methods
    private function isDateTime($value): bool
    {
        if (!is_string($value)) return false;
        
        $patterns = [
            '/^\d{4}-\d{2}-\d{2}$/',                    // Y-m-d
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', // Y-m-d H:i:s
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',  // ISO 8601
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return strtotime($value) !== false;
            }
        }
        
        return false;
    }
    
    private function isJson($value): bool
    {
        if (!is_string($value)) return false;
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    private function isUrl($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    private function isEmail($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    private function isBoolean($value): bool
    {
        return in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off']);
    }
}

/**
 * Streaming data analyzer that processes data in batches to minimize memory usage
 */
class StreamingDataAnalyzer
{
    private int $maxSamples;
    private int $currentSamples = 0;
    private array $typeCounters = [];
    private array $sampleValues = [];
    private int $totalCount = 0;
    private int $emptyCount = 0;
    private int $nullCount = 0;
    private array $uniqueValues = [];
    private ?MemoryManager $memoryManager;
    
    public function __construct(int $maxSamples = 5000, ?MemoryManager $memoryManager = null)
    {
        $this->maxSamples = $maxSamples;
        $this->memoryManager = $memoryManager;
        $this->initializeCounters();
    }
    
    private function initializeCounters(): void
    {
        $this->typeCounters = [
            'string' => 0,
            'integer' => 0,
            'float' => 0,
            'boolean' => 0,
            'datetime' => 0,
            'json' => 0,
            'url' => 0,
            'email' => 0,
        ];
    }
    
    /**
     * Process a batch of data
     * 
     * @param Collection $batch
     * @return bool True if enough samples collected
     */
    public function processBatch(Collection $batch): bool
    {
        foreach ($batch as $value) {
            if ($this->currentSamples >= $this->maxSamples) {
                return true; // Enough samples
            }
            
            $this->analyzeValue($value);
            $this->totalCount++;
            
            // Memory pressure check every 100 values
            if ($this->memoryManager && $this->totalCount % 100 === 0) {
                $this->memoryManager->monitor();
                
                if ($this->memoryManager->shouldReduceBatchSize()) {
                    // Trigger cleanup and possibly reduce sampling
                    $this->optimizeForMemory();
                }
            }
        }
        
        return false; // Need more samples
    }
    
    private function analyzeValue($value): void
    {
        // Count nulls and empties
        if (is_null($value)) {
            $this->nullCount++;
            return;
        }
        
        if ($value === '' || $value === 0) {
            $this->emptyCount++;
            return;
        }
        
        // Only analyze non-empty values for type detection
        $this->currentSamples++;
        
        // Track unique values (with memory limit)
        $valueKey = (string)$value;
        if (count($this->uniqueValues) < 10000) { // Limit unique value tracking
            $this->uniqueValues[$valueKey] = true;
        }
        
        // Store sample values (limited)
        if (count($this->sampleValues) < 10) {
            $this->sampleValues[] = $value;
        }
        
        // Type analysis
        $this->analyzeValueType($value);
    }
    
    private function analyzeValueType($value): void
    {
        if ($this->isDateTime($value)) {
            $this->typeCounters['datetime']++;
        } elseif ($this->isJson($value)) {
            $this->typeCounters['json']++;
        } elseif ($this->isUrl($value)) {
            $this->typeCounters['url']++;
        } elseif ($this->isEmail($value)) {
            $this->typeCounters['email']++;
        } elseif ($this->isBoolean($value)) {
            $this->typeCounters['boolean']++;
        } elseif (is_numeric($value)) {
            if (is_float($value + 0) && !is_int($value + 0)) {
                $this->typeCounters['float']++;
            } else {
                $this->typeCounters['integer']++;
            }
        } else {
            $this->typeCounters['string']++;
        }
    }
    
    private function optimizeForMemory(): void
    {
        // Reduce unique value tracking if memory pressure
        if (count($this->uniqueValues) > 5000) {
            $this->uniqueValues = array_slice($this->uniqueValues, 0, 2500, true);
        }
        
        // Reduce sample collection
        if (count($this->sampleValues) > 5) {
            $this->sampleValues = array_slice($this->sampleValues, 0, 5);
        }
    }
    
    public function getResults(): array
    {
        if ($this->currentSamples === 0) {
            return [
                'type' => 'empty',
                'confidence' => 100,
                'breakdown' => [
                    'total_count' => $this->totalCount,
                    'empty_count' => $this->emptyCount,
                    'null_count' => $this->nullCount,
                    'unique_count' => 0,
                    'uniqueness_ratio' => 0,
                ],
                'sample_values' => [],
                'recommendations' => [
                    'primary_type' => 'text',
                    'alternatives' => [],
                    'notes' => 'No non-empty values to analyze'
                ]
            ];
        }
        
        // Find primary type
        $primaryType = array_keys($this->typeCounters, max($this->typeCounters))[0];
        $confidence = round(($this->typeCounters[$primaryType] / $this->currentSamples) * 100, 2);
        
        $uniqueCount = count($this->uniqueValues);
        $uniquenessRatio = $this->totalCount > 0 ? ($uniqueCount / $this->totalCount) * 100 : 0;
        
        return [
            'type' => $primaryType,
            'confidence' => $confidence,
            'breakdown' => [
                'total_count' => $this->totalCount,
                'empty_count' => $this->emptyCount,
                'null_count' => $this->nullCount,
                'unique_count' => $uniqueCount,
                'uniqueness_ratio' => round($uniquenessRatio, 2),
                'type_analysis' => [
                    'primary_type' => $primaryType,
                    'confidence' => $confidence,
                    'type_breakdown' => $this->typeCounters,
                    'sample_size' => $this->currentSamples,
                ],
                'sampling_info' => [
                    'is_sampled' => $this->totalCount > $this->maxSamples,
                    'sample_size' => $this->currentSamples,
                    'total_processed' => $this->totalCount,
                ]
            ],
            'sample_values' => $this->sampleValues,
            'recommendations' => $this->generateRecommendations($primaryType, $confidence, $uniquenessRatio)
        ];
    }
    
    private function generateRecommendations(string $primaryType, float $confidence, float $uniquenessRatio): array
    {
        $recommendations = [
            'primary_type' => $primaryType,
            'alternatives' => [],
            'notes' => []
        ];
        
        // High uniqueness suggestions
        if ($uniquenessRatio > 80) {
            $recommendations['notes'][] = 'High uniqueness suggests this might be an identifier field';
        }
        
        // Low confidence suggestions
        if ($confidence < 60) {
            $recommendations['notes'][] = 'Mixed data types detected - consider data cleaning';
            $recommendations['alternatives'][] = 'text';
        }
        
        return $recommendations;
    }
    
    // Helper methods (same as in trait)
    private function isDateTime($value): bool
    {
        if (!is_string($value)) return false;
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) && strtotime($value) !== false;
    }
    
    private function isJson($value): bool
    {
        if (!is_string($value)) return false;
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    private function isUrl($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    private function isEmail($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    private function isBoolean($value): bool
    {
        return in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no']);
    }
}