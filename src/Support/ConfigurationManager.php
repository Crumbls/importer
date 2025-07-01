<?php

namespace Crumbls\Importer\Support;

class ConfigurationManager
{
    protected array $config = [];
    protected array $environmentDefaults = [];
    protected string $environment;
    
    public function __construct(array $config = [], ?string $environment = null)
    {
        $this->environment = $environment ?? $this->detectEnvironment();
        $this->setupEnvironmentDefaults();
        $this->config = $this->mergeWithDefaults($config);
    }
    
    protected function detectEnvironment(): string
    {
        // Try Laravel first
        if (function_exists('app') && app()->bound('env')) {
            return app()->environment();
        }
        
        // Fallback to environment variable
        return $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'development';
    }
    
    public static function production(array $overrides = []): self
    {
        return new self($overrides, 'production');
    }
    
    public static function staging(array $overrides = []): self
    {
        return new self($overrides, 'staging');
    }
    
    public static function development(array $overrides = []): self
    {
        return new self($overrides, 'development');
    }
    
    public function get(string $key, $default = null)
    {
        return $this->arrayGet($this->config, $key, $default);
    }
    
    protected function arrayGet($array, $key, $default = null)
    {
        if (function_exists('data_get')) {
            return data_get($array, $key, $default);
        }
        
        if (is_null($key)) {
            return $array;
        }
        
        if (isset($array[$key])) {
            return $array[$key];
        }
        
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        
        return $array;
    }
    
    public function set(string $key, $value): self
    {
        $this->arraySet($this->config, $key, $value);
        return $this;
    }
    
    protected function arraySet(array &$array, string $key, $value): void
    {
        if (function_exists('data_set')) {
            data_set($array, $key, $value);
            return;
        }
        
        $keys = explode('.', $key);
        while (count($keys) > 1) {
            $key = array_shift($keys);
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }
            $array = &$array[$key];
        }
        $array[array_shift($keys)] = $value;
    }
    
    public function merge(array $config): self
    {
        $this->config = array_merge_recursive($this->config, $config);
        return $this;
    }
    
    public function getAll(): array
    {
        return $this->config;
    }
    
    public function getEnvironment(): string
    {
        return $this->environment;
    }
    
    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }
    
    public function isDevelopment(): bool
    {
        return $this->environment === 'development';
    }
    
    public function getPerformanceConfig(): array
    {
        return [
            'memory_limit' => $this->get('performance.memory_limit'),
            'timeout' => $this->get('performance.timeout'),
            'batch_size' => $this->get('performance.batch_size'),
            'chunk_size' => $this->get('performance.chunk_size'),
            'max_records_per_second' => $this->get('performance.max_records_per_second'),
            'gc_probability' => $this->get('performance.gc_probability'),
            'optimize_memory_interval' => $this->get('performance.optimize_memory_interval')
        ];
    }
    
    public function getValidationConfig(): array
    {
        return [
            'strict_mode' => $this->get('validation.strict_mode'),
            'max_content_length' => $this->get('validation.max_content_length'),
            'allow_html' => $this->get('validation.allow_html'),
            'check_encoding' => $this->get('validation.check_encoding'),
            'validate_relationships' => $this->get('validation.validate_relationships'),
            'max_validation_errors' => $this->get('validation.max_errors')
        ];
    }
    
    public function getBackupConfig(): array
    {
        return [
            'enabled' => $this->get('backup.enabled'),
            'strategy' => $this->get('backup.strategy'),
            'storage_disk' => $this->get('backup.storage_disk'),
            'retention_days' => $this->get('backup.retention_days'),
            'compress' => $this->get('backup.compress'),
            'verify_backup' => $this->get('backup.verify_backup')
        ];
    }
    
    public function getRetryConfig(): array
    {
        return [
            'max_attempts' => $this->get('retry.max_attempts'),
            'backoff_strategy' => $this->get('retry.backoff_strategy'),
            'base_delay' => $this->get('retry.base_delay'),
            'max_delay' => $this->get('retry.max_delay'),
            'jitter' => $this->get('retry.jitter'),
            'retry_on' => $this->get('retry.retry_on')
        ];
    }
    
    public function getLoggingConfig(): array
    {
        return [
            'enabled' => $this->get('logging.enabled'),
            'level' => $this->get('logging.level'),
            'channels' => $this->get('logging.channels'),
            'include_performance_metrics' => $this->get('logging.include_performance_metrics'),
            'log_memory_usage' => $this->get('logging.log_memory_usage'),
            'log_sql_queries' => $this->get('logging.log_sql_queries')
        ];
    }
    
    public function getNotificationConfig(): array
    {
        return [
            'enabled' => $this->get('notifications.enabled'),
            'channels' => $this->get('notifications.channels'),
            'alert_on' => $this->get('notifications.alert_on'),
            'slack_webhook' => $this->get('notifications.slack_webhook'),
            'email_recipients' => $this->get('notifications.email_recipients')
        ];
    }
    
    protected function setupEnvironmentDefaults(): void
    {
        $this->environmentDefaults = [
            'production' => [
                'performance' => [
                    'memory_limit' => '512M',
                    'timeout' => 300,
                    'batch_size' => 100,
                    'chunk_size' => 50,
                    'max_records_per_second' => 1000,
                    'gc_probability' => 10,
                    'optimize_memory_interval' => 100
                ],
                'validation' => [
                    'strict_mode' => true,
                    'max_content_length' => 65535,
                    'allow_html' => true,
                    'check_encoding' => true,
                    'validate_relationships' => true,
                    'max_errors' => 100
                ],
                'backup' => [
                    'enabled' => true,
                    'strategy' => 'incremental',
                    'storage_disk' => 'backups',
                    'retention_days' => 30,
                    'compress' => true,
                    'verify_backup' => true
                ],
                'retry' => [
                    'max_attempts' => 3,
                    'backoff_strategy' => 'exponential',
                    'base_delay' => 2,
                    'max_delay' => 60,
                    'jitter' => true,
                    'retry_on' => ['connection_timeout', 'lock_timeout', 'deadlock']
                ],
                'logging' => [
                    'enabled' => true,
                    'level' => 'info',
                    'channels' => ['file', 'database'],
                    'include_performance_metrics' => true,
                    'log_memory_usage' => true,
                    'log_sql_queries' => false
                ],
                'notifications' => [
                    'enabled' => true,
                    'channels' => ['email', 'slack'],
                    'alert_on' => ['failure', 'completion', 'performance_alert'],
                    'slack_webhook' => $this->env('MIGRATION_SLACK_WEBHOOK'),
                    'email_recipients' => explode(',', $this->env('MIGRATION_EMAIL_ALERTS', ''))
                ]
            ],
            
            'staging' => [
                'performance' => [
                    'memory_limit' => '256M',
                    'timeout' => 180,
                    'batch_size' => 200,
                    'chunk_size' => 100,
                    'max_records_per_second' => 2000,
                    'gc_probability' => 5,
                    'optimize_memory_interval' => 50
                ],
                'validation' => [
                    'strict_mode' => false,
                    'max_content_length' => 65535,
                    'allow_html' => true,
                    'check_encoding' => true,
                    'validate_relationships' => true,
                    'max_errors' => 500
                ],
                'backup' => [
                    'enabled' => true,
                    'strategy' => 'incremental',
                    'storage_disk' => 'local',
                    'retention_days' => 7,
                    'compress' => true,
                    'verify_backup' => false
                ],
                'retry' => [
                    'max_attempts' => 2,
                    'backoff_strategy' => 'linear',
                    'base_delay' => 1,
                    'max_delay' => 30,
                    'jitter' => false,
                    'retry_on' => ['connection_timeout']
                ],
                'logging' => [
                    'enabled' => true,
                    'level' => 'debug',
                    'channels' => ['file'],
                    'include_performance_metrics' => true,
                    'log_memory_usage' => true,
                    'log_sql_queries' => true
                ],
                'notifications' => [
                    'enabled' => false,
                    'channels' => [],
                    'alert_on' => ['failure'],
                    'slack_webhook' => null,
                    'email_recipients' => []
                ]
            ],
            
            'development' => [
                'performance' => [
                    'memory_limit' => '128M',
                    'timeout' => 60,
                    'batch_size' => 50,
                    'chunk_size' => 25,
                    'max_records_per_second' => 0, // No limit
                    'gc_probability' => 1,
                    'optimize_memory_interval' => 10
                ],
                'validation' => [
                    'strict_mode' => false,
                    'max_content_length' => 10000,
                    'allow_html' => true,
                    'check_encoding' => false,
                    'validate_relationships' => false,
                    'max_errors' => 10
                ],
                'backup' => [
                    'enabled' => false,
                    'strategy' => 'none',
                    'storage_disk' => 'local',
                    'retention_days' => 1,
                    'compress' => false,
                    'verify_backup' => false
                ],
                'retry' => [
                    'max_attempts' => 1,
                    'backoff_strategy' => 'fixed',
                    'base_delay' => 1,
                    'max_delay' => 5,
                    'jitter' => false,
                    'retry_on' => []
                ],
                'logging' => [
                    'enabled' => true,
                    'level' => 'debug',
                    'channels' => ['console'],
                    'include_performance_metrics' => false,
                    'log_memory_usage' => false,
                    'log_sql_queries' => true
                ],
                'notifications' => [
                    'enabled' => false,
                    'channels' => [],
                    'alert_on' => [],
                    'slack_webhook' => null,
                    'email_recipients' => []
                ]
            ]
        ];
    }
    
    protected function mergeWithDefaults(array $config): array
    {
        $defaults = $this->environmentDefaults[$this->environment] ?? $this->environmentDefaults['development'];
        return $this->arrayMergeRecursiveDistinct($defaults, $config);
    }
    
    protected function arrayMergeRecursiveDistinct(array $array1, array $array2): array
    {
        $merged = $array1;
        
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayMergeRecursiveDistinct($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }
        
        return $merged;
    }
    
    public function validateConfig(): array
    {
        $errors = [];
        $warnings = [];
        
        // Validate memory limit
        $memoryLimit = $this->get('performance.memory_limit');
        if (!preg_match('/^\d+[KMG]?$/', $memoryLimit)) {
            $errors[] = 'Invalid memory limit format: ' . $memoryLimit;
        }
        
        // Validate batch sizes
        $batchSize = $this->get('performance.batch_size');
        if (!is_int($batchSize) || $batchSize < 1) {
            $errors[] = 'Batch size must be a positive integer';
        }
        
        // Validate timeout
        $timeout = $this->get('performance.timeout');
        if (!is_int($timeout) || $timeout < 1) {
            $errors[] = 'Timeout must be a positive integer';
        }
        
        // Check production-specific requirements
        if ($this->isProduction()) {
            if (!$this->get('backup.enabled')) {
                $warnings[] = 'Backups are disabled in production environment';
            }
            
            if (!$this->get('logging.enabled')) {
                $warnings[] = 'Logging is disabled in production environment';
            }
            
            if ($this->get('validation.strict_mode') === false) {
                $warnings[] = 'Strict validation is disabled in production environment';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    public function optimizeForDataSize(int $estimatedRecords): self
    {
        if ($estimatedRecords > 100000) {
            // Large dataset optimizations
            $this->set('performance.batch_size', 50);
            $this->set('performance.chunk_size', 25);
            $this->set('performance.optimize_memory_interval', 25);
            $this->set('performance.gc_probability', 20);
        } elseif ($estimatedRecords > 10000) {
            // Medium dataset optimizations
            $this->set('performance.batch_size', 100);
            $this->set('performance.chunk_size', 50);
            $this->set('performance.optimize_memory_interval', 50);
        } else {
            // Small dataset - can be more aggressive
            $this->set('performance.batch_size', 500);
            $this->set('performance.chunk_size', 250);
            $this->set('performance.optimize_memory_interval', 100);
        }
        
        return $this;
    }
    
    public function toArray(): array
    {
        return $this->config;
    }
    
    protected function env(string $key, $default = null)
    {
        if (function_exists('env')) {
            return env($key, $default);
        }
        
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}