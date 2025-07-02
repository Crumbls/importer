<?php

namespace Crumbls\Importer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Crumbls\Importer\ImporterManager;
use Crumbls\Importer\Support\StructuredLogger;
use Crumbls\Importer\Events\ImportStarted;
use Crumbls\Importer\Events\ImportCompleted;
use Crumbls\Importer\Events\ImportFailed;

class ImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public string $importId;
    public string $source;
    public string $driver;
    public array $options;
    public array $driverConfig;
    public ?string $userId;
    public array $metadata;
    
    public int $timeout = 3600; // 1 hour default timeout
    public int $tries = 3;
    public int $maxExceptions = 1;
    
    protected StructuredLogger $logger;
    protected float $startTime;
    
    public function __construct(
        string $source,
        string $driver = 'csv',
        array $options = [],
        array $driverConfig = [],
        ?string $userId = null,
        array $metadata = []
    ) {
        $this->importId = $this->generateImportId();
        $this->source = $source;
        $this->driver = $driver;
        $this->options = $options;
        $this->driverConfig = $driverConfig;
        $this->userId = $userId;
        $this->metadata = $metadata;
        
        // Set queue configuration based on file size
        $this->configureQueue();
    }
    
    public function handle(ImporterManager $importer): void
    {
        $this->startTime = microtime(true);
        $this->logger = new StructuredLogger(Log::channel('imports'));
        
        try {
            // Log import start
            $this->logger->importStarted($this->importId, $this->source, $this->driver, [
                'user_id' => $this->userId,
                'queue' => $this->queue,
                'metadata' => $this->metadata
            ]);
            
            // Fire Laravel event
            event(new ImportStarted(
                $this->importId,
                $this->source,
                $this->driver,
                $this->options,
                $this->getFileInfo(),
                ['user_id' => $this->userId, 'job_id' => $this->job->getJobId()]
            ));
            
            // Configure driver
            $driver = $importer->driver($this->driver);
            
            // Apply driver configuration
            if (!empty($this->driverConfig)) {
                $driver->setConfig($this->driverConfig);
            }
            
            // Execute import
            $result = $driver->import($this->source, $this->options);
            
            $duration = microtime(true) - $this->startTime;
            $stats = [
                'processed' => $result->getProcessed(),
                'imported' => $result->getImported(),
                'failed' => $result->getFailed()
            ];
            
            // Log completion
            $this->logger->importCompleted($this->importId, $stats, $duration, [
                'user_id' => $this->userId,
                'job_id' => $this->job->getJobId()
            ]);
            
            // Fire Laravel event
            event(new ImportCompleted(
                $this->importId,
                $this->source,
                $result,
                $this->startTime,
                $stats,
                ['user_id' => $this->userId, 'job_id' => $this->job->getJobId()]
            ));
            
            // Store result for retrieval
            $this->storeResult($result, $stats, $duration);
            
        } catch (\Throwable $e) {
            $this->handleJobFailure($e);
            throw $e; // Re-throw to trigger Laravel's failure handling
        }
    }
    
    public function failed(\Throwable $exception): void
    {
        $this->logger->importFailed($this->importId, $exception, 'job_execution', [
            'user_id' => $this->userId,
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempt' => $this->attempts()
        ]);
        
        // Fire Laravel event
        event(new ImportFailed(
            $this->importId,
            $this->source,
            $exception,
            'job_execution',
            $this->getRecoveryOptions($exception),
            ['user_id' => $this->userId, 'attempts' => $this->attempts()]
        ));
        
        // Store failure information
        $this->storeFailure($exception);
    }
    
    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'import',
            "driver:{$this->driver}",
            "user:{$this->userId}",
            "size:{$this->getFileSizeCategory()}"
        ];
    }
    
    protected function generateImportId(): string
    {
        return 'imp_' . uniqid() . '_' . time();
    }
    
    protected function configureQueue(): void
    {
        $fileSize = file_exists($this->source) ? filesize($this->source) : 0;
        
        // Configure queue and timeout based on file size
        if ($fileSize > 100 * 1024 * 1024) { // > 100MB
            $this->onQueue('large-imports');
            $this->timeout = 7200; // 2 hours
            $this->tries = 2; // Fewer retries for large files
        } elseif ($fileSize > 10 * 1024 * 1024) { // > 10MB
            $this->onQueue('medium-imports');
            $this->timeout = 1800; // 30 minutes
        } else {
            $this->onQueue('small-imports');
            $this->timeout = 600; // 10 minutes
        }
    }
    
    protected function getFileInfo(): array
    {
        if (!file_exists($this->source)) {
            return [];
        }
        
        return [
            'size' => filesize($this->source),
            'name' => basename($this->source),
            'extension' => pathinfo($this->source, PATHINFO_EXTENSION),
            'modified' => filemtime($this->source)
        ];
    }
    
    protected function getFileSizeCategory(): string
    {
        $fileSize = file_exists($this->source) ? filesize($this->source) : 0;
        
        if ($fileSize > 100 * 1024 * 1024) return 'large';
        if ($fileSize > 10 * 1024 * 1024) return 'medium';
        return 'small';
    }
    
    protected function handleJobFailure(\Throwable $e): void
    {
        // Attempt to clean up any partial state
        try {
            // Clear any temporary files or state
            $this->cleanup();
        } catch (\Exception $cleanupException) {
            $this->logger->recovery($this->importId, 'cleanup', 'failed', [
                'cleanup_error' => $cleanupException->getMessage()
            ]);
        }
    }
    
    protected function cleanup(): void
    {
        // Clean up temporary files, database connections, etc.
        // This will be implemented based on specific storage drivers used
    }
    
    protected function storeResult($result, array $stats, float $duration): void
    {
        // Store import result in cache or database for retrieval
        $resultData = [
            'import_id' => $this->importId,
            'status' => 'completed',
            'result' => [
                'processed' => $result->getProcessed(),
                'imported' => $result->getImported(),
                'failed' => $result->getFailed(),
                'errors' => $result->getErrors()
            ],
            'duration' => $duration,
            'completed_at' => now(),
            'user_id' => $this->userId,
            'metadata' => $this->metadata
        ];
        
        // Store in cache for 24 hours
        cache()->put("import_result:{$this->importId}", $resultData, now()->addHours(24));
    }
    
    protected function storeFailure(\Throwable $exception): void
    {
        $failureData = [
            'import_id' => $this->importId,
            'status' => 'failed',
            'error' => [
                'message' => $exception->getMessage(),
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ],
            'failed_at' => now(),
            'user_id' => $this->userId,
            'attempts' => $this->attempts(),
            'metadata' => $this->metadata
        ];
        
        // Store in cache for 48 hours
        cache()->put("import_result:{$this->importId}", $failureData, now()->addHours(48));
    }
    
    protected function getRecoveryOptions(\Throwable $exception): array
    {
        if (method_exists($exception, 'getRecoveryOptions')) {
            return $exception->getRecoveryOptions();
        }
        
        return [
            'Check the error details and fix any configuration issues',
            'Retry the import with different settings',
            'Contact support if the problem persists'
        ];
    }
}