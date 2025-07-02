<?php

namespace Crumbls\Importer\Configuration;

use Crumbls\Importer\Contracts\AdapterConfiguration;
use Crumbls\Importer\Contracts\ValidationResult;
use Illuminate\Support\Arr;

abstract class BaseConfiguration implements AdapterConfiguration
{
    protected array $config = [];
    protected string $environment = 'production';
    
    public function __construct(array $config = [], string $environment = 'production')
    {
        $this->environment = $environment;
        $this->config = array_merge($this->getDefaults(), $config);
    }
    
    /**
     * Get default configuration values for the current environment
     */
    abstract protected function getDefaults(): array;
    
    /**
     * Define validation rules for this configuration
     */
    abstract protected function getValidationRules(): array;
    
    public function toArray(): array
    {
        return $this->config;
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }
    
    public function set(string $key, mixed $value): static
    {
        Arr::set($this->config, $key, $value);
        return $this;
    }
    
    public function merge(array $config): static
    {
        $this->config = array_merge_recursive($this->config, $config);
        return $this;
    }
    
    public function validate(): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $suggestions = [];
        
        $rules = $this->getValidationRules();
        
        foreach ($rules as $key => $rule) {
            $value = $this->get($key);
            
            if ($rule['required'] ?? false) {
                if ($value === null || $value === '') {
                    $errors[] = "Configuration key '{$key}' is required";
                    continue;
                }
            }
            
            if ($value !== null && isset($rule['type'])) {
                if (!$this->validateType($value, $rule['type'])) {
                    $errors[] = "Configuration key '{$key}' must be of type {$rule['type']}";
                }
            }
            
            if ($value !== null && isset($rule['in'])) {
                if (!in_array($value, $rule['in'])) {
                    $allowedValues = implode(', ', $rule['in']);
                    $errors[] = "Configuration key '{$key}' must be one of: {$allowedValues}";
                }
            }
            
            if ($value !== null && isset($rule['min'])) {
                if ((is_numeric($value) && $value < $rule['min']) ||
                    (is_string($value) && strlen($value) < $rule['min']) ||
                    (is_array($value) && count($value) < $rule['min'])) {
                    $errors[] = "Configuration key '{$key}' must be at least {$rule['min']}";
                }
            }
            
            if ($value !== null && isset($rule['max'])) {
                if ((is_numeric($value) && $value > $rule['max']) ||
                    (is_string($value) && strlen($value) > $rule['max']) ||
                    (is_array($value) && count($value) > $rule['max'])) {
                    $errors[] = "Configuration key '{$key}' must be at most {$rule['max']}";
                }
            }
        }
        
        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors,
            warnings: $warnings,
            suggestions: $suggestions
        );
    }
    
    public function getEnvironment(): string
    {
        return $this->environment;
    }
    
    public function forEnvironment(string $environment): static
    {
        $clone = clone $this;
        $clone->environment = $environment;
        $clone->config = array_merge($clone->getDefaults(), $this->config);
        return $clone;
    }
    
    protected function validateType(mixed $value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'resource' => is_resource($value),
            'null' => is_null($value),
            default => true
        };
    }
    
    /**
     * Magic method to access configuration as properties
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }
    
    /**
     * Magic method to set configuration as properties
     */
    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }
    
    /**
     * Magic method to check if configuration key exists
     */
    public function __isset(string $key): bool
    {
        return $this->get($key) !== null;
    }
}