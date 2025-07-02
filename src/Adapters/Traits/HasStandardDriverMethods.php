<?php

namespace Crumbls\Importer\Adapters\Traits;

use Crumbls\Importer\RateLimit\RateLimiter;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Support\ConfigurationPresets;

trait HasStandardDriverMethods
{
    protected int $chunkSize = 1000;
    protected ?RateLimiter $rateLimiter = null;
    protected int $maxRowsPerSecond = 0;
    protected int $maxChunksPerMinute = 0;
    protected string $storageDriver = 'memory';
    protected array $storageConfig = [];
    protected array $validationRules = [];
    protected bool $skipInvalidRows = false;
    protected int $maxErrors = 1000;
    
    public function chunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }
    
    public function throttle(int $maxRowsPerSecond = 0, int $maxChunksPerMinute = 0): self
    {
        $this->maxRowsPerSecond = $maxRowsPerSecond;
        $this->maxChunksPerMinute = $maxChunksPerMinute;
        
        if ($maxRowsPerSecond > 0 || $maxChunksPerMinute > 0) {
            $this->rateLimiter = new RateLimiter(
                max($maxRowsPerSecond ?: 1000000, $maxChunksPerMinute ?: 1000000),
                $maxChunksPerMinute > 0 ? 60 : 1
            );
        }
        
        return $this;
    }
    
    public function maxRowsPerSecond(int $limit): self
    {
        return $this->throttle($limit, $this->maxChunksPerMinute);
    }
    
    public function maxChunksPerMinute(int $limit): self
    {
        return $this->throttle($this->maxRowsPerSecond, $limit);
    }
    
    public function getRateLimiterStats(): ?array
    {
        return $this->rateLimiter?->getStats();
    }
    
    public function useMemoryStorage(): self
    {
        $this->storageDriver = 'memory';
        return $this;
    }
    
    public function useSqliteStorage(array $config = []): self
    {
        $this->storageDriver = 'sqlite';
        $this->storageConfig = $config;
        return $this;
    }
    
    public function storage(string $driver, array $config = []): self
    {
        $this->storageDriver = $driver;
        $this->storageConfig = $config;
        return $this;
    }
    
    public function getStorageReader(): ?StorageReader
    {
        if (!isset($this->pipeline)) {
            return null;
        }
        
        $storage = $this->pipeline->getContext()->get('temporary_storage');
        return $storage ? new StorageReader($storage) : null;
    }
    
    public function addValidationRule(string $field, string $rule, $parameter): self
    {
        if (!isset($this->validationRules[$field])) {
            $this->validationRules[$field] = [];
        }
        $this->validationRules[$field][$rule] = $parameter;
        return $this;
    }
    
    public function required(string $field): self
    {
        return $this->addValidationRule($field, 'required', true);
    }
    
    public function numeric(string $field): self
    {
        return $this->addValidationRule($field, 'numeric', true);
    }
    
    public function email(string $field): self
    {
        return $this->addValidationRule($field, 'email', true);
    }
    
    public function minLength(string $field, int $length): self
    {
        return $this->addValidationRule($field, 'min_length', $length);
    }
    
    public function maxLength(string $field, int $length): self
    {
        return $this->addValidationRule($field, 'max_length', $length);
    }
    
    public function regex(string $field, string $pattern): self
    {
        return $this->addValidationRule($field, 'regex', $pattern);
    }
    
    public function allowedValues(string $field, array $values): self
    {
        return $this->addValidationRule($field, 'in', $values);
    }
    
    public function skipInvalidRows(bool $skip = true): self
    {
        $this->skipInvalidRows = $skip;
        return $this;
    }
    
    public function maxErrors(int $maxErrors): self
    {
        $this->maxErrors = $maxErrors;
        return $this;
    }
    
    public function withTempStorage(): self
    {
        if (isset($this->pipeline)) {
            $this->pipeline->withTempStorage();
        }
        return $this;
    }
    
    public function getFileInfo(string $source): array
    {
        if (method_exists($this, 'getFileInfo') && trait_exists('HasFileValidation')) {
            return $this->getFileInfo($source);
        }
        
        // Fallback implementation
        return [
            'exists' => file_exists($source),
            'readable' => is_readable($source),
            'size' => file_exists($source) ? filesize($source) : 0,
            'extension' => pathinfo($source, PATHINFO_EXTENSION),
            'last_modified' => file_exists($source) ? filemtime($source) : null
        ];
    }
    
    public function getConfig(): array
    {
        return [
            'chunk_size' => $this->chunkSize,
            'max_rows_per_second' => $this->maxRowsPerSecond,
            'max_chunks_per_minute' => $this->maxChunksPerMinute,
            'storage_driver' => $this->storageDriver,
            'storage_config' => $this->storageConfig,
            'validation_rules' => $this->validationRules,
            'skip_invalid_rows' => $this->skipInvalidRows,
            'max_errors' => $this->maxErrors
        ];
    }
    
    public function setConfig(array $config): self
    {
        if (isset($config['chunk_size'])) {
            $this->chunkSize($config['chunk_size']);
        }
        
        if (isset($config['max_rows_per_second']) || isset($config['max_chunks_per_minute'])) {
            $this->throttle(
                $config['max_rows_per_second'] ?? 0,
                $config['max_chunks_per_minute'] ?? 0
            );
        }
        
        if (isset($config['storage_driver'])) {
            $this->storage($config['storage_driver'], $config['storage_config'] ?? []);
        }
        
        if (isset($config['validation_rules'])) {
            $this->validationRules = $config['validation_rules'];
        }
        
        if (isset($config['skip_invalid_rows'])) {
            $this->skipInvalidRows($config['skip_invalid_rows']);
        }
        
        if (isset($config['max_errors'])) {
            $this->maxErrors($config['max_errors']);
        }
        
        return $this;
    }
    
    /**
     * Apply a configuration preset
     */
    public function preset(string $name): self
    {
        $presetConfig = ConfigurationPresets::getPreset($name);
        return $this->setConfig($presetConfig);
    }
    
    /**
     * Apply a configuration preset with custom overrides
     */
    public function presetWith(string $name, array $overrides): self
    {
        $config = ConfigurationPresets::mergeWithPreset($name, $overrides);
        return $this->setConfig($config);
    }
    
    /**
     * Get recommended preset based on file characteristics
     */
    public function recommendedPreset(string $filePath): self
    {
        if (method_exists($this, 'getFileInfo')) {
            $fileInfo = $this->getFileInfo($filePath);
            $recommendedPreset = ConfigurationPresets::recommend($fileInfo);
            return $this->preset($recommendedPreset);
        }
        
        // Fallback to production preset
        return $this->preset('production');
    }
    
    /**
     * Get standardized error response format
     */
    protected function getStandardErrorResponse(\Throwable $e, string $operation): array
    {
        $errorType = match (true) {
            $e instanceof \InvalidArgumentException => 'validation',
            $e instanceof \RuntimeException => 'runtime',
            $e instanceof \LogicException => 'logic',
            $e instanceof \UnexpectedValueException => 'unexpected_value',
            str_contains($e->getMessage(), 'file') || str_contains($e->getMessage(), 'File') => 'file_access',
            str_contains($e->getMessage(), 'permission') || str_contains($e->getMessage(), 'Permission') => 'permission',
            str_contains($e->getMessage(), 'memory') || str_contains($e->getMessage(), 'Memory') => 'memory',
            str_contains($e->getMessage(), 'network') || str_contains($e->getMessage(), 'Network') => 'network',
            default => 'unexpected'
        };
        
        return [
            'error' => "Failed to {$operation}: " . $e->getMessage(),
            'error_type' => $errorType,
            'error_code' => $e->getCode(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ];
    }
}