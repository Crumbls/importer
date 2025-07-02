<?php

namespace Crumbls\Importer\Contracts;

use Crumbls\Importer\Storage\StorageReader;

interface BaseDriverContract extends ImporterDriverContract
{
    /**
     * Set chunk size for processing
     */
    public function chunkSize(int $size): self;
    
    /**
     * Enable rate limiting with max operations per second
     */
    public function throttle(int $maxPerSecond = 0, int $maxChunksPerMinute = 0): self;
    
    /**
     * Set max operations per second
     */
    public function maxRowsPerSecond(int $limit): self;
    
    /**
     * Set max chunks per minute
     */
    public function maxChunksPerMinute(int $limit): self;
    
    /**
     * Get rate limiter statistics
     */
    public function getRateLimiterStats(): ?array;
    
    /**
     * Use memory storage for data
     */
    public function useMemoryStorage(): self;
    
    /**
     * Use SQLite storage with optional configuration
     */
    public function useSqliteStorage(array $config = []): self;
    
    /**
     * Set storage driver and configuration
     */
    public function storage(string $driver, array $config = []): self;
    
    /**
     * Get storage reader for accessing stored data
     */
    public function getStorageReader(): ?StorageReader;
    
    /**
     * Add validation rule for a field
     */
    public function addValidationRule(string $field, string $rule, $parameter): self;
    
    /**
     * Set field as required
     */
    public function required(string $field): self;
    
    /**
     * Set field as numeric
     */
    public function numeric(string $field): self;
    
    /**
     * Set field as email
     */
    public function email(string $field): self;
    
    /**
     * Set minimum length for field
     */
    public function minLength(string $field, int $length): self;
    
    /**
     * Set maximum length for field
     */
    public function maxLength(string $field, int $length): self;
    
    /**
     * Set regex pattern for field
     */
    public function regex(string $field, string $pattern): self;
    
    /**
     * Set allowed values for field
     */
    public function allowedValues(string $field, array $values): self;
    
    /**
     * Skip invalid rows instead of failing
     */
    public function skipInvalidRows(bool $skip = true): self;
    
    /**
     * Set maximum number of errors before failing
     */
    public function maxErrors(int $maxErrors): self;
    
    /**
     * Enable temporary storage for large files
     */
    public function withTempStorage(): self;
    
    /**
     * Get detailed file information
     */
    public function getFileInfo(string $source): array;
    
    /**
     * Get driver configuration
     */
    public function getConfig(): array;
    
    /**
     * Set driver configuration
     */
    public function setConfig(array $config): self;
}