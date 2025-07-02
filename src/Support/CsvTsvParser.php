<?php

namespace Crumbls\Importer\Support;

class CsvTsvParser
{
    protected array $config = [];
    protected array $headers = [];
    protected array $data = [];
    protected array $columnTypes = [];
    protected array $statistics = [];
    protected string $detectedDelimiter = ',';
    protected string $detectedEnclosure = '"';
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_detect_delimiter' => true,
            'auto_detect_headers' => true,
            'auto_detect_types' => true,
            'sample_rows_for_detection' => 100,
            'memory_limit' => '256M',
            'chunk_size' => 1000,
            'skip_empty_rows' => true,
            'trim_whitespace' => true,
            'encoding' => 'UTF-8'
        ], $config);
    }
    
    public function parseFile(string $csvFilePath): array
    {
        if (!file_exists($csvFilePath)) {
            throw new \InvalidArgumentException("CSV file not found: {$csvFilePath}");
        }
        
        $this->reset();
        
        // Detect file encoding and convert if needed
        $content = $this->handleEncoding($csvFilePath);
        
        // Auto-detect CSV format
        if ($this->config['auto_detect_delimiter']) {
            $this->detectDelimiter($content);
        }
        
        // Parse the CSV file
        $this->parseCsvContent($csvFilePath);
        
        // Auto-detect headers
        if ($this->config['auto_detect_headers']) {
            $this->detectHeaders();
        }
        
        // Analyze column types
        if ($this->config['auto_detect_types']) {
            $this->analyzeColumnTypes();
        }
        
        // Generate WordPress-compatible data structure
        $wordpressData = $this->convertToWordPressFormat();
        
        // Calculate statistics
        $this->calculateStatistics();
        
        return $wordpressData;
    }
    
    public function parseString(string $csvContent, array $options = []): array
    {
        $this->config = array_merge($this->config, $options);
        $this->reset();
        
        // Auto-detect CSV format
        if ($this->config['auto_detect_delimiter']) {
            $this->detectDelimiter($csvContent);
        }
        
        // Parse content directly
        $lines = explode("\n", $csvContent);
        $this->parseLines($lines);
        
        // Process headers and types
        if ($this->config['auto_detect_headers']) {
            $this->detectHeaders();
        }
        
        if ($this->config['auto_detect_types']) {
            $this->analyzeColumnTypes();
        }
        
        return $this->convertToWordPressFormat();
    }
    
    protected function reset(): void
    {
        $this->headers = [];
        $this->data = [];
        $this->columnTypes = [];
        $this->statistics = [];
        $this->detectedDelimiter = ',';
        $this->detectedEnclosure = '"';
    }
    
    protected function handleEncoding(string $filePath): string
    {
        $content = file_get_contents($filePath);
        
        // Detect encoding
        $detected = mb_detect_encoding($content, ['UTF-8', 'UTF-16', 'ISO-8859-1', 'Windows-1252'], true);
        
        if ($detected && $detected !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $detected);
        }
        
        return $content;
    }
    
    protected function detectDelimiter(string $content): void
    {
        $delimiters = [
            'tab' => "\t",
            'comma' => ',',
            'semicolon' => ';',
            'pipe' => '|'
        ];
        
        $sample = substr($content, 0, 4096); // Sample first 4KB
        $lines = array_slice(explode("\n", $sample), 0, 10); // First 10 lines
        
        $scores = [];
        
        foreach ($delimiters as $name => $delimiter) {
            $columnCounts = [];
            
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                
                $columns = str_getcsv($line, $delimiter, $this->detectedEnclosure);
                $columnCounts[] = count($columns);
            }
            
            if (!empty($columnCounts)) {
                // Score based on consistency of column counts
                $avgColumns = array_sum($columnCounts) / count($columnCounts);
                $variance = 0;
                
                foreach ($columnCounts as $count) {
                    $variance += pow($count - $avgColumns, 2);
                }
                $variance /= count($columnCounts);
                
                // Lower variance = more consistent = better score
                // Also favor more columns (likely better structure)
                $scores[$name] = [
                    'delimiter' => $delimiter,
                    'avg_columns' => $avgColumns,
                    'variance' => $variance,
                    'score' => $avgColumns / (1 + $variance) // Higher = better
                ];
            }
        }
        
        if (!empty($scores)) {
            // Get delimiter with best score
            $bestDelimiter = array_reduce($scores, function($best, $current) {
                return $current['score'] > ($best['score'] ?? 0) ? $current : $best;
            });
            
            $this->detectedDelimiter = $bestDelimiter['delimiter'];
        }
    }
    
    protected function parseCsvContent(string $filePath): void
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }
        
        try {
            $rowIndex = 0;
            
            while (($row = fgetcsv($handle, 0, $this->detectedDelimiter, $this->detectedEnclosure)) !== false) {
                if ($this->config['skip_empty_rows'] && $this->isEmptyRow($row)) {
                    continue;
                }
                
                if ($this->config['trim_whitespace']) {
                    $row = array_map('trim', $row);
                }
                
                $this->data[] = $row;
                $rowIndex++;
                
                // Memory management for large files
                if ($rowIndex % $this->config['chunk_size'] === 0) {
                    $this->checkMemoryUsage();
                }
            }
        } finally {
            fclose($handle);
        }
    }
    
    protected function parseLines(array $lines): void
    {
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) && $this->config['skip_empty_rows']) {
                continue;
            }
            
            $row = str_getcsv($line, $this->detectedDelimiter, $this->detectedEnclosure);
            
            if ($this->config['trim_whitespace']) {
                $row = array_map('trim', $row);
            }
            
            $this->data[] = $row;
        }
    }
    
    protected function isEmptyRow(array $row): bool
    {
        return empty(array_filter($row, fn($cell) => !empty(trim($cell))));
    }
    
    protected function detectHeaders(): void
    {
        if (empty($this->data)) {
            return;
        }
        
        $firstRow = $this->data[0];
        $secondRow = $this->data[1] ?? null;
        
        // Heuristics to detect if first row is headers
        $isHeaders = false;
        
        if ($secondRow) {
            // Check if first row has different data types than second row
            $firstRowTypes = array_map([$this, 'inferDataType'], $firstRow);
            $secondRowTypes = array_map([$this, 'inferDataType'], $secondRow);
            
            // If first row is mostly strings and second row has mixed types, likely headers
            $firstStringCount = count(array_filter($firstRowTypes, fn($type) => $type === 'string'));
            $firstStringRatio = $firstStringCount / count($firstRowTypes);
            
            if ($firstStringRatio > 0.7) {
                $isHeaders = true;
            }
        }
        
        // Check for common header patterns
        $headerPatterns = [
            'id', 'name', 'title', 'content', 'description', 'author', 'date', 
            'category', 'tag', 'status', 'type', 'slug', 'url', 'email',
            'post_title', 'post_content', 'post_type', 'post_status', 'post_date'
        ];
        
        if ($firstRow) {
            $patternMatches = 0;
            foreach ($firstRow as $cell) {
                $cellLower = strtolower(trim($cell));
                if (in_array($cellLower, $headerPatterns) || 
                    preg_match('/^[a-z_][a-z0-9_]*$/', $cellLower)) {
                    $patternMatches++;
                }
            }
            
            if ($patternMatches / count($firstRow) > 0.5) {
                $isHeaders = true;
            }
        }
        
        if ($isHeaders) {
            $this->headers = array_shift($this->data);
        } else {
            // Generate generic headers
            $columnCount = count($this->data[0] ?? []);
            $this->headers = array_map(fn($i) => "column_" . ($i + 1), range(0, $columnCount - 1));
        }
    }
    
    protected function analyzeColumnTypes(): void
    {
        if (empty($this->data) || empty($this->headers)) {
            return;
        }
        
        $sampleSize = min(count($this->data), $this->config['sample_rows_for_detection']);
        $sampleData = array_slice($this->data, 0, $sampleSize);
        
        foreach ($this->headers as $index => $header) {
            $columnValues = array_column($sampleData, $index);
            $this->columnTypes[$header] = $this->analyzeColumnType($columnValues);
        }
    }
    
    protected function analyzeColumnType(array $values): array
    {
        $types = [];
        $patterns = [];
        $samples = [];
        
        foreach ($values as $value) {
            if (empty($value)) continue;
            
            $type = $this->inferDataType($value);
            $types[] = $type;
            $samples[] = $value;
            
            // Detect specific patterns
            $patterns = array_merge($patterns, $this->detectPatterns($value));
        }
        
        if (empty($types)) {
            return ['type' => 'string', 'patterns' => [], 'samples' => []];
        }
        
        // Determine dominant type
        $typeCounts = array_count_values($types);
        $dominantType = array_search(max($typeCounts), $typeCounts);
        
        return [
            'type' => $dominantType,
            'type_distribution' => $typeCounts,
            'patterns' => array_unique($patterns),
            'samples' => array_slice(array_unique($samples), 0, 5),
            'confidence' => max($typeCounts) / count($types)
        ];
    }
    
    protected function inferDataType($value): string
    {
        if (empty($value)) {
            return 'empty';
        }
        
        // Check for JSON
        if ($this->isJson($value)) {
            return 'json';
        }
        
        // Check for serialized data
        if ($this->isSerialized($value)) {
            return 'serialized';
        }
        
        // Check for numbers
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? 'decimal' : 'integer';
        }
        
        // Check for URLs
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return 'url';
        }
        
        // Check for emails
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        
        // Check for dates
        if ($this->isDate($value)) {
            return 'date';
        }
        
        // Check for boolean-like values
        if (in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'])) {
            return 'boolean';
        }
        
        // Check for HTML
        if ($this->containsHtml($value)) {
            return 'html';
        }
        
        return 'string';
    }
    
    protected function detectPatterns($value): array
    {
        $patterns = [];
        
        // WordPress-specific patterns
        if (preg_match('/^https?:\/\/.*\.(?:jpg|jpeg|png|gif|webp)$/i', $value)) {
            $patterns[] = 'image_url';
        }
        
        if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            $patterns[] = 'color_hex';
        }
        
        if (preg_match('/^-?\d+\.?\d*,-?\d+\.?\d*$/', $value)) {
            $patterns[] = 'coordinates';
        }
        
        if (preg_match('/^\$?\d+\.?\d*$/', $value)) {
            $patterns[] = 'price';
        }
        
        if (strlen($value) > 1000) {
            $patterns[] = 'long_text';
        }
        
        return $patterns;
    }
    
    protected function convertToWordPressFormat(): array
    {
        if (empty($this->data) || empty($this->headers)) {
            return ['posts' => [], 'postmeta' => []];
        }
        
        $posts = [];
        $postmeta = [];
        $postId = 1;
        
        // Identify WordPress core fields
        $coreFields = $this->identifyWordPressFields();
        
        foreach ($this->data as $rowIndex => $row) {
            $post = [];
            $meta = [];
            
            foreach ($this->headers as $columnIndex => $header) {
                $value = $row[$columnIndex] ?? '';
                
                if (isset($coreFields[$header])) {
                    // Map to WordPress core field
                    $post[$coreFields[$header]] = $value;
                } else {
                    // Treat as custom field
                    if (!empty($value)) {
                        $postmeta[] = [
                            'post_id' => $postId,
                            'meta_key' => $header,
                            'meta_value' => $value
                        ];
                    }
                }
            }
            
            // Ensure required WordPress fields
            $post = array_merge([
                'ID' => $postId,
                'post_title' => $post['post_title'] ?? "Imported Post {$postId}",
                'post_content' => $post['post_content'] ?? '',
                'post_type' => $post['post_type'] ?? 'post',
                'post_status' => $post['post_status'] ?? 'draft',
                'post_date' => $post['post_date'] ?? date('Y-m-d H:i:s'),
                'post_author' => $post['post_author'] ?? 1
            ], $post);
            
            $posts[] = $post;
            $postId++;
        }
        
        return [
            'posts' => $posts,
            'postmeta' => $postmeta,
            'statistics' => $this->statistics
        ];
    }
    
    protected function identifyWordPressFields(): array
    {
        $fieldMappings = [
            // Direct mappings
            'id' => 'ID',
            'post_id' => 'ID',
            'title' => 'post_title',
            'post_title' => 'post_title',
            'content' => 'post_content',
            'post_content' => 'post_content',
            'excerpt' => 'post_excerpt',
            'post_excerpt' => 'post_excerpt',
            'type' => 'post_type',
            'post_type' => 'post_type',
            'status' => 'post_status',
            'post_status' => 'post_status',
            'date' => 'post_date',
            'post_date' => 'post_date',
            'author' => 'post_author',
            'post_author' => 'post_author',
            'slug' => 'post_name',
            'post_name' => 'post_name',
            'post_slug' => 'post_name',
            'parent' => 'post_parent',
            'post_parent' => 'post_parent',
            'order' => 'menu_order',
            'menu_order' => 'menu_order',
            'guid' => 'guid',
            'url' => 'guid'
        ];
        
        $mappings = [];
        
        foreach ($this->headers as $header) {
            $headerLower = strtolower(trim($header));
            if (isset($fieldMappings[$headerLower])) {
                $mappings[$header] = $fieldMappings[$headerLower];
            }
        }
        
        return $mappings;
    }
    
    protected function calculateStatistics(): void
    {
        $this->statistics = [
            'total_rows' => count($this->data),
            'total_columns' => count($this->headers),
            'detected_delimiter' => $this->detectedDelimiter,
            'detected_encoding' => $this->config['encoding'],
            'headers' => $this->headers,
            'column_types' => $this->columnTypes,
            'wordpress_fields_detected' => count($this->identifyWordPressFields()),
            'custom_fields_detected' => count($this->headers) - count($this->identifyWordPressFields())
        ];
    }
    
    protected function checkMemoryUsage(): void
    {
        $usage = memory_get_usage(true);
        $limit = $this->parseMemoryLimit($this->config['memory_limit']);
        
        if ($usage > $limit * 0.9) {
            throw new \RuntimeException("Memory usage approaching limit. Consider processing file in smaller chunks.");
        }
    }
    
    protected function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtoupper(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $limit
        };
    }
    
    protected function isJson(string $value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    protected function isSerialized(string $value): bool
    {
        return @unserialize($value) !== false || $value === 'b:0;';
    }
    
    protected function isDate(string $value): bool
    {
        $timestamp = strtotime($value);
        return $timestamp !== false && 
               preg_match('/\d{4}-\d{2}-\d{2}/', $value) || 
               preg_match('/\d{1,2}\/\d{1,2}\/\d{4}/', $value) ||
               preg_match('/\d{1,2}-\d{1,2}-\d{4}/', $value);
    }
    
    protected function containsHtml(string $value): bool
    {
        return $value !== strip_tags($value);
    }
    
    public function getParsingReport(): array
    {
        return [
            'summary' => [
                'rows_parsed' => count($this->data),
                'columns_detected' => count($this->headers),
                'delimiter_detected' => $this->detectedDelimiter,
                'headers_detected' => !empty($this->headers)
            ],
            'headers' => $this->headers,
            'column_analysis' => $this->columnTypes,
            'wordpress_mapping' => $this->identifyWordPressFields(),
            'statistics' => $this->statistics
        ];
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public function getColumnTypes(): array
    {
        return $this->columnTypes;
    }
    
    public function getData(): array
    {
        return $this->data;
    }
    
    public function getDetectedDelimiter(): string
    {
        return $this->detectedDelimiter;
    }
}