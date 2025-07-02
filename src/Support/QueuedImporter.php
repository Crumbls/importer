<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Jobs\ImportJob;
use Illuminate\Foundation\Bus\PendingDispatch;

class QueuedImporter
{
    protected string $driver = 'csv';
    protected array $options = [];
    protected array $driverConfig = [];
    protected ?string $userId = null;
    protected array $metadata = [];
    protected ?string $queue = null;
    protected ?int $delay = null;
    
    /**
     * Set the driver to use for import
     */
    public function driver(string $driver): self
    {
        $this->driver = $driver;
        return $this;
    }
    
    /**
     * Set import options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
    
    /**
     * Set driver configuration
     */
    public function config(array $config): self
    {
        $this->driverConfig = array_merge($this->driverConfig, $config);
        return $this;
    }
    
    /**
     * Apply a configuration preset
     */
    public function preset(string $name): self
    {
        $presetConfig = ConfigurationPresets::getPreset($name);
        return $this->config($presetConfig);
    }
    
    /**
     * Apply a preset with custom overrides
     */
    public function presetWith(string $name, array $overrides): self
    {
        $config = ConfigurationPresets::mergeWithPreset($name, $overrides);
        return $this->config($config);
    }
    
    /**
     * Set the user ID for tracking
     */
    public function forUser(?string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }
    
    /**
     * Add metadata to the import
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }
    
    /**
     * Set the queue to dispatch to
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }
    
    /**
     * Delay the job execution
     */
    public function delay(int $seconds): self
    {
        $this->delay = $seconds;
        return $this;
    }
    
    /**
     * Dispatch the import job
     */
    public function dispatch(string $source): PendingDispatch
    {
        $job = new ImportJob(
            $source,
            $this->driver,
            $this->options,
            $this->driverConfig,
            $this->userId,
            $this->metadata
        );
        
        if ($this->queue) {
            $job->onQueue($this->queue);
        }
        
        $dispatch = dispatch($job);
        
        if ($this->delay) {
            $dispatch->delay($this->delay);
        }
        
        return $dispatch;
    }
    
    /**
     * Dispatch multiple imports in batch
     */
    public function dispatchBatch(array $sources): array
    {
        $jobs = [];
        
        foreach ($sources as $source) {
            $jobs[] = $this->dispatch($source);
        }
        
        return $jobs;
    }
    
    /**
     * Get import result by import ID
     */
    public static function getResult(string $importId): ?array
    {
        return cache()->get("import_result:{$importId}");
    }
    
    /**
     * Check if import is completed
     */
    public static function isCompleted(string $importId): bool
    {
        $result = self::getResult($importId);
        return $result && in_array($result['status'], ['completed', 'failed']);
    }
    
    /**
     * Check if import failed
     */
    public static function hasFailed(string $importId): bool
    {
        $result = self::getResult($importId);
        return $result && $result['status'] === 'failed';
    }
    
    /**
     * Get import status
     */
    public static function getStatus(string $importId): ?string
    {
        $result = self::getResult($importId);
        return $result['status'] ?? null;
    }
    
    /**
     * Clear import result from cache
     */
    public static function clearResult(string $importId): void
    {
        cache()->forget("import_result:{$importId}");
    }
    
    /**
     * Get all imports for a user
     */
    public static function getUserImports(string $userId): array
    {
        // This would require a more sophisticated storage solution
        // For now, we'll return an empty array
        // In a real implementation, you might store import IDs in a user-specific cache key
        return [];
    }
    
    /**
     * Schedule an import for later execution
     */
    public function schedule(string $source, \DateTimeInterface $when): PendingDispatch
    {
        $delayInSeconds = $when->getTimestamp() - time();
        return $this->delay(max(0, $delayInSeconds))->dispatch($source);
    }
    
    /**
     * Create a recurring import job (requires a scheduler)
     */
    public function recurring(string $source, string $cron): array
    {
        // This would integrate with Laravel's task scheduler
        // For now, return configuration for manual setup
        return [
            'command' => "php artisan import:file {$source}",
            'cron' => $cron,
            'driver' => $this->driver,
            'config' => $this->driverConfig,
            'options' => $this->options
        ];
    }
}