<?php

namespace Crumbls\Importer\Adapters\Traits;

use Crumbls\Importer\Contracts\AdapterConfiguration;
use Crumbls\Importer\Contracts\ValidationResult;

trait HasStandardizedConfiguration
{
    protected AdapterConfiguration $configuration;
    
    /**
     * Initialize configuration from various input types
     */
    protected function initializeConfiguration(mixed $config, string $defaultConfigClass, string $environment = 'production'): void
    {
        if ($config instanceof AdapterConfiguration) {
            // Already a configuration object
            $this->configuration = $config;
        } elseif (is_array($config)) {
            // Array configuration - convert to configuration object
            $this->configuration = new $defaultConfigClass($config, $environment);
        } elseif (is_string($config)) {
            // Environment name - create default configuration for that environment
            $this->configuration = new $defaultConfigClass([], $config);
        } else {
            // Default configuration
            $this->configuration = new $defaultConfigClass([], $environment);
        }
    }
    
    /**
     * Get the configuration object
     */
    public function getConfiguration(): AdapterConfiguration
    {
        return $this->configuration;
    }
    
    /**
     * Set the configuration object
     */
    public function setConfiguration(AdapterConfiguration $configuration): static
    {
        $this->configuration = $configuration;
        return $this;
    }
    
    /**
     * Get configuration array (backwards compatibility)
     */
    public function getConfig(): array
    {
        return $this->configuration->toArray();
    }
    
    /**
     * Set configuration from array (backwards compatibility)
     */
    public function setConfig(array $config): static
    {
        $this->configuration->merge($config);
        return $this;
    }
    
    /**
     * Get a configuration value with dot notation support
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->configuration->get($key, $default);
    }
    
    /**
     * Set a configuration value with dot notation support
     */
    public function configure(string $key, mixed $value): static
    {
        $this->configuration->set($key, $value);
        return $this;
    }
    
    /**
     * Merge configuration values
     */
    public function mergeConfig(array $config): static
    {
        $this->configuration->merge($config);
        return $this;
    }
    
    /**
     * Validate the current configuration
     */
    public function validateConfiguration(): ValidationResult
    {
        return $this->configuration->validate();
    }
    
    /**
     * Switch to a different environment configuration
     */
    public function forEnvironment(string $environment): static
    {
        $this->configuration = $this->configuration->forEnvironment($environment);
        return $this;
    }
    
    /**
     * Get the current environment
     */
    public function getEnvironment(): string
    {
        return $this->configuration->getEnvironment();
    }
    
    /**
     * Check if we're in production environment
     */
    public function isProduction(): bool
    {
        return $this->getEnvironment() === 'production';
    }
    
    /**
     * Check if we're in development/testing environment
     */
    public function isDevelopment(): bool
    {
        return in_array($this->getEnvironment(), ['development', 'testing']);
    }
    
    /**
     * Magic method to access configuration fluently
     * e.g., $adapter->chunkSize(1000)->timeout(300)
     */
    public function __call(string $method, array $arguments): mixed
    {
        // Check if the configuration object has this method
        if (method_exists($this->configuration, $method)) {
            $result = $this->configuration->{$method}(...$arguments);
            
            // If the configuration method returns the configuration object,
            // return this adapter for chaining
            if ($result instanceof AdapterConfiguration) {
                return $this;
            }
            
            return $result;
        }
        
        // Check if it's a getter (no arguments)
        if (empty($arguments)) {
            return $this->configuration->get($method);
        }
        
        // Check if it's a setter (one argument)
        if (count($arguments) === 1) {
            $this->configuration->set($method, $arguments[0]);
            return $this;
        }
        
        throw new \BadMethodCallException("Method {$method} does not exist");
    }
}