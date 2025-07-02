<?php

namespace Crumbls\Importer\Configuration;

use Crumbls\Importer\Contracts\MigrationAdapter;

class MigrationConfiguration extends BaseConfiguration
{
    protected function getDefaults(): array
    {
        return match($this->environment) {
            'production' => [
                'connection' => null,
                'strategy' => 'migration',
                'conflict_strategy' => 'skip',
                'create_missing' => true,
                'dry_run' => false,
                'batch_size' => 1000,
                'timeout' => 300,
                'continue_on_error' => false,
                'backup_before_migration' => true,
                'log_operations' => true,
                'mappings' => [],
                'relationships' => [],
                'exclude_meta' => [
                    '_wp_trash_*',
                    '_edit_lock',
                    '_edit_last',
                    '_wp_old_slug'
                ],
                'performance' => [
                    'memory_limit' => '512M',
                    'max_execution_time' => 300,
                    'chunk_size' => 100,
                ],
                'validation' => [
                    'strict_mode' => true,
                    'validate_references' => true,
                    'check_constraints' => true,
                ],
                'retry' => [
                    'enabled' => true,
                    'max_attempts' => 3,
                    'delay' => 1000, // milliseconds
                    'backoff_multiplier' => 2,
                ]
            ],
            'staging' => [
                'connection' => null,
                'strategy' => 'migration',
                'conflict_strategy' => 'overwrite',
                'create_missing' => true,
                'dry_run' => false,
                'batch_size' => 500,
                'timeout' => 180,
                'continue_on_error' => true,
                'backup_before_migration' => true,
                'log_operations' => true,
                'mappings' => [],
                'relationships' => [],
                'exclude_meta' => [],
                'performance' => [
                    'memory_limit' => '256M',
                    'max_execution_time' => 180,
                    'chunk_size' => 50,
                ],
                'validation' => [
                    'strict_mode' => false,
                    'validate_references' => true,
                    'check_constraints' => false,
                ],
                'retry' => [
                    'enabled' => true,
                    'max_attempts' => 2,
                    'delay' => 500,
                    'backoff_multiplier' => 1.5,
                ]
            ],
            'development', 'testing' => [
                'connection' => 'testing',
                'strategy' => 'migration',
                'conflict_strategy' => 'overwrite',
                'create_missing' => true,
                'dry_run' => false,
                'batch_size' => 100,
                'timeout' => 60,
                'continue_on_error' => true,
                'backup_before_migration' => false,
                'log_operations' => false,
                'mappings' => [],
                'relationships' => [],
                'exclude_meta' => [],
                'performance' => [
                    'memory_limit' => '128M',
                    'max_execution_time' => 60,
                    'chunk_size' => 10,
                ],
                'validation' => [
                    'strict_mode' => false,
                    'validate_references' => false,
                    'check_constraints' => false,
                ],
                'retry' => [
                    'enabled' => false,
                    'max_attempts' => 1,
                    'delay' => 0,
                    'backoff_multiplier' => 1,
                ]
            ]
        };
    }
    
    protected function getValidationRules(): array
    {
        return [
            'connection' => [
                'required' => true,
                'type' => 'string|array'
            ],
            'strategy' => [
                'required' => true,
                'type' => 'string',
                'in' => ['migration', 'sync']
            ],
            'conflict_strategy' => [
                'required' => true,
                'type' => 'string',
                'in' => ['skip', 'overwrite', 'merge']
            ],
            'create_missing' => [
                'type' => 'boolean'
            ],
            'dry_run' => [
                'type' => 'boolean'
            ],
            'batch_size' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 10000
            ],
            'timeout' => [
                'type' => 'integer',
                'min' => 1
            ],
            'continue_on_error' => [
                'type' => 'boolean'
            ],
            'backup_before_migration' => [
                'type' => 'boolean'
            ],
            'log_operations' => [
                'type' => 'boolean'
            ],
            'mappings' => [
                'type' => 'array'
            ],
            'relationships' => [
                'type' => 'array'
            ],
            'exclude_meta' => [
                'type' => 'array'
            ],
            'performance.memory_limit' => [
                'type' => 'string'
            ],
            'performance.max_execution_time' => [
                'type' => 'integer',
                'min' => 1
            ],
            'performance.chunk_size' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 1000
            ],
            'validation.strict_mode' => [
                'type' => 'boolean'
            ],
            'validation.validate_references' => [
                'type' => 'boolean'
            ],
            'validation.check_constraints' => [
                'type' => 'boolean'
            ],
            'retry.enabled' => [
                'type' => 'boolean'
            ],
            'retry.max_attempts' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 10
            ],
            'retry.delay' => [
                'type' => 'integer',
                'min' => 0
            ],
            'retry.backoff_multiplier' => [
                'type' => 'float',
                'min' => 1.0
            ]
        ];
    }
    
    // Fluent configuration methods
    public function connection(string|array $connection): static
    {
        return $this->set('connection', $connection);
    }
    
    public function strategy(string $strategy): static
    {
        return $this->set('strategy', $strategy);
    }
    
    public function conflictStrategy(string $strategy): static
    {
        return $this->set('conflict_strategy', $strategy);
    }
    
    public function createMissing(bool $create = true): static
    {
        return $this->set('create_missing', $create);
    }
    
    public function dryRun(bool $dryRun = true): static
    {
        return $this->set('dry_run', $dryRun);
    }
    
    public function batchSize(int $size): static
    {
        return $this->set('batch_size', $size);
    }
    
    public function timeout(int $seconds): static
    {
        return $this->set('timeout', $seconds);
    }
    
    public function continueOnError(bool $continue = true): static
    {
        return $this->set('continue_on_error', $continue);
    }
    
    public function backup(bool $backup = true): static
    {
        return $this->set('backup_before_migration', $backup);
    }
    
    public function logOperations(bool $log = true): static
    {
        return $this->set('log_operations', $log);
    }
    
    public function mappings(array $mappings): static
    {
        return $this->set('mappings', $mappings);
    }
    
    public function relationships(array $relationships): static
    {
        return $this->set('relationships', $relationships);
    }
    
    public function excludeMeta(array $patterns): static
    {
        return $this->set('exclude_meta', $patterns);
    }
    
    public function chunkSize(int $size): static
    {
        return $this->set('performance.chunk_size', $size);
    }
    
    public function memoryLimit(string $limit): static
    {
        return $this->set('performance.memory_limit', $limit);
    }
    
    public function maxExecutionTime(int $seconds): static
    {
        return $this->set('performance.max_execution_time', $seconds);
    }
    
    public function strictMode(bool $strict = true): static
    {
        return $this->set('validation.strict_mode', $strict);
    }
    
    public function validateReferences(bool $validate = true): static
    {
        return $this->set('validation.validate_references', $validate);
    }
    
    public function checkConstraints(bool $check = true): static
    {
        return $this->set('validation.check_constraints', $check);
    }
    
    public function enableRetry(bool $enable = true): static
    {
        return $this->set('retry.enabled', $enable);
    }
    
    public function maxRetryAttempts(int $attempts): static
    {
        return $this->set('retry.max_attempts', $attempts);
    }
    
    public function retryDelay(int $milliseconds): static
    {
        return $this->set('retry.delay', $milliseconds);
    }
    
    public function retryBackoffMultiplier(float $multiplier): static
    {
        return $this->set('retry.backoff_multiplier', $multiplier);
    }
    
    // Static factory methods
    public static function production(array $config = []): static
    {
        return new static($config, 'production');
    }
    
    public static function staging(array $config = []): static
    {
        return new static($config, 'staging');
    }
    
    public static function development(array $config = []): static
    {
        return new static($config, 'development');
    }
    
    public static function testing(array $config = []): static
    {
        return new static($config, 'testing');
    }
}