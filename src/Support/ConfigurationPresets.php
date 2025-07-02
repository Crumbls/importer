<?php

namespace Crumbls\Importer\Support;

class ConfigurationPresets
{
    /**
     * Get preset configuration for small CSV files (< 10MB)
     */
    public static function smallCsv(): array
    {
        return [
            'chunk_size' => 500,
            'storage_driver' => 'memory',
            'max_errors' => 100,
            'skip_invalid_rows' => true,
            'auto_detect_delimiter' => true,
            'clean_column_names' => true,
            'throttle' => false,
            'validation_rules' => [],
            'description' => 'Optimized for small CSV files with in-memory processing'
        ];
    }
    
    /**
     * Get preset configuration for large CSV files (> 100MB)
     */
    public static function largeCsv(): array
    {
        return [
            'chunk_size' => 2000,
            'storage_driver' => 'sqlite',
            'max_errors' => 1000,
            'skip_invalid_rows' => true,
            'auto_detect_delimiter' => true,
            'clean_column_names' => true,
            'throttle' => ['max_rows_per_second' => 1000],
            'memory_limit' => '512M',
            'use_temp_storage' => true,
            'description' => 'Optimized for large CSV files with SQLite storage and throttling'
        ];
    }
    
    /**
     * Get preset configuration for production environments
     */
    public static function production(): array
    {
        return [
            'chunk_size' => 1000,
            'storage_driver' => 'sqlite',
            'max_errors' => 500,
            'skip_invalid_rows' => false, // Strict validation in production
            'auto_detect_delimiter' => true,
            'clean_column_names' => true,
            'throttle' => ['max_rows_per_second' => 500],
            'memory_limit' => '256M',
            'validation_strict' => true,
            'logging_enabled' => true,
            'checkpoint_interval' => 1000,
            'description' => 'Production-ready configuration with strict validation and monitoring'
        ];
    }
    
    /**
     * Get preset configuration for development environments
     */
    public static function development(): array
    {
        return [
            'chunk_size' => 100,
            'storage_driver' => 'memory',
            'max_errors' => 10,
            'skip_invalid_rows' => true,
            'auto_detect_delimiter' => true,
            'clean_column_names' => true,
            'throttle' => false,
            'debug_mode' => true,
            'verbose_logging' => true,
            'description' => 'Development configuration with verbose logging and small chunks'
        ];
    }
    
    /**
     * Get preset configuration for WordPress XML imports
     */
    public static function wordPressXml(): array
    {
        return [
            'chunk_size' => 50, // WordPress posts can be large
            'storage_driver' => 'multi_table_sqlite',
            'max_errors' => 100,
            'skip_invalid_rows' => true,
            'enabled_entities' => ['posts', 'users', 'comments', 'media'],
            'conflict_resolution' => 'update',
            'preserve_ids' => false,
            'process_media' => true,
            'description' => 'Optimized for WordPress XML exports with multi-table storage'
        ];
    }
    
    /**
     * Get preset configuration for e-commerce data imports
     */
    public static function ecommerce(): array
    {
        return [
            'chunk_size' => 1000,
            'storage_driver' => 'sqlite',
            'max_errors' => 200,
            'skip_invalid_rows' => false, // Important for product data integrity
            'auto_detect_delimiter' => true,
            'clean_column_names' => true,
            'required_fields' => ['sku', 'name', 'price'],
            'validation_rules' => [
                'price' => ['numeric', 'min' => 0],
                'sku' => ['required', 'max_length' => 50],
                'email' => ['email']
            ],
            'throttle' => ['max_rows_per_second' => 200],
            'description' => 'E-commerce preset with strict validation for product data'
        ];
    }
    
    /**
     * Get preset configuration for user data imports (GDPR compliant)
     */
    public static function userData(): array
    {
        return [
            'chunk_size' => 500,
            'storage_driver' => 'sqlite',
            'max_errors' => 50,
            'skip_invalid_rows' => false, // Strict for user data
            'clean_column_names' => true,
            'required_fields' => ['email'],
            'validation_rules' => [
                'email' => ['required', 'email'],
                'phone' => ['phone'],
                'age' => ['numeric', 'min' => 13, 'max' => 120]
            ],
            'data_sanitization' => true,
            'privacy_mode' => true,
            'throttle' => ['max_rows_per_second' => 100],
            'description' => 'GDPR-compliant preset for user data with privacy controls'
        ];
    }
    
    /**
     * Get preset configuration for high-performance bulk imports
     */
    public static function bulk(): array
    {
        return [
            'chunk_size' => 5000,
            'storage_driver' => 'sqlite',
            'max_errors' => 2000,
            'skip_invalid_rows' => true,
            'auto_detect_delimiter' => true,
            'clean_column_names' => true,
            'validation_rules' => [], // Minimal validation for speed
            'throttle' => false, // No throttling for maximum speed
            'memory_limit' => '1G',
            'use_temp_storage' => true,
            'parallel_processing' => true,
            'description' => 'High-performance configuration for bulk data imports'
        ];
    }
    
    /**
     * Get preset configuration for data migration scenarios
     */
    public static function migration(): array
    {
        return [
            'chunk_size' => 1000,
            'storage_driver' => 'sqlite',
            'max_errors' => 1000,
            'skip_invalid_rows' => false,
            'clean_column_names' => true,
            'conflict_resolution' => 'update',
            'backup_before_migration' => true,
            'rollback_on_failure' => true,
            'dry_run_first' => true,
            'checkpoint_interval' => 500,
            'validation_strict' => true,
            'throttle' => ['max_rows_per_second' => 300],
            'description' => 'Migration preset with backup, rollback, and strict validation'
        ];
    }
    
    /**
     * Get all available presets
     */
    public static function getAllPresets(): array
    {
        return [
            'small_csv' => self::smallCsv(),
            'large_csv' => self::largeCsv(),
            'production' => self::production(),
            'development' => self::development(),
            'wordpress_xml' => self::wordPressXml(),
            'ecommerce' => self::ecommerce(),
            'user_data' => self::userData(),
            'bulk' => self::bulk(),
            'migration' => self::migration()
        ];
    }
    
    /**
     * Get preset by name
     */
    public static function getPreset(string $name): array
    {
        $presets = self::getAllPresets();
        
        if (!isset($presets[$name])) {
            throw new \InvalidArgumentException("Unknown preset: {$name}. Available presets: " . implode(', ', array_keys($presets)));
        }
        
        return $presets[$name];
    }
    
    /**
     * Merge preset with custom configuration
     */
    public static function mergeWithPreset(string $presetName, array $customConfig): array
    {
        $preset = self::getPreset($presetName);
        return array_merge($preset, $customConfig);
    }
    
    /**
     * Get preset recommendations based on file characteristics
     */
    public static function recommend(array $fileInfo): string
    {
        $size = $fileInfo['size'] ?? 0;
        $extension = strtolower($fileInfo['extension'] ?? '');
        
        // Size-based recommendations
        if ($size > 100 * 1024 * 1024) { // > 100MB
            return 'large_csv';
        }
        
        if ($size < 10 * 1024 * 1024) { // < 10MB
            return 'small_csv';
        }
        
        // Extension-based recommendations
        if ($extension === 'xml' && str_contains($fileInfo['name'] ?? '', 'wordpress')) {
            return 'wordpress_xml';
        }
        
        // Default recommendation
        return 'production';
    }
}