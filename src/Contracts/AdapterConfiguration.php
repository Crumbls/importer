<?php

namespace Crumbls\Importer\Contracts;

interface AdapterConfiguration
{
    /**
     * Get the raw configuration array
     */
    public function toArray(): array;
    
    /**
     * Get a configuration value with dot notation support
     */
    public function get(string $key, mixed $default = null): mixed;
    
    /**
     * Set a configuration value with dot notation support
     */
    public function set(string $key, mixed $value): static;
    
    /**
     * Merge configuration values
     */
    public function merge(array $config): static;
    
    /**
     * Validate the current configuration
     */
    public function validate(): ValidationResult;
    
    /**
     * Get the environment this configuration is optimized for
     */
    public function getEnvironment(): string;
    
    /**
     * Clone the configuration for a different environment
     */
    public function forEnvironment(string $environment): static;
}