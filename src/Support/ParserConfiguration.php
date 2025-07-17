<?php

namespace Crumbls\Importer\Support;

class ParserConfiguration
{
    protected array $config;
    protected array $defaults;
    
    public function __construct(array $config = [])
    {
        $this->defaults = $this->getDefaultConfiguration();
        $this->config = array_merge($this->defaults, $config);
        $this->validateConfiguration();
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            // XMLReader Security Settings
            'xml_security' => [
                'disable_entity_loader' => true,
                'substitute_entities' => false,
                'resolve_externals' => false,
                'validate_dtd' => false,
                'libxml_options' => LIBXML_NOENT | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING,
            ],
            
            // Memory Management
            'memory' => [
                'memory_limit' => 512 * 1024 * 1024, // 512MB
                'batch_size' => 100,
                'min_batch_size' => 10,
                'max_batch_size' => 500,
                'warning_threshold' => 0.7,  // 70%
                'critical_threshold' => 0.85, // 85%
                'emergency_threshold' => 0.95, // 95%
            ],
            
            // Progress Tracking
            'progress' => [
                'min_update_interval' => 2.0, // seconds
                'min_update_percentage' => 1.0, // percent
                'force_update_every' => 100, // items
                'log_milestones' => true,
            ],
            
            // Data Extraction
            'extraction' => [
                'extract_posts' => true,
                'extract_meta' => true,
                'extract_comments' => true,
                'extract_terms' => true,
                'extract_users' => true,
                'skip_empty_content' => false,
                'validate_data' => true,
            ],
            
            // Database Configuration
            'database' => [
                'tables' => [
                    'posts' => 'posts',
                    'meta' => 'meta',
                    'comments' => 'comments',
                    'terms' => 'terms',
                    'term_relationships' => 'term_relationships',
                    'users' => 'users',
                ],
                'use_transactions' => true,
                'duplicate_handling' => 'replace', // 'replace', 'ignore', 'error'
            ],
            
            // Error Handling
            'error_handling' => [
                'stop_on_error' => false,
                'max_errors' => 100,
                'log_errors' => true,
                'skip_invalid_data' => true,
            ],
            
            // Performance Optimization
            'performance' => [
                'enable_compression' => false,
                'use_streaming' => true,
                'prefetch_nodes' => 50,
                'cleanup_frequency' => 1000, // items
            ],
            
            // Debugging
            'debug' => [
                'enabled' => false,
                'log_level' => 'info',
                'sample_data' => false,
                'timing_analysis' => false,
            ],
        ];
    }
    
    protected function validateConfiguration(): void
    {
        // Validate memory settings
        if ($this->config['memory']['batch_size'] < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1');
        }
        
        if ($this->config['memory']['min_batch_size'] > $this->config['memory']['max_batch_size']) {
            throw new \InvalidArgumentException('Min batch size cannot be greater than max batch size');
        }
        
        // Validate memory thresholds
        $memoryThresholds = ['warning_threshold', 'critical_threshold', 'emergency_threshold'];
        foreach ($memoryThresholds as $threshold) {
            $value = $this->config['memory'][$threshold];
            if ($value < 0 || $value > 1) {
                throw new \InvalidArgumentException("Memory {$threshold} must be between 0 and 1");
            }
        }
        
        // Validate progress settings
        if ($this->config['progress']['min_update_interval'] < 0) {
            throw new \InvalidArgumentException('Min update interval cannot be negative');
        }
        
        // Validate database duplicate handling
        $validHandling = ['replace', 'ignore', 'error'];
        if (!in_array($this->config['database']['duplicate_handling'], $validHandling)) {
            throw new \InvalidArgumentException('Invalid duplicate handling method');
        }
    }
    
    // Getter methods for configuration sections
    
    public function getXmlSecurityConfig(): array
    {
        return $this->config['xml_security'];
    }
    
    public function getMemoryConfig(): array
    {
        return $this->config['memory'];
    }
    
    public function getProgressConfig(): array
    {
        return $this->config['progress'];
    }
    
    public function getExtractionConfig(): array
    {
        return $this->config['extraction'];
    }
    
    public function getDatabaseConfig(): array
    {
        return $this->config['database'];
    }
    
    public function getErrorHandlingConfig(): array
    {
        return $this->config['error_handling'];
    }
    
    public function getPerformanceConfig(): array
    {
        return $this->config['performance'];
    }
    
    public function getDebugConfig(): array
    {
        return $this->config['debug'];
    }
    
    // Specific configuration getters
    
    public function getMemoryLimit(): int
    {
        return $this->config['memory']['memory_limit'];
    }
    
    public function getBatchSize(): int
    {
        return $this->config['memory']['batch_size'];
    }
    
    public function getMinBatchSize(): int
    {
        return $this->config['memory']['min_batch_size'];
    }
    
    public function getMaxBatchSize(): int
    {
        return $this->config['memory']['max_batch_size'];
    }
    
    public function getTableName(string $type): string
    {
        return $this->config['database']['tables'][$type] ?? $type;
    }
    
    public function shouldExtract(string $type): bool
    {
        $key = "extract_{$type}";
        return $this->config['extraction'][$key] ?? false;
    }
    
    public function shouldStopOnError(): bool
    {
        return $this->config['error_handling']['stop_on_error'];
    }
    
    public function getMaxErrors(): int
    {
        return $this->config['error_handling']['max_errors'];
    }
    
    public function isDebuggingEnabled(): bool
    {
        return $this->config['debug']['enabled'];
    }
    
    public function getLibXmlOptions(): int
    {
        return $this->config['xml_security']['libxml_options'];
    }
    
    public function shouldDisableEntityLoader(): bool
    {
        return $this->config['xml_security']['disable_entity_loader'];
    }
    
    // Configuration modification methods
    
    public function set(string $key, $value): self
    {
        $keys = explode('.', $key);
        $config = &$this->config;
        
        foreach ($keys as $keyPart) {
            if (!isset($config[$keyPart])) {
                $config[$keyPart] = [];
            }
            $config = &$config[$keyPart];
        }
        
        $config = $value;
        $this->validateConfiguration();
        
        return $this;
    }
    
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $config = $this->config;
        
        foreach ($keys as $keyPart) {
            if (!isset($config[$keyPart])) {
                return $default;
            }
            $config = $config[$keyPart];
        }
        
        return $config;
    }
    
    public function merge(array $config): self
    {
        $this->config = array_merge_recursive($this->config, $config);
        $this->validateConfiguration();
        
        return $this;
    }
    
    public function toArray(): array
    {
        return $this->config;
    }
    
    // Factory methods for common configurations
    
    public static function forLargeFiles(): self
    {
        return new self([
            'memory' => [
                'memory_limit' => 1024 * 1024 * 1024, // 1GB
                'batch_size' => 50,
                'warning_threshold' => 0.6,
                'critical_threshold' => 0.8,
            ],
            'progress' => [
                'min_update_interval' => 5.0,
                'force_update_every' => 50,
            ],
            'performance' => [
                'cleanup_frequency' => 500,
            ],
        ]);
    }
    
    public static function forDevelopment(): self
    {
        return new self([
            'debug' => [
                'enabled' => true,
                'log_level' => 'debug',
                'sample_data' => true,
                'timing_analysis' => true,
            ],
            'memory' => [
                'batch_size' => 25,
            ],
            'error_handling' => [
                'stop_on_error' => true,
                'max_errors' => 10,
            ],
        ]);
    }
    
    public static function forProduction(): self
    {
        return new self([
            'error_handling' => [
                'stop_on_error' => false,
                'max_errors' => 1000,
                'log_errors' => true,
            ],
            'performance' => [
                'enable_compression' => true,
                'cleanup_frequency' => 2000,
            ],
            'debug' => [
                'enabled' => false,
            ],
        ]);
    }
}