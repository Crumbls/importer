<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\States\Concerns\HasStorageDriver;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\SchemaDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ColumnTypeAnalysisState extends AbstractState
{
    use HasStorageDriver;

	public function onEnter() : void {
	}
    public function execute(): bool
    {
        $record = $this->getRecord();
        $metadata = $record->metadata ?? [];
        $tableName = $metadata['target_table'] ?? 'csv_imports';

        // Skip if already mapped to new column types
        if (!empty($metadata['typed_table']) && !empty($metadata['typed_column_types'])) {
            Log::info('Column type analysis skipped; already mapped.', [
                'import_id' => $record->getKey(),
                'table' => $metadata['typed_table'],
                'types' => $metadata['typed_column_types']
            ]);

			$this->transitionToNextState($record);
            return true;
        }

        // Get table structure first
        $columns = $this->getStorageDriver()->getColumns($tableName);
        if (empty($columns)) {
			throw new \Exception('Table structure not found.');
        }

        // Sample rows for analysis (more efficient for large datasets)
        $sampleSize = min(1000, $this->getStorageDriver()->count($tableName));
        $rows = $this->getStorageDriver()->db()->table($tableName)->limit($sampleSize)->get();

        // Analyze types for each column using comprehensive statistics
        $columnTypes = [];
        $columnStats = [];
        
        foreach ($columns as $col) {
            $stats = $this->analyzeColumnStatistics($tableName, $col);
            $columnStats[$col] = $stats;
            $columnTypes[$col] = $this->inferColumnTypeFromStats($stats);
        }

        // Create new table with proper types
        $newTable = $tableName . '_typed';
        $this->createTypedTable($newTable, $columns, $columnTypes);

        // Copy data in batches to avoid memory issues
        $this->copyDataWithTypes($tableName, $newTable, $columns, $columnTypes);

        // Update metadata
        $metadata['typed_table'] = $newTable;
        $metadata['typed_column_types'] = $columnTypes;
        $metadata['original_table'] = $tableName;
        $record->update(['metadata' => $metadata]);

		$this->transitionToNextState($record);

        return true;
    }

    protected function createTypedTable(string $newTable, array $columns, array $columnTypes): void
    {
        $schema = new SchemaDefinition($newTable);
        foreach ($columns as $col) {
            $type = $columnTypes[$col];
            switch ($type) {
                case 'integer':
                    $schema->integer($col, ['nullable' => true]);
                    break;
                case 'bigInteger':
                    $schema->bigInteger($col, ['nullable' => true]);
                    break;
                case 'decimal':
                    $schema->decimal($col, ['precision' => 12, 'scale' => 4, 'nullable' => true]);
                    break;
                case 'float':
                    $schema->float($col, ['nullable' => true]);
                    break;
                case 'boolean':
                    $schema->boolean($col, ['nullable' => true]);
                    break;
                case 'datetime':
                    $schema->timestamp($col, ['nullable' => true]);
                    break;
                case 'date':
                    $schema->date($col, ['nullable' => true]);
                    break;
                case 'json':
                    $schema->json($col, ['nullable' => true]);
                    break;
                case 'string':
                    $schema->string($col, 255, ['nullable' => true]);
                    break;
                case 'longText':
                    $schema->longText($col, ['nullable' => true]);
                    break;
                default: // 'text'
                    $schema->text($col, ['nullable' => true]);
            }
        }

        // If table exists, add missing columns, else create new table
        if ($this->getStorageDriver()->tableExists($newTable)) {
            $existingCols = $this->getStorageDriver()->getColumns($newTable);
            $missing = array_diff($columns, $existingCols);
            foreach ($missing as $col) {
                $this->addColumnToTable($newTable, $col, $columnTypes[$col] ?? 'text');
            }
        } else {
            $this->getStorageDriver()->createTableFromSchema($newTable, $schema->toArray());
        }
    }

    protected function addColumnToTable(string $table, string $column, string $type): void
    {
        switch ($type) {
            case 'integer':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'integer', 'nullable' => true]);
                break;
            case 'bigInteger':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'bigInteger', 'nullable' => true]);
                break;
            case 'decimal':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'nullable' => true]);
                break;
            case 'float':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'float', 'nullable' => true]);
                break;
            case 'boolean':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'boolean', 'nullable' => true]);
                break;
            case 'datetime':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'timestamp', 'nullable' => true]);
                break;
            case 'date':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'date', 'nullable' => true]);
                break;
            case 'json':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'json', 'nullable' => true]);
                break;
            case 'string':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'string', 'length' => 255, 'nullable' => true]);
                break;
            case 'longText':
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'longText', 'nullable' => true]);
                break;
            default:
                $this->getStorageDriver()->addColumn($table, $column, ['type' => 'text', 'nullable' => true]);
        }
    }

    protected function analyzeColumnStatistics(string $tableName, string $column): array
    {
        $storage = $this->getStorageDriver();
        
        // Get basic counts
        $totalRows = $storage->count($tableName);
        $nullCount = $storage->countWhere($tableName, [$column => null]) + 
                    $storage->countWhere($tableName, [$column => '']);
        
        $nonNullCount = $totalRows - $nullCount;
        $nullPercentage = $totalRows > 0 ? ($nullCount / $totalRows) * 100 : 0;
        
        // Get distinct values count and sample
        $distinctCount = $storage->countDistinct($tableName, $column);
        $uniquePercentage = $nonNullCount > 0 ? ($distinctCount / $nonNullCount) * 100 : 0;
        
        // Get min/max values (as strings for now)
        $minValue = $storage->min($tableName, $column);
        $maxValue = $storage->max($tableName, $column);
        
        // Get sample of non-null values for pattern analysis
        $sampleSize = min(200, $nonNullCount);
        $sampleValues = $storage->sampleNonNull($tableName, $column, $sampleSize);
        
        // Analyze value patterns
        $patterns = $this->analyzeValuePatterns($sampleValues);
        
        // Calculate string length statistics for text analysis
        $lengths = array_map('strlen', $sampleValues);
        $avgLength = !empty($lengths) ? array_sum($lengths) / count($lengths) : 0;
        $minLength = !empty($lengths) ? min($lengths) : 0;
        $maxLength = !empty($lengths) ? max($lengths) : 0;
        
        return [
            'column' => $column,
            'total_rows' => $totalRows,
            'null_count' => $nullCount,
            'non_null_count' => $nonNullCount,
            'null_percentage' => round($nullPercentage, 2),
            'distinct_count' => $distinctCount,
            'unique_percentage' => round($uniquePercentage, 2),
            'min_value' => $minValue,
            'max_value' => $maxValue,
            'min_length' => $minLength,
            'max_length' => $maxLength,
            'avg_length' => round($avgLength, 1),
            'sample_size' => count($sampleValues),
            'patterns' => $patterns
        ];
    }

    protected function analyzeValuePatterns(array $values): array
    {
        if (empty($values)) {
            return [
                'integer_count' => 0,
                'decimal_count' => 0,
                'float_count' => 0,
                'boolean_count' => 0,
                'datetime_count' => 0,
                'date_count' => 0,
                'email_count' => 0,
                'url_count' => 0,
                'json_count' => 0,
                'text_count' => 0
            ];
        }

        $patterns = [
            'integer_count' => 0,
            'decimal_count' => 0,
            'float_count' => 0,
            'boolean_count' => 0,
            'datetime_count' => 0,
            'date_count' => 0,
            'email_count' => 0,
            'url_count' => 0,
            'json_count' => 0,
            'phone_count' => 0,
            'text_count' => 0
        ];

        foreach ($values as $value) {
            if (is_null($value) || $value === '') continue;
            
            $value = trim($value);
            
            // Integer (including negative)
            if (preg_match('/^-?\d+$/', $value)) {
                $patterns['integer_count']++;
            }
            // Decimal/Currency (19.99, $19.99, €1,234.56)
            elseif (preg_match('/^[\$£€¥]?-?\d{1,3}(?:[,\s]\d{3})*(?:\.\d{1,4})?$/', $value)) {
                $patterns['decimal_count']++;
            }
            // Float/Scientific notation
            elseif (is_numeric($value) && (strpos($value, '.') !== false || strpos(strtolower($value), 'e') !== false)) {
                $patterns['float_count']++;
            }
            // Boolean variations
            elseif (preg_match('/^(true|false|yes|no|y|n|0|1|on|off|enabled|disabled)$/i', $value)) {
                $patterns['boolean_count']++;
            }
            // Email
            elseif (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $patterns['email_count']++;
            }
            // URL
            elseif (filter_var($value, FILTER_VALIDATE_URL)) {
                $patterns['url_count']++;
            }
            // JSON
            elseif ($this->isValidJson($value)) {
                $patterns['json_count']++;
            }
            // Phone numbers (basic patterns)
            elseif (preg_match('/^[\+]?[\d\s\-\(\)]{7,15}$/', $value)) {
                $patterns['phone_count']++;
            }
            // DateTime
            elseif ($this->isDateTime($value)) {
                $patterns['datetime_count']++;
            }
            // Date only
            elseif ($this->isDateOnly($value)) {
                $patterns['date_count']++;
            }
            else {
                $patterns['text_count']++;
            }
        }

        return $patterns;
    }

    protected function isValidJson(string $value): bool
    {
        if (strlen($value) < 2 || (!str_starts_with($value, '{') && !str_starts_with($value, '['))) {
            return false;
        }
        
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function copyDataWithTypes(string $sourceTable, string $targetTable, array $columns, array $columnTypes): void
    {
        $batchSize = 500; // Process in batches to avoid memory issues
        $offset = 0;
        $totalRows = $this->getStorageDriver()->count($sourceTable);

        Log::info('Starting data copy with type casting', [
            'source' => $sourceTable,
            'target' => $targetTable,
            'total_rows' => $totalRows,
            'batch_size' => $batchSize
        ]);

        while ($offset < $totalRows) {
            $rows = $this->getStorageDriver()->limit($sourceTable, $batchSize, $offset);
            
            foreach ($rows as $row) {
                $typedRow = [];
                foreach ($columns as $col) {
                    $value = is_array($row) ? ($row[$col] ?? null) : (isset($row->$col) ? $row->$col : null);
                    $typedRow[$col] = $this->castValue($value, $columnTypes[$col]);
                }
                $this->getStorageDriver()->insert($targetTable, $typedRow);
            }

            $offset += $batchSize;
            
            // Log progress for large datasets
            if ($totalRows > 1000 && $offset % 5000 === 0) {
                Log::info('Data copy progress', [
                    'processed' => $offset,
                    'total' => $totalRows,
                    'percentage' => round(($offset / $totalRows) * 100, 1)
                ]);
            }
        }
    }
    protected function inferColumnTypeFromStats(array $stats): string
    {
        $patterns = $stats['patterns'];
        $totalSamples = $stats['sample_size'];
        
        if ($totalSamples === 0) {
            return 'text';
        }

        // High confidence threshold (90%) for pure types
        $highConfidence = $totalSamples * 0.9;
        // Medium confidence threshold (75%) for mixed numeric types
        $mediumConfidence = $totalSamples * 0.75;
        
        // Check for high-confidence single types
        if ($patterns['integer_count'] >= $highConfidence) {
            // Additional check for ID columns (high unique percentage + integer)
            if ($stats['unique_percentage'] > 95 && $stats['min_length'] > 0) {
                return 'bigInteger'; // Likely an ID column
            }
            return 'integer';
        }
        
        if ($patterns['decimal_count'] >= $highConfidence) {
            return 'decimal';
        }
        
        if ($patterns['float_count'] >= $highConfidence) {
            return 'float';
        }
        
        if ($patterns['boolean_count'] >= $highConfidence) {
            return 'boolean';
        }
        
        if ($patterns['email_count'] >= $highConfidence) {
            return 'string'; // Use string instead of text for emails (may want indexing)
        }
        
        if ($patterns['url_count'] >= $highConfidence) {
            return 'text'; // URLs can be long
        }
        
        if ($patterns['json_count'] >= $highConfidence) {
            return 'json';
        }
        
        if ($patterns['datetime_count'] >= $highConfidence) {
            return 'datetime';
        }
        
        if ($patterns['date_count'] >= $highConfidence) {
            return 'date';
        }

        // Handle mixed numeric types with medium confidence
        $totalNumeric = $patterns['integer_count'] + $patterns['decimal_count'] + $patterns['float_count'];
        if ($totalNumeric >= $mediumConfidence) {
            // If we have any decimals or floats, use the most permissive type
            if ($patterns['decimal_count'] > 0) {
                return 'decimal';
            }
            if ($patterns['float_count'] > 0) {
                return 'float';
            }
            return 'integer';
        }

        // Handle text with length considerations
        if ($stats['max_length'] <= 255 && $stats['avg_length'] <= 50) {
            // Short strings - good for varchar/string with indexing potential
            return 'string';
        }
        
        if ($stats['max_length'] > 65535) {
            // Very long text - use longText
            return 'longText';
        }
        
        if ($stats['max_length'] > 255) {
            // Medium text
            return 'text';
        }

        // Default to string for short values
        return 'string';
    }

    protected function inferColumnType(array $values): string
    {
        $nonNullValues = array_filter($values, function($v) {
            return !is_null($v) && $v !== '';
        });

        if (empty($nonNullValues)) {
            return 'text';
        }

        $totalValues = count($nonNullValues);
        $sampleSize = min(100, $totalValues); // Sample up to 100 values for analysis
        $sample = array_slice($nonNullValues, 0, $sampleSize);

        // Counters for different types
        $integerCount = 0;
        $floatCount = 0;
        $decimalCount = 0;
        $booleanCount = 0;
        $datetimeCount = 0;
        $dateCount = 0;
        $textCount = 0;

        foreach ($sample as $value) {
            $value = trim($value);
            
            // Integer check (including negative numbers)
            if (preg_match('/^-?\d+$/', $value)) {
                $integerCount++;
            }
            // Decimal/money check (e.g., 19.99, $19.99, 19,99)
            elseif (preg_match('/^[\$£€]?-?\d{1,3}(?:[,\s]\d{3})*(?:\.\d{1,4})?$/', $value)) {
                $decimalCount++;
            }
            // Float check (scientific notation, etc.)
            elseif (is_numeric($value) && strpos($value, '.') !== false) {
                $floatCount++;
            }
            // Boolean check (various formats)
            elseif (preg_match('/^(true|false|yes|no|y|n|0|1|on|off)$/i', $value)) {
                $booleanCount++;
            }
            // DateTime check (various formats)
            elseif ($this->isDateTime($value)) {
                $datetimeCount++;
            }
            // Date only check
            elseif ($this->isDateOnly($value)) {
                $dateCount++;
            }
            else {
                $textCount++;
            }
        }

        // Determine type based on majority (80% threshold for confidence)
        $threshold = $sampleSize * 0.8;

        if ($integerCount >= $threshold) {
            return 'integer';
        }
        if ($decimalCount >= $threshold) {
            return 'decimal';
        }
        if ($floatCount >= $threshold) {
            return 'float';
        }
        if ($booleanCount >= $threshold) {
            return 'boolean';
        }
        if ($datetimeCount >= $threshold) {
            return 'datetime';
        }
        if ($dateCount >= $threshold) {
            return 'date';
        }

        // Mixed numeric types - choose the most permissive
        if (($integerCount + $decimalCount + $floatCount) >= $threshold) {
            if ($decimalCount > 0 || $floatCount > 0) {
                return $decimalCount > $floatCount ? 'decimal' : 'float';
            }
            return 'integer';
        }

        return 'text';
    }

    protected function isDateTime(string $value): bool
    {
        // Common datetime patterns
        $patterns = [
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',  // 2025-01-01 12:00:00
            '/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/', // 01/01/2025 12:00:00
            '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/',  // 01-01-2025 12:00:00
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return strtotime($value) !== false;
            }
        }

        return false;
    }

    protected function isDateOnly(string $value): bool
    {
        // Common date-only patterns
        $patterns = [
            '/^\d{4}-\d{2}-\d{2}$/',  // 2025-01-01
            '/^\d{2}\/\d{2}\/\d{4}$/', // 01/01/2025
            '/^\d{2}-\d{2}-\d{4}$/',  // 01-01-2025
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return strtotime($value) !== false;
            }
        }

        return false;
    }

    protected function castValue($value, string $type)
    {
        if (is_null($value) || $value === '') return null;
        
        $value = trim($value);
        
        try {
            switch ($type) {
                case 'integer':
                case 'bigInteger':
                    return (int) preg_replace('/[^-\d]/', '', $value);
                
                case 'decimal':
                    // Remove currency symbols and formatting, keep decimal point
                    $cleaned = preg_replace('/[\$£€¥,\s]/', '', $value);
                    return (float) $cleaned;
                
                case 'float':
                    return (float) $value;
                
                case 'boolean':
                    $lowercaseValue = strtolower($value);
                    if (in_array($lowercaseValue, ['true', 'yes', 'y', 'on', '1', 'enabled'])) {
                        return true;
                    }
                    if (in_array($lowercaseValue, ['false', 'no', 'n', 'off', '0', 'disabled'])) {
                        return false;
                    }
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                
                case 'datetime':
                    $timestamp = strtotime($value);
                    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
                
                case 'date':
                    $timestamp = strtotime($value);
                    return $timestamp ? date('Y-m-d', $timestamp) : null;
                
                case 'json':
                    // Validate JSON before storing
                    $decoded = json_decode($value, true);
                    return json_last_error() === JSON_ERROR_NONE ? $value : null;
                
                case 'string':
                    return strlen($value) <= 255 ? (string) $value : substr($value, 0, 255);
                
                case 'longText':
                case 'text':
                default:
                    return (string) $value;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cast value', [
                'value' => $value,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
