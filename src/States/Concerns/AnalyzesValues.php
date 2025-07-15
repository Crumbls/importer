<?php

namespace Crumbls\Importer\States\Concerns;

use Illuminate\Support\Collection;

trait AnalyzesValues
{
    /**
     * Analyze a collection of values to determine optimal data types
     * 
     * @param Collection $values Collection of values to analyze
     * @return array Analysis results including type, breakdown, and recommendations
     */
    public function analyzeValues(Collection $values): array
    {
        if ($values->isEmpty()) {
            return [
                'type' => 'empty',
                'confidence' => 100,
                'breakdown' => [
                    'total_count' => 0,
                    'empty_count' => 0,
                    'null_count' => 0,
                    'unique_count' => 0,
                ],
                'sample_values' => [],
                'recommendations' => [
                    'primary_type' => 'text',
                    'alternatives' => [],
                    'notes' => 'No values to analyze'
                ]
            ];
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

        // Type analysis
        $typeBreakdown = $this->analyzeTypeBreakdown($nonEmptyValues);
        $dateTimeAnalysis = $this->analyzeDateTimeValues($nonEmptyValues);
        $numericAnalysis = $this->analyzeNumericValues($nonEmptyValues);
        $booleanAnalysis = $this->analyzeBooleanValues($nonEmptyValues);
        $jsonAnalysis = $this->analyzeJsonValues($nonEmptyValues);
        $urlAnalysis = $this->analyzeUrlValues($nonEmptyValues);
        $emailAnalysis = $this->analyzeEmailValues($nonEmptyValues);
        
        // Determine primary type and confidence
        $primaryType = $this->determinePrimaryType($typeBreakdown, $dateTimeAnalysis, $numericAnalysis, $booleanAnalysis, $jsonAnalysis, $urlAnalysis, $emailAnalysis);
        
        return [
            'type' => $primaryType['type'],
            'confidence' => $primaryType['confidence'],
            'breakdown' => [
                'total_count' => $totalCount,
                'empty_count' => $emptyCount,
                'null_count' => $nullCount,
                'unique_count' => $uniqueCount,
                'uniqueness_ratio' => $totalCount > 0 ? round($uniqueCount / $totalCount * 100, 2) : 0,
                'type_breakdown' => $typeBreakdown,
                'datetime_analysis' => $dateTimeAnalysis,
                'numeric_analysis' => $numericAnalysis,
                'boolean_analysis' => $booleanAnalysis,
                'json_analysis' => $jsonAnalysis,
                'url_analysis' => $urlAnalysis,
                'email_analysis' => $emailAnalysis,
            ],
            'sample_values' => $uniqueValues->take(10)->values()->toArray(),
            'recommendations' => $this->generateRecommendations($primaryType, $typeBreakdown, $uniqueCount, $totalCount)
        ];
    }
    
    /**
     * Analyze basic type breakdown
     */
    private function analyzeTypeBreakdown(Collection $values): array
    {
        $types = [];
        
        foreach ($values as $value) {
            $type = gettype($value);
            if ($type === 'string') {
                // Further classify strings
                if (is_numeric($value)) {
                    $type = 'numeric_string';
                } elseif (strlen($value) > 255) {
                    $type = 'long_text';
                } else {
                    $type = 'string';
                }
            }
            
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        
        return $types;
    }
    
    /**
     * Analyze datetime values
     */
    private function analyzeDateTimeValues(Collection $values): array
    {
        $dateTimeCount = 0;
        $formats = [];
        $samples = [];
        
        foreach ($values as $value) {
            if (!is_string($value)) continue;
            
            // Try various datetime formats
            $dateTimeFormats = [
                'Y-m-d H:i:s',
                'Y-m-d',
                'Y/m/d H:i:s',
                'Y/m/d',
                'm/d/Y H:i:s',
                'm/d/Y',
                'd/m/Y H:i:s',
                'd/m/Y',
                'Y-m-d\TH:i:s\Z',
                'Y-m-d\TH:i:s',
                'c', // ISO 8601
                'r', // RFC 2822
            ];
            
            foreach ($dateTimeFormats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    $dateTimeCount++;
                    $formats[$format] = ($formats[$format] ?? 0) + 1;
                    if (count($samples) < 5) {
                        $samples[] = $value;
                    }
                    break;
                }
            }
            
            // Try strtotime as fallback
            if (!$date && strtotime($value) !== false) {
                $dateTimeCount++;
                $formats['strtotime'] = ($formats['strtotime'] ?? 0) + 1;
                if (count($samples) < 5) {
                    $samples[] = $value;
                }
            }
        }
        
        $totalCount = $values->count();
        $percentage = $totalCount > 0 ? round($dateTimeCount / $totalCount * 100, 2) : 0;
        
        return [
            'datetime_count' => $dateTimeCount,
            'percentage' => $percentage,
            'formats' => $formats,
            'samples' => $samples,
            'is_likely_datetime' => $percentage > 80
        ];
    }
    
    /**
     * Analyze numeric values
     */
    private function analyzeNumericValues(Collection $values): array
    {
        $numericCount = 0;
        $integerCount = 0;
        $floatCount = 0;
        $min = null;
        $max = null;
        $sum = 0;
        
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $numericCount++;
                $numericValue = is_string($value) ? (float) $value : $value;
                
                if ($min === null || $numericValue < $min) {
                    $min = $numericValue;
                }
                if ($max === null || $numericValue > $max) {
                    $max = $numericValue;
                }
                
                $sum += $numericValue;
                
                if (is_int($value) || (is_string($value) && (int) $value == $value)) {
                    $integerCount++;
                } else {
                    $floatCount++;
                }
            }
        }
        
        $totalCount = $values->count();
        $percentage = $totalCount > 0 ? round($numericCount / $totalCount * 100, 2) : 0;
        $average = $numericCount > 0 ? $sum / $numericCount : null;
        
        return [
            'numeric_count' => $numericCount,
            'integer_count' => $integerCount,
            'float_count' => $floatCount,
            'percentage' => $percentage,
            'min' => $min,
            'max' => $max,
            'average' => $average,
            'is_likely_numeric' => $percentage > 80,
            'is_likely_integer' => $integerCount > 0 && $floatCount === 0,
            'is_likely_float' => $floatCount > 0
        ];
    }
    
    /**
     * Analyze boolean values
     */
    private function analyzeBooleanValues(Collection $values): array
    {
        $booleanCount = 0;
        $trueValues = ['true', '1', 'yes', 'on', 'y', 'enabled', 'active'];
        $falseValues = ['false', '0', 'no', 'off', 'n', 'disabled', 'inactive'];
        
        $trueCount = 0;
        $falseCount = 0;
        
        foreach ($values as $value) {
            if (is_bool($value)) {
                $booleanCount++;
                $value ? $trueCount++ : $falseCount++;
            } elseif (is_string($value)) {
                $lowerValue = strtolower(trim($value));
                if (in_array($lowerValue, $trueValues)) {
                    $booleanCount++;
                    $trueCount++;
                } elseif (in_array($lowerValue, $falseValues)) {
                    $booleanCount++;
                    $falseCount++;
                }
            }
        }
        
        $totalCount = $values->count();
        $percentage = $totalCount > 0 ? round($booleanCount / $totalCount * 100, 2) : 0;
        
        return [
            'boolean_count' => $booleanCount,
            'true_count' => $trueCount,
            'false_count' => $falseCount,
            'percentage' => $percentage,
            'is_likely_boolean' => $percentage > 80
        ];
    }
    
    /**
     * Analyze JSON values
     */
    private function analyzeJsonValues(Collection $values): array
    {
        $jsonCount = 0;
        $samples = [];
        
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) > 1) {
                $trimmed = trim($value);
                if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) ||
                    (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
                    
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $jsonCount++;
                        if (count($samples) < 3) {
                            $samples[] = $value;
                        }
                    }
                }
            }
        }
        
        $totalCount = $values->count();
        $percentage = $totalCount > 0 ? round($jsonCount / $totalCount * 100, 2) : 0;
        
        return [
            'json_count' => $jsonCount,
            'percentage' => $percentage,
            'samples' => $samples,
            'is_likely_json' => $percentage > 80
        ];
    }
    
    /**
     * Analyze URL values
     */
    private function analyzeUrlValues(Collection $values): array
    {
        $urlCount = 0;
        $samples = [];
        
        foreach ($values as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                $urlCount++;
                if (count($samples) < 5) {
                    $samples[] = $value;
                }
            }
        }
        
        $totalCount = $values->count();
        $percentage = $totalCount > 0 ? round($urlCount / $totalCount * 100, 2) : 0;
        
        return [
            'url_count' => $urlCount,
            'percentage' => $percentage,
            'samples' => $samples,
            'is_likely_url' => $percentage > 80
        ];
    }
    
    /**
     * Analyze email values
     */
    private function analyzeEmailValues(Collection $values): array
    {
        $emailCount = 0;
        $samples = [];
        
        foreach ($values as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emailCount++;
                if (count($samples) < 5) {
                    $samples[] = $value;
                }
            }
        }
        
        $totalCount = $values->count();
        $percentage = $totalCount > 0 ? round($emailCount / $totalCount * 100, 2) : 0;
        
        return [
            'email_count' => $emailCount,
            'percentage' => $percentage,
            'samples' => $samples,
            'is_likely_email' => $percentage > 80
        ];
    }
    
    /**
     * Determine primary type based on analysis
     */
    private function determinePrimaryType(array $typeBreakdown, array $dateTimeAnalysis, array $numericAnalysis, array $booleanAnalysis, array $jsonAnalysis, array $urlAnalysis, array $emailAnalysis): array
    {
        $candidates = [];
        
        // Check specialized types first (higher confidence)
        if ($dateTimeAnalysis['is_likely_datetime']) {
            $candidates[] = ['type' => 'datetime', 'confidence' => $dateTimeAnalysis['percentage']];
        }
        
        if ($emailAnalysis['is_likely_email']) {
            $candidates[] = ['type' => 'email', 'confidence' => $emailAnalysis['percentage']];
        }
        
        if ($urlAnalysis['is_likely_url']) {
            $candidates[] = ['type' => 'url', 'confidence' => $urlAnalysis['percentage']];
        }
        
        if ($jsonAnalysis['is_likely_json']) {
            $candidates[] = ['type' => 'json', 'confidence' => $jsonAnalysis['percentage']];
        }
        
        if ($booleanAnalysis['is_likely_boolean']) {
            $candidates[] = ['type' => 'boolean', 'confidence' => $booleanAnalysis['percentage']];
        }
        
        if ($numericAnalysis['is_likely_numeric']) {
            if ($numericAnalysis['is_likely_integer']) {
                $candidates[] = ['type' => 'integer', 'confidence' => $numericAnalysis['percentage']];
            } else {
                $candidates[] = ['type' => 'float', 'confidence' => $numericAnalysis['percentage']];
            }
        }
        
        // If no specialized type found, use basic type analysis
        if (empty($candidates)) {
            $dominantType = array_key_first($typeBreakdown) ?? 'string';
            $totalValues = array_sum($typeBreakdown);
            $confidence = $totalValues > 0 ? round($typeBreakdown[$dominantType] / $totalValues * 100, 2) : 0;
            
            $candidates[] = ['type' => $dominantType, 'confidence' => $confidence];
        }
        
        // Return the highest confidence candidate
        usort($candidates, function ($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });
        
        return $candidates[0];
    }
    
    /**
     * Generate recommendations based on analysis
     */
    private function generateRecommendations(array $primaryType, array $typeBreakdown, int $uniqueCount, int $totalCount): array
    {
        $recommendations = [
            'primary_type' => $primaryType['type'],
            'alternatives' => [],
            'notes' => []
        ];
        
        // Add alternative types
        if ($primaryType['confidence'] < 95) {
            $recommendations['alternatives'][] = 'text'; // Always a safe fallback
        }
        
        // Add specific notes based on type
        switch ($primaryType['type']) {
            case 'datetime':
                $recommendations['notes'][] = 'Consider timezone handling for datetime values';
                break;
                
            case 'integer':
            case 'float':
                if ($uniqueCount / $totalCount > 0.9) {
                    $recommendations['notes'][] = 'High uniqueness suggests this might be an ID field';
                }
                break;
                
            case 'string':
                if ($uniqueCount / $totalCount > 0.9) {
                    $recommendations['notes'][] = 'High uniqueness suggests this might be a unique identifier';
                } elseif ($uniqueCount / $totalCount < 0.1) {
                    $recommendations['notes'][] = 'Low uniqueness suggests this might be a category or enum field';
                }
                break;
                
            case 'json':
                $recommendations['notes'][] = 'Consider parsing JSON for more detailed field mapping';
                break;
        }
        
        return $recommendations;
    }
}