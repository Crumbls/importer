<?php

namespace Crumbls\Importer\Support;

class PostTypeAnalyzer
{
    protected array $postTypes = [];
    protected array $schemas = [];
    protected array $statistics = [];
    
    public function analyze(array $wordPressData): self
    {
        $this->reset();
        
        // Extract posts and postmeta
        $posts = $wordPressData['posts'] ?? [];
        $postmeta = $wordPressData['postmeta'] ?? [];
        
        // Group posts by post_type
        $this->groupPostsByType($posts);
        
        // Analyze postmeta for each post type
        $this->analyzePostMeta($postmeta);
        
        // Generate schemas
        $this->generateSchemas();
        
        // Calculate statistics
        $this->calculateStatistics();
        
        return $this;
    }
    
    public function getPostTypes(): array
    {
        return array_keys($this->postTypes);
    }
    
    public function getPostsForType(string $postType): array
    {
        return $this->postTypes[$postType] ?? [];
    }
    
    public function getSchema(string $postType): ?array
    {
        return $this->schemas[$postType] ?? null;
    }
    
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }
    
    public function getStatistics(): array
    {
        return $this->statistics;
    }
    
    public function getDetailedReport(): array
    {
        return [
            'post_types' => $this->getPostTypesSummary(),
            'schemas' => $this->schemas,
            'statistics' => $this->statistics,
            'field_analysis' => $this->getFieldAnalysis(),
            'recommendations' => $this->getRecommendations()
        ];
    }
    
    protected function reset(): void
    {
        $this->postTypes = [];
        $this->schemas = [];
        $this->statistics = [];
    }
    
    protected function groupPostsByType(array $posts): void
    {
        foreach ($posts as $post) {
            $postType = $post['post_type'] ?? 'post';
            
            if (!isset($this->postTypes[$postType])) {
                $this->postTypes[$postType] = [];
            }
            
            $this->postTypes[$postType][] = $post;
        }
    }
    
    protected function analyzePostMeta(array $postmeta): void
    {
        // Group postmeta by post_id for easier lookup
        $metaByPost = [];
        foreach ($postmeta as $meta) {
            $postId = $meta['post_id'] ?? null;
            $metaKey = $meta['meta_key'] ?? null;
            $metaValue = $meta['meta_value'] ?? null;
            
            if (!$postId || !$metaKey) {
                continue; // Skip invalid meta records
            }
            
            if (!isset($metaByPost[$postId])) {
                $metaByPost[$postId] = [];
            }
            $metaByPost[$postId][$metaKey] = $metaValue;
        }
        
        // Analyze meta fields for each post type
        foreach ($this->postTypes as $postType => $posts) {
            $this->analyzePostTypeFields($postType, $posts, $metaByPost);
        }
    }
    
    protected function analyzePostTypeFields(string $postType, array $posts, array $metaByPost): void
    {
        $fieldFrequency = [];
        $fieldValues = [];
        $fieldTypes = [];
        
        foreach ($posts as $post) {
            $postId = $post['ID'] ?? $post['id'] ?? null;
            if (!$postId || !isset($metaByPost[$postId])) {
                continue;
            }
            
            $meta = $metaByPost[$postId];
            
            foreach ($meta as $key => $value) {
                // Track field frequency (include internal fields for analysis)
                if (!isset($fieldFrequency[$key])) {
                    $fieldFrequency[$key] = 0;
                }
                $fieldFrequency[$key]++;
                
                // Collect field values for analysis
                if (!isset($fieldValues[$key])) {
                    $fieldValues[$key] = [];
                }
                $fieldValues[$key][] = $value;
                
                // Determine field type
                $fieldTypes[$key] = $this->determineFieldType($value, $fieldTypes[$key] ?? null);
            }
        }
        
        // Separate internal and custom fields
        $internalFields = [];
        $customFields = [];
        
        foreach ($fieldFrequency as $key => $frequency) {
            if (strpos($key, '_') === 0) {
                $internalFields[$key] = $frequency;
            } else {
                $customFields[$key] = $frequency;
            }
        }
        
        // Store analysis results
        $this->schemas[$postType] = [
            'post_count' => count($posts),
            'fields' => $this->buildFieldSchema($fieldFrequency, $fieldValues, $fieldTypes, count($posts)),
            'custom_fields' => $this->buildFieldSchema($customFields, $fieldValues, $fieldTypes, count($posts)),
            'internal_fields' => $this->buildFieldSchema($internalFields, $fieldValues, $fieldTypes, count($posts)),
            'core_fields' => $this->getCorePosts(),
            'meta_fields' => array_keys($fieldFrequency),
            'custom_field_names' => array_keys($customFields),
            'internal_field_names' => array_keys($internalFields)
        ];
    }
    
    protected function buildFieldSchema(array $frequency, array $values, array $types, int $totalPosts): array
    {
        $fields = [];
        
        foreach ($frequency as $field => $count) {
            $coverage = ($count / $totalPosts) * 100;
            $fieldValues = $values[$field];
            
            $fields[$field] = [
                'name' => $field,
                'type' => $types[$field],
                'frequency' => $count,
                'coverage_percentage' => round($coverage, 2),
                'is_common' => $coverage >= 80, // Field appears in 80%+ of posts
                'is_occasional' => $coverage >= 20 && $coverage < 80, // 20-80%
                'is_rare' => $coverage < 20, // Less than 20%
                'sample_values' => array_slice(array_unique($fieldValues), 0, 5),
                'unique_values' => count(array_unique($fieldValues)),
                'max_length' => max(array_map('strlen', $fieldValues)),
                'analysis' => $this->analyzeFieldPattern($field, $fieldValues)
            ];
        }
        
        // Sort by coverage percentage descending
        uasort($fields, fn($a, $b) => $b['coverage_percentage'] <=> $a['coverage_percentage']);
        
        return $fields;
    }
    
    protected function determineFieldType($value, ?string $currentType = null): string
    {
        // If we already determined it's mixed, keep it mixed
        if ($currentType === 'mixed') {
            return 'mixed';
        }
        
        $newType = $this->inferTypeFromValue($value);
        
        // If we have a current type and it doesn't match, it's mixed
        if ($currentType && $currentType !== $newType) {
            return 'mixed';
        }
        
        return $newType;
    }
    
    protected function inferTypeFromValue($value): string
    {
        if (is_null($value) || $value === '') {
            return 'string'; // Default for empty values
        }
        
        // Check for JSON
        if (is_string($value) && $this->isJson($value)) {
            return 'json';
        }
        
        // Check for serialized data
        if (is_string($value) && $this->isSerialized($value)) {
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
        if (strtotime($value) !== false && preg_match('/\d{4}-\d{2}-\d{2}/', $value)) {
            return 'date';
        }
        
        // Check for boolean-like values
        if (in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'])) {
            return 'boolean';
        }
        
        return 'string';
    }
    
    protected function analyzeFieldPattern(string $fieldName, array $values): array
    {
        $analysis = [
            'likely_acf' => false,
            'likely_woocommerce' => false,
            'likely_seo' => false,
            'likely_custom' => false,
            'pattern_hints' => []
        ];
        
        // ACF patterns
        if (strpos($fieldName, 'field_') === 0 || 
            in_array($fieldName, ['gallery', 'image', 'file', 'repeater', 'flexible_content'])) {
            $analysis['likely_acf'] = true;
            $analysis['pattern_hints'][] = 'ACF field detected';
        }
        
        // WooCommerce patterns
        if (strpos($fieldName, '_product_') !== false || 
            strpos($fieldName, '_order_') !== false ||
            in_array($fieldName, ['_price', '_regular_price', '_sale_price', '_stock', '_sku', '_weight'])) {
            $analysis['likely_woocommerce'] = true;
            $analysis['pattern_hints'][] = 'WooCommerce field detected';
        }
        
        // SEO patterns
        if (strpos($fieldName, '_yoast_') !== false || 
            strpos($fieldName, 'seo_') !== false ||
            in_array($fieldName, ['meta_description', 'meta_keywords', 'canonical_url'])) {
            $analysis['likely_seo'] = true;
            $analysis['pattern_hints'][] = 'SEO field detected';
        }
        
        // Custom field patterns
        if (!$analysis['likely_acf'] && !$analysis['likely_woocommerce'] && !$analysis['likely_seo']) {
            $analysis['likely_custom'] = true;
            $analysis['pattern_hints'][] = 'Custom field';
        }
        
        // Value pattern analysis
        $this->analyzeValuePatterns($values, $analysis);
        
        return $analysis;
    }
    
    protected function analyzeValuePatterns(array $values, array &$analysis): void
    {
        $sampleSize = min(20, count($values));
        $sample = array_slice($values, 0, $sampleSize);
        
        foreach ($sample as $value) {
            // Gallery/image patterns
            if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $value)) {
                $analysis['pattern_hints'][] = 'Contains image references';
                break;
            }
            
            // Price patterns
            if (preg_match('/^\$?\d+\.?\d*$/', $value)) {
                $analysis['pattern_hints'][] = 'Likely price/monetary value';
                break;
            }
            
            // Color patterns
            if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
                $analysis['pattern_hints'][] = 'Contains color codes';
                break;
            }
            
            // Coordinate patterns
            if (preg_match('/^-?\d+\.?\d*,-?\d+\.?\d*$/', $value)) {
                $analysis['pattern_hints'][] = 'Likely coordinates';
                break;
            }
        }
    }
    
    protected function generateSchemas(): void
    {
        foreach ($this->schemas as $postType => &$schema) {
            // Separate fields by importance (use custom fields for migration)
            $allFields = $schema['fields'];
            $customFields = $schema['custom_fields'];
            
            $commonFields = array_filter($allFields, fn($field) => $field['is_common']);
            $occasionalFields = array_filter($allFields, fn($field) => $field['is_occasional']);
            $rareFields = array_filter($allFields, fn($field) => $field['is_rare']);
            
            // For migration, focus on custom fields
            $commonCustomFields = array_filter($customFields, fn($field) => $field['is_common']);
            $occasionalCustomFields = array_filter($customFields, fn($field) => $field['is_occasional']);
            
            // Generate Laravel migration-style schema (using custom fields)
            $schema['migration_schema'] = $this->generateMigrationSchema($postType, $commonCustomFields, $occasionalCustomFields);
            
            // Generate model attributes suggestion (using custom fields)
            $schema['model_attributes'] = $this->generateModelAttributes($commonCustomFields, $occasionalCustomFields);
            
            // Add field categories
            $schema['field_categories'] = [
                'common' => array_keys($commonFields),
                'occasional' => array_keys($occasionalFields),
                'rare' => array_keys($rareFields)
            ];
        }
    }
    
    protected function generateMigrationSchema(string $postType, array $commonFields, array $occasionalFields): array
    {
        $schema = [
            'table_name' => $postType . 's',
            'common_fields' => [],
            'optional_fields' => []
        ];
        
        // Add common fields (required)
        foreach ($commonFields as $field) {
            $schema['common_fields'][] = $this->fieldToMigrationColumn($field);
        }
        
        // Add occasional fields (nullable)
        foreach ($occasionalFields as $field) {
            $column = $this->fieldToMigrationColumn($field);
            $column['nullable'] = true;
            $schema['optional_fields'][] = $column;
        }
        
        return $schema;
    }
    
    protected function fieldToMigrationColumn(array $field): array
    {
        $column = [
            'name' => $field['name'],
            'type' => $this->mapTypeToMigration($field['type']),
            'length' => $this->suggestColumnLength($field)
        ];
        
        // Add indexes for common lookup fields
        if ($field['coverage_percentage'] > 50 && in_array($field['type'], ['string', 'integer'])) {
            $column['index'] = true;
        }
        
        return $column;
    }
    
    protected function mapTypeToMigration(string $type): string
    {
        return match ($type) {
            'integer' => 'integer',
            'decimal' => 'decimal',
            'boolean' => 'boolean',
            'date' => 'timestamp',
            'url', 'email' => 'string',
            'json' => 'json',
            'serialized' => 'text',
            default => 'string'
        };
    }
    
    protected function suggestColumnLength(array $field): ?int
    {
        if ($field['type'] === 'string') {
            $maxLength = $field['max_length'];
            
            if ($maxLength <= 50) return 50;
            if ($maxLength <= 255) return 255;
            if ($maxLength <= 1000) return 1000;
            return null; // Use TEXT
        }
        
        return null;
    }
    
    protected function generateModelAttributes(array $commonFields, array $occasionalFields): array
    {
        $attributes = [
            'fillable' => [],
            'casts' => [],
            'relationships' => []
        ];
        
        $allFields = array_merge($commonFields, $occasionalFields);
        
        foreach ($allFields as $field) {
            $attributes['fillable'][] = $field['name'];
            
            // Add casts for special types
            switch ($field['type']) {
                case 'json':
                    $attributes['casts'][$field['name']] = 'array';
                    break;
                case 'boolean':
                    $attributes['casts'][$field['name']] = 'boolean';
                    break;
                case 'date':
                    $attributes['casts'][$field['name']] = 'datetime';
                    break;
                case 'decimal':
                    $attributes['casts'][$field['name']] = 'decimal:2';
                    break;
            }
        }
        
        return $attributes;
    }
    
    protected function calculateStatistics(): void
    {
        $this->statistics = [
            'total_post_types' => count($this->postTypes),
            'total_posts' => array_sum(array_map('count', $this->postTypes)),
            'post_type_distribution' => array_map('count', $this->postTypes),
            'average_fields_per_type' => $this->calculateAverageFields(),
            'common_field_patterns' => $this->findCommonPatterns(),
            'plugin_detection' => $this->detectPlugins()
        ];
    }
    
    protected function calculateAverageFields(): float
    {
        $totalFields = 0;
        $totalTypes = count($this->schemas);
        
        foreach ($this->schemas as $schema) {
            $totalFields += count($schema['fields']);
        }
        
        return $totalTypes > 0 ? round($totalFields / $totalTypes, 2) : 0;
    }
    
    protected function findCommonPatterns(): array
    {
        $patterns = [];
        
        foreach ($this->schemas as $postType => $schema) {
            foreach ($schema['fields'] as $field) {
                if ($field['is_common']) {
                    $patterns[] = $field['name'];
                }
            }
        }
        
        return array_unique($patterns);
    }
    
    protected function detectPlugins(): array
    {
        $plugins = [
            'acf' => false,
            'woocommerce' => false,
            'yoast' => false,
            'elementor' => false,
            'custom_fields' => false
        ];
        
        foreach ($this->schemas as $schema) {
            foreach ($schema['fields'] as $field) {
                if ($field['analysis']['likely_acf']) {
                    $plugins['acf'] = true;
                }
                if ($field['analysis']['likely_woocommerce']) {
                    $plugins['woocommerce'] = true;
                }
                if ($field['analysis']['likely_seo']) {
                    $plugins['yoast'] = true;
                }
                if (strpos($field['name'], 'elementor') !== false) {
                    $plugins['elementor'] = true;
                }
                if ($field['analysis']['likely_custom']) {
                    $plugins['custom_fields'] = true;
                }
            }
        }
        
        return $plugins;
    }
    
    protected function getPostTypesSummary(): array
    {
        $summary = [];
        
        foreach ($this->postTypes as $postType => $posts) {
            $schema = $this->schemas[$postType] ?? [];
            
            $summary[$postType] = [
                'count' => count($posts),
                'fields_count' => count($schema['fields'] ?? []),
                'common_fields' => count($schema['field_categories']['common'] ?? []),
                'has_custom_fields' => !empty($schema['meta_fields']),
                'complexity' => $this->calculateComplexity($schema)
            ];
        }
        
        return $summary;
    }
    
    protected function calculateComplexity(array $schema): string
    {
        $fieldCount = count($schema['fields'] ?? []);
        
        if ($fieldCount === 0) return 'simple';
        if ($fieldCount <= 5) return 'moderate';
        if ($fieldCount <= 15) return 'complex';
        return 'very_complex';
    }
    
    protected function getFieldAnalysis(): array
    {
        $analysis = [
            'field_types_distribution' => [],
            'coverage_distribution' => [],
            'plugin_field_counts' => []
        ];
        
        foreach ($this->schemas as $schema) {
            foreach ($schema['fields'] as $field) {
                // Type distribution
                $type = $field['type'];
                $analysis['field_types_distribution'][$type] = ($analysis['field_types_distribution'][$type] ?? 0) + 1;
                
                // Coverage distribution
                $coverage = $field['coverage_percentage'];
                if ($coverage >= 80) {
                    $analysis['coverage_distribution']['common'] = ($analysis['coverage_distribution']['common'] ?? 0) + 1;
                } elseif ($coverage >= 20) {
                    $analysis['coverage_distribution']['occasional'] = ($analysis['coverage_distribution']['occasional'] ?? 0) + 1;
                } else {
                    $analysis['coverage_distribution']['rare'] = ($analysis['coverage_distribution']['rare'] ?? 0) + 1;
                }
                
                // Plugin field counts
                if ($field['analysis']['likely_acf']) {
                    $analysis['plugin_field_counts']['acf'] = ($analysis['plugin_field_counts']['acf'] ?? 0) + 1;
                }
                if ($field['analysis']['likely_woocommerce']) {
                    $analysis['plugin_field_counts']['woocommerce'] = ($analysis['plugin_field_counts']['woocommerce'] ?? 0) + 1;
                }
            }
        }
        
        return $analysis;
    }
    
    protected function getRecommendations(): array
    {
        $recommendations = [];
        
        // Migration strategy recommendations
        foreach ($this->schemas as $postType => $schema) {
            $fieldCount = count($schema['fields']);
            $postCount = $schema['post_count'];
            
            if ($fieldCount > 10) {
                $recommendations[] = "Consider breaking down '{$postType}' into separate related models for better organization";
            }
            
            if ($postCount > 1000 && $fieldCount > 5) {
                $recommendations[] = "'{$postType}' has {$postCount} posts with {$fieldCount} custom fields - consider performance optimization";
            }
            
            $commonFieldsCount = count($schema['field_categories']['common']);
            if ($commonFieldsCount < 3 && $fieldCount > 8) {
                $recommendations[] = "'{$postType}' has inconsistent field usage - review data quality";
            }
        }
        
        // Plugin-specific recommendations
        $plugins = $this->statistics['plugin_detection'];
        if ($plugins['acf']) {
            $recommendations[] = "ACF fields detected - consider using ACF Pro for better field management";
        }
        if ($plugins['woocommerce']) {
            $recommendations[] = "WooCommerce data detected - ensure proper product relationships are maintained";
        }
        
        return $recommendations;
    }
    
    protected function getCorePosts(): array
    {
        return [
            'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content',
            'post_title', 'post_excerpt', 'post_status', 'comment_status',
            'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
            'post_modified', 'post_modified_gmt', 'post_content_filtered',
            'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type',
            'comment_count'
        ];
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
}