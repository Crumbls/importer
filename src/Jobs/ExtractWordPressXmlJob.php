<?php

namespace Crumbls\Importer\Jobs;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Parsers\WordPressXmlStreamParser;
use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\States\Shared\FailedState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class   ExtractWordPressXmlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ImportContract $import;
    protected int $memoryLimit;
    protected int $timeLimit;

    public $timeout = 7200; // 2 hours maximum
    public $tries = 1; // Only retry 3 times instead of 1000
    public $maxExceptions = 1;

    public function __construct(ImportContract $import)
    {
        $this->import = $import;
        $this->memoryLimit = $this->parseMemoryLimit('512M'); // Conservative default
        $this->timeLimit = 7200; // 2 hours
        
        // Use default queue for now - can be customized later
        // $this->onQueue($this->determineQueue());
    }

    public function handle(): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            Log::info('Starting extraction job', [
                'import_id' => $this->import->getKey(),
                'source_type' => $this->import->source_type,
                'memory_limit' => $this->formatBytes($this->memoryLimit)
            ]);
            
            $this->updateStatus('initializing', 'Setting up extraction process...');
            
            // Setup storage and verify database connection
            $metadata = $this->import->metadata ?? [];
            $storage = $this->setupStorage($metadata);
            $sourceResolver = $this->setupSourceResolver();
            $this->updateStatus('processing', 'Starting XML stream processing...');
            
            // Create memory-efficient parser with progress callbacks
            $parser = new WordPressXmlStreamParser([
                'batch_size' => $this->calculateOptimalBatchSize(),
                'extract_meta' => true,
                'extract_comments' => true,
                'extract_terms' => true,
                'extract_users' => true,
                'memory_limit' => $this->formatBytes($this->memoryLimit),
                'progress_callback' => [$this, 'updateProgress'],
                'memory_callback' => [$this, 'monitorMemory'],
            ]);

            // Execute the parsing with automatic memory management
            $stats = $parser->parse($this->import, $storage, $sourceResolver);
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);
            
            // Update completion status with comprehensive stats
            $this->updateStatus('completed', 'Extraction completed successfully', [
                'extraction_completed' => true,
                'parsing_completed' => true,
                'parsing_stats' => $stats,
                'performance_stats' => [
                    'duration_seconds' => round($endTime - $startTime, 2),
                    'start_memory' => $startMemory,
                    'end_memory' => $endMemory,
                    'peak_memory' => $peakMemory,
                    'memory_efficiency' => round(($peakMemory - $startMemory) / (1024 * 1024), 2) . ' MB growth'
                ]
            ]);

        } catch (\Exception $e) {
	        $this->handleJobFailure($e, $startTime);
            throw $e;
        }
    }

    protected function setupStorage(array $metadata): StorageDriverContract
    {
        // Reconfigure database connection (lost between requests)
        if (isset($metadata['storage_connection'], $metadata['storage_path'])) {
            $connectionName = $metadata['storage_connection'];
            $sqliteDbPath = $metadata['storage_path'];
            
            config([
                "database.connections.{$connectionName}" => [
                    'driver' => 'sqlite',
                    'database' => $sqliteDbPath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ]
            ]);
        }

        return Storage::driver($metadata['storage_driver'])
            ->configureFromMetadata($metadata);
    }

    protected function setupSourceResolver(): SourceResolverManager
    {
		$sourceResolver = $this->import->getSourceResolver();

        return $sourceResolver;
    }

    protected function calculateOptimalBatchSize(): int
    {
        // Calculate batch size based on available memory
        $currentMemory = memory_get_usage(true);
        $availableMemory = $this->memoryLimit - $currentMemory;
        $memoryPerMB = 1024 * 1024;
        
        // Ensure we have positive available memory
        if ($availableMemory <= 0) {
            Log::warning('Memory limit already exceeded during batch size calculation', [
                'current_memory' => $this->formatBytes($currentMemory),
                'memory_limit' => $this->formatBytes($this->memoryLimit)
            ]);
            return 10; // Very small batches when memory is tight
        }
        
        if ($availableMemory > 100 * $memoryPerMB) {
            return 200; // High memory: large batches
        } elseif ($availableMemory > 50 * $memoryPerMB) {
            return 100; // Medium memory: medium batches
        } elseif ($availableMemory > 20 * $memoryPerMB) {
            return 50;  // Medium-low memory: smaller batches
        } else {
            return 25;  // Low memory: small batches
        }
    }

    protected function determineQueue(): string
    {
        // Route to different queues based on expected processing load
        $metadata = $this->import->metadata ?? [];
        
        // If we have file size info, use it to determine queue
        if (isset($metadata['file_size'])) {
            $fileSizeMB = $metadata['file_size'] / (1024 * 1024);
            
            if ($fileSizeMB > 100) {
                return 'heavy-imports'; // Large files get dedicated queue
            } elseif ($fileSizeMB > 10) {
                return 'medium-imports';
            }
        }
        
        return 'imports'; // Default queue for smaller files
    }

    public function updateProgress(int $current, int $total, string $type = 'items'): void
    {
        $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
        $memoryUsage = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        $progressData = [
            'extraction_progress' => $percentage,
            'extraction_current' => $current,
            'extraction_total' => $total,
            'extraction_type' => $type,
            'current_memory' => $memoryUsage,
            'peak_memory' => $peakMemory,
            'last_update' => now()->toISOString()
        ];

        // Update import metadata with progress
        $this->import->update([
            'metadata' => array_merge($this->import->metadata ?? [], $progressData)
        ]);

        // Log progress every 10% or every 1000 items
        if ($percentage % 10 == 0 || $current % 1000 == 0) {
            Log::info("Extraction progress: {$percentage}% ({$current}/{$total} {$type})", [
                'import_id' => $this->import->getKey(),
                'memory_usage' => $this->formatBytes($memoryUsage),
                'peak_memory' => $this->formatBytes($peakMemory)
            ]);
        }
    }

    public function monitorMemory(): void
    {
        $currentMemory = memory_get_usage(true);
        $memoryUsageRatio = $currentMemory / $this->memoryLimit;
        
        // Warning at 70% memory usage
        if ($memoryUsageRatio > 0.7) {
            Log::warning('High memory usage during extraction', [
                'import_id' => $this->import->getKey(),
                'memory_usage' => $this->formatBytes($currentMemory),
                'memory_limit' => $this->formatBytes($this->memoryLimit),
                'usage_ratio' => round($memoryUsageRatio * 100, 1) . '%'
            ]);
        }
        
        // Emergency cleanup at 85% memory usage
        if ($memoryUsageRatio > 0.85) {
            Log::error('Critical memory usage - forcing garbage collection', [
                'import_id' => $this->import->getKey(),
                'memory_usage' => $this->formatBytes($currentMemory)
            ]);
            
            gc_collect_cycles();
            
            // If still critical after GC, reduce batch size
            $newMemory = memory_get_usage(true);
            if ($newMemory / $this->memoryLimit > 0.80) {
                throw new \RuntimeException("Memory usage critical: {$this->formatBytes($newMemory)} / {$this->formatBytes($this->memoryLimit)}");
            }
        }
    }

    protected function updateStatus(string $status, string $message, array $additionalData = []): void
    {
        $updateData = array_merge([
            'extraction_status' => $status,
            'extraction_message' => $message,
            'extraction_updated_at' => now()->toISOString()
        ], $additionalData);

        $this->import->update([
            'metadata' => array_merge($this->import->metadata ?? [], $updateData)
        ]);
    }

    protected function handleJobFailure(Throwable $e, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

		$data = [
			'error_message' => $e->getMessage(),
			'failed_at' => now(),
			'metadata' => array_merge($this->import->metadata ?? [], [
				'extraction_status' => 'failed',
				'extraction_error' => $e->getMessage(),
				'extraction_failed_at' => now()->toISOString(),
				'failure_details' => [
					'duration_seconds' => $duration,
					'memory_usage' => $this->formatBytes($memoryUsage),
					'peak_memory' => $this->formatBytes($peakMemory),
					'exception_type' => get_class($e),
					'exception_file' => $e->getFile(),
					'exception_line' => $e->getLine()
				]
			])
		];

        // Update import status to failed
        $this->import->update($data);

		$this->import->getStateMachine()->transitionTo(FailedState::class);
		
		Log::error('Job failed with detailed information', [
			'import_id' => $this->import->getKey(),
			'exception' => $e->getMessage(),
			'type' => get_class($e),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'duration' => $duration,
			'memory_peak' => $this->formatBytes($peakMemory)
		]);
    }

    protected function parseMemoryLimit(string $limit): int
    {
        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));
        
        return match($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value
        };
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    public function failed(Throwable $exception): void
    {
dd(get_class_methods($this));
        $this->handleJobFailure($exception, 0);
    }
}