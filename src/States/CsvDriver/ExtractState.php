<?php

namespace Crumbls\Importer\States\CsvDriver;

use Crumbls\Importer\Console\Prompts\ExtractionPrompt;
use Crumbls\Importer\Jobs\ExtractWordPressXmlJob;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Parsers\CsvStreamParser;
use Crumbls\Importer\Parsers\WordPressXmlStreamParser;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\Concerns\DetectsQueueWorkers;
use Crumbls\Importer\States\Concerns\HasSourceResolver;
use Crumbls\Importer\States\Concerns\HasStorageDriver;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\States\XmlDriver\ExtractState as BaseState;
use Crumbls\Importer\Support\SourceResolverManager;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtractState extends BaseState
{
	use HasSourceResolver, DetectsQueueWorkers, HasStorageDriver;

	public function execute(): bool
	{
		$record = $this->getRecord();
		$metadata = $record->metadata ?? [];
		
		// Get extraction status from metadata (not job_status from base class)
		$extractionStatus = $metadata['extraction_status'] ?? null;

		// First check if extraction is already completed
		if ($this->isExtractionComplete($record)) {
			$metadata['extraction_status'] = 'completed';
			$record->update(['metadata' => $metadata]);
			$this->transitionToNextState($record);
			return true;
		}

		switch ($extractionStatus) {
			case 'completed':
				$this->transitionToNextState($record);
				return true;
				
			case 'failed':
				return false;
				
			case 'waiting_for_workers':
				// No queue workers detected, check again with enhanced detection
				$queueStatus = $this->checkQueueWorkers($this->determineQueue($record));

				if ($queueStatus['has_workers']) {
					
					// Clear previous dispatch flag to allow new dispatch
					$metadata['extraction_job_dispatched'] = false;
					$metadata['extraction_status'] = 'workers_detected';
					$metadata['queue_check'] = $queueStatus;
					$record->update(['metadata' => $metadata]);
					return $this->executeStart($record);
				} else {
					// Still no workers, keep waiting
					$metadata['queue_check'] = $queueStatus;
					$metadata['last_worker_check'] = now()->toISOString();
					$record->update(['metadata' => $metadata]);
					return true;
				}
				
			case 'processing':
			case 'queued':
				
				// First check for explicit job failures
				$jobFailure = $this->checkForJobFailures($record);
				if ($jobFailure) {
					
					$metadata['extraction_status'] = 'failed';
					$metadata['extraction_error'] = 'Job failed: ' . substr($jobFailure['exception'], 0, 500);
					$metadata['job_failure_details'] = $jobFailure;
					$metadata['extraction_job_dispatched'] = false; // Clear dispatch flag
					$record->update(['metadata' => $metadata]);
					return false; // Signal failure to trigger state transition
				}
				
				// Job is running, check if it's still active
				$isStillRunning = $this->isJobStillRunning($record);
				
				// Also check database directly for debugging
				$jobsInQueue = DB::table('jobs')
					->where('queue', $this->determineQueue($record))
					->count();

				$failedJobs = DB::table('failed_jobs')
					->where('queue', $this->determineQueue($record))
					->where('created_at', '>', now()->subMinutes(10))
					->count();

				if ($isStillRunning) {
					// Job is running, don't transition yet
					$metadata['last_progress_check'] = now()->toISOString();
					$record->update(['metadata' => $metadata]);
					return true;
				} else {
					// Job might have failed silently, check for completion
					if ($this->isExtractionComplete($record)) {
						$metadata['extraction_status'] = 'completed';
						$record->update(['metadata' => $metadata]);
						$this->transitionToNextState($record);
						return true;
					} else {

						$metadata['extraction_job_dispatched'] = false;
						$metadata['extraction_status'] = 'restarting';
						$record->update(['metadata' => $metadata]);
						
						return $this->executeStart($record);
					}
				}
				
			default:
				// No extraction started yet, start it
				return $this->executeStart($record);
		}
	}

	protected function executeStart(ImportContract $record): bool
	{
			$metadata = $record->metadata ?? [];
			
			// CRITICAL: Check if we've already dispatched a job for this import
			if (isset($metadata['extraction_job_dispatched']) && $metadata['extraction_job_dispatched']) {
				$dispatchedAt = $metadata['extraction_dispatched_at'] ?? 'unknown';
				
				// If job was dispatched but status is unclear, set it to queued
				if (!isset($metadata['extraction_status']) || $metadata['extraction_status'] === 'dispatching') {
					$metadata['extraction_status'] = 'queued';
					$record->update(['metadata' => $metadata]);
				}
				
				return true; // Don't dispatch again
			}

		$prefix = 'storage_';

		$fileSize = Arr::get($metadata, 'storage_size', null);

		if ($fileSize === null) {
				$sourceMeta = $record->getSourceMeta();
				$sourceMeta = array_combine(
					array_map(
						function($k) use ($prefix) {return $prefix . $k; },
						array_keys($sourceMeta)
					),
					$sourceMeta
				);
				$metadata = array_merge($sourceMeta, $metadata);

			$fileSize = Arr::get($metadata, 'storage_size', null);
		}

			$fileSizeMB = $fileSize / 1024 / 1024;


			// Force synchronous processing for console commands
			$forceSync = app()->runningInConsole() &&
						$fileSizeMB < 10;

			// Force sync for development/testing
			$forceSync = true;

			if ($forceSync) {
				$this->performExtractionSync($record, true);
				$this->transitionToNextState($record);
				return true;
			} else {
				// Check if queue workers are running before dispatching with enhanced detection
				$queueName = $this->determineQueue($record);
				$queueStatus = $this->checkQueueWorkers($queueName);

				if (!$queueStatus['has_workers']) {
					// No queue workers detected with enhanced detection
					$metadata['extraction_status'] = 'waiting_for_workers';
					$metadata['extraction_error'] = $this->formatQueueWorkerError($queueStatus);
					$metadata['queue_check'] = $queueStatus;
					$metadata['file_size'] = $fileSize;
					$record->update(['metadata' => $metadata]);

					return true;
				}
				
				// Queue workers are running, dispatch the job
				$metadata['extraction_status'] = 'dispatching';
				$metadata['queue_check'] = $queueStatus;
				$metadata['file_size'] = $fileSize;
				$record->update(['metadata' => $metadata]);

				dd(__LINE__);
				ExtractWordPressXmlJob::dispatch($record)
					->onQueue($queueStatus['queue'])
					->delay(now()->addSeconds(2));

				// Mark job as dispatched
				$metadata['extraction_job_dispatched'] = true;
				$metadata['extraction_status'] = 'queued';
				$metadata['extraction_dispatched_at'] = now()->toISOString();
				$metadata['queue_check'] = $queueStatus;
				$record->update(['metadata' => $metadata]);

				return true;
			}
	}
	
	
	protected function formatFileSize(int $bytes): string
	{
		if ($bytes === 0) return 'Unknown size';
		
		$units = ['B', 'KB', 'MB', 'GB'];
		$power = floor(log($bytes, 1024));
		return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
	}
	
	protected function isJobStillRunning(ImportContract $record): bool
	{
		$metadata = $record->metadata ?? [];
		
		// Check if job was dispatched recently (within last 5 minutes)
		$dispatchedAt = $metadata['extraction_dispatched_at'] ?? null;
		if ($dispatchedAt) {
			$dispatchTime = \Carbon\Carbon::parse($dispatchedAt);
			$timeSinceDispatch = $dispatchTime->diffInMinutes(now());
			
			// If more than 5 minutes and still "queued", consider it stalled
			if ($timeSinceDispatch > 5 && $metadata['extraction_status'] === 'queued') {
				return false;
			}
			
			// If dispatched very recently (less than 30 seconds), assume still starting
			if ($timeSinceDispatch < 0.5) {
				return true;
			}
		}
		
		// Check for recent progress updates (job is actively running)
		$lastProgressUpdate = $metadata['extraction_last_update'] ?? null;
		if ($lastProgressUpdate) {
			$lastUpdate = \Carbon\Carbon::parse($lastProgressUpdate);
			$timeSinceUpdate = $lastUpdate->diffInMinutes(now());
			
			// If no progress update in 2 minutes, consider stalled
			return $timeSinceUpdate < 2;
		}
		
		// No progress updates and not recently dispatched - check if job actually exists
		return $this->checkIfJobExistsInQueue($record);
	}
	
	protected function checkIfJobExistsInQueue(ImportContract $record): bool
	{
		try {
			// For database queues, check if job exists
			$connection = config('queue.default');
			$driver = config("queue.connections.{$connection}.driver");
			
			if ($driver === 'database') {
				$jobExists = DB::table('jobs')
					->where('queue', $this->determineQueue($record))
					->where('payload', 'like', '%ExtractWordPressXmlJob%')
					->where('payload', 'like', '%"import_id":' . $record->getKey() . '%')
					->exists();

				return $jobExists;
			}

			return false;
			
		} catch (\Exception $e) {
			throw $e;
//			return false;
		}
	}
	
	protected function isExtractionComplete(ImportContract $record): bool {
		$metadata = $record->metadata ?? [];
		return ($metadata['extraction_completed'] ?? false) || 
			   ($metadata['parsing_completed'] ?? false);
	}

    protected function getJobStatusMessage(string $status, float $progress, int $current, int $total): string
    {
        switch($status) {
            case 'queued':
                return '⏳ **Queued for Processing**

Your WordPress XML import has been queued for background processing. Large files are processed efficiently using memory-optimized streaming.';
            
            case 'initializing':
                return '🔄 **Initializing Extraction**

Setting up optimized streaming parser and database connections...';
            
            case 'processing':
                return "⚡ **Processing WordPress XML**

**Progress:** {$progress}% (" . number_format($current) . " / " . number_format($total) . " items processed)

🎯 Using memory-efficient streaming to handle files of any size safely. Your import will complete automatically and advance to the next step.";
            
            case 'completed':
                return '✅ **Extraction Complete**

WordPress XML processing completed successfully! Moving to analysis phase...';
            
            case 'failed':
                return '❌ **Extraction Failed**

There was an error processing your WordPress XML file. Please check the logs for details.';
            
            default:
                return '🚀 **Starting Background Processing**

Preparing to process your WordPress XML file using optimized streaming technology...';
        }
    }

    protected function determineQueue(ImportContract $record): string
    {
        // Estimate processing load based on file size or metadata
        $metadata = $record->metadata ?? [];
        
        if (isset($metadata['file_size'])) {
            $fileSizeMB = $metadata['file_size'] / (1024 * 1024);
            
            if ($fileSizeMB > 100) {
                return 'heavy-imports'; // Large files
            } elseif ($fileSizeMB > 10) {
                return 'medium-imports'; // Medium files
            }
        }
        
        return config('importer.queue.queue', 'default'); // Use configured queue
    }
    
    /**
     * Format a user-friendly error message for queue worker detection failures
     */
    protected function formatQueueWorkerError(array $queueStatus): string
    {
        $queue = $queueStatus['queue'] ?? 'default';
        $checkMethod = $queueStatus['check_method'] ?? 'unknown';
        
        $message = "No queue workers detected for queue '{$queue}'";
        
        // Add specific guidance based on detection method
        switch ($checkMethod) {
            case 'database_comprehensive':
                $message .= " (checked database activity, processes, and responsiveness)";
                break;
            case 'redis_heartbeat':
                $message .= " (checked Redis worker heartbeats)";
                break;
            case 'process_check':
                $message .= " (checked running processes)";
                break;
            case 'database_error':
            case 'redis_error':
                $message .= " (detection failed due to error)";
                if (isset($queueStatus['error'])) {
                    $message .= ": " . substr($queueStatus['error'], 0, 100);
                }
                break;
        }
        
        return $message . ". Please start workers with: php artisan queue:work --queue={$queue}";
    }

    protected function performExtractionSync(ImportContract $record, bool $allowSync = false): void
    {
        if (!$allowSync) {
            // UI fallback - don't run sync extraction for large files
            try {
                $record->update([
                    'metadata' => array_merge($record->metadata ?? [], [
                        'extraction_status' => 'failed',
                        'extraction_message' => 'Synchronous extraction not recommended for large files. Please ensure your queue workers are running.'
                    ])
                ]);
                
                throw new \Exception('Synchronous extraction not recommended for large files. Please ensure your queue workers are running.');
                
            } catch (\Exception $e) {
                $record->update([
                    'state' => FailedState::class,
                    'error_message' => 'Extraction failed: ' . $e->getMessage(),
                    'failed_at' => now(),
                ]);
            }
            return;
        }
        
        // Command-line synchronous extraction
        try {
            $record->update([
                'metadata' => array_merge($record->metadata ?? [], [
                    'extraction_status' => 'processing',
                    'extraction_message' => 'Running synchronous extraction for command-line...'
                ])
            ]);
            
            $metadata = $record->metadata ?? [];
            
            // Reconfigure the database connection since it's lost between requests
            if (isset($metadata['storage_connection']) && isset($metadata['storage_path'])) {
                $connectionName = $metadata['storage_connection'];
                $sqliteDbPath = $metadata['storage_path'];
                
                // Re-add SQLite connection to Laravel's database config
                config([
                    "database.connections.{$connectionName}" => [
                        'driver' => 'sqlite',
                        'database' => $sqliteDbPath,
                        'prefix' => '',
                        'foreign_key_constraints' => true,
                    ]
                ]);
            }
            
            // Get the storage driver using the concern
            $storage = $this->getStorageDriver();

            // Set up the source resolver
            $sourceResolver = new \Crumbls\Importer\Support\SourceResolverManager();
            if ($record->source_type == 'storage') {
                $sourceResolver->addResolver(new \Crumbls\Importer\Resolvers\FileSourceResolver(
                    $record->source_type,
                    $record->source_detail
                ));
            } else {
                throw new \Exception("Unsupported source type: {$record->source_type}");
            }

			// Create and configure the CsvStreamParser for command-line use

            $parser = new CsvStreamParser([
                'batch_size' => 50,
                'delimiter' => $record->metadata['delimiter'] ?? ',',
                'enclosure' => $record->metadata['enclosure'] ?? '"',
                'escape' => $record->metadata['escape'] ?? '\\',
				'headers' => $record->metadata['headers'],
				'headers_first_row' => $record->metadata['headers_first_row'],
                'progress_callback' => function($processed)  {
                },
                'memory_callback' => null,
            ]);

            $stats = $parser->parse($record, $storage, $sourceResolver);

            $record->update([
                'metadata' => array_merge($metadata, [
                    'extraction_completed' => true,
                    'parsing_completed' => true,
                    'parsing_stats' => $stats,
                    'processed_at' => now()->toISOString(),
                    'extraction_status' => 'completed',
                ])
            ]);

            $tableName = $metadata['target_table'] ?? 'csv_imports';
            $rowCount = $this->countStorageRecords($tableName);

        } catch (\Exception $e) {
            $record->update([
                'state' => FailedState::class,
                'error_message' => 'Extraction failed: ' . $e->getMessage(),
                'failed_at' => now(),
                'metadata' => array_merge($record->metadata ?? [], [
                    'extraction_status' => 'failed',
                    'extraction_error' => $e->getMessage(),
                ])
            ]);

            throw $e;
        }
    }

    protected function transitionToNextState($record): void
    {
            // Get the driver and its preferred transitions
            $driver = $record->getDriver();
            $config = $driver->config();
            
            // Get the next preferred state from current state
            $nextState = $config->getPreferredTransition(static::class);
            
            if ($nextState) {
                // Get the state machine and transition
                $stateMachine = $record->getStateMachine();
                $stateMachine->transitionTo($nextState);
                
                // Update the record with new state
                $record->update(['state' => $nextState]);
            } else {
                // If no next state, this might be the final state
                $record->update([
                    'completed_at' => now(),
                    'progress' => 100
                ]);
            }
    }

    private function executeStartAsync(ImportContract $record): void
    {

            // Mark extraction as started to prevent multiple starts
            $metadata = $record->metadata ?? [];
            $metadata['extraction_started'] = true;
            $metadata['extraction_started_at'] = now()->toISOString();
            
            $record->update(['metadata' => $metadata]);
            
            // Dispatch the background job for extraction
            ExtractWordPressXmlJob::dispatch($record)
                ->delay(now()->addSeconds(2)); // Small delay for UI feedback
                
            // Update metadata to show job was dispatched
            $metadata['extraction_job_dispatched'] = true;
            $metadata['extraction_status'] = 'queued';
            $metadata['extraction_dispatched_at'] = now()->toISOString();
            
            $record->update(['metadata' => $metadata]);
            
    }
    
    public static function getCommandPrompt(): string
    {
		return ExtractionPrompt::class;
    }
    
    public function shouldContinuePolling(): bool
    {
        $metadata = $this->getRecord()->metadata ?? [];
        $extractionStatus = $metadata['extraction_status'] ?? null;
        
        // Continue polling if we're waiting for workers or processing
        return in_array($extractionStatus, [
            'waiting_for_workers',
            'workers_detected', 
            'dispatching',
            'queued',
            'processing'
        ]);
    }
    
    protected function checkForJobFailures(ImportContract $record): ?array
    {
        try {
            // Check for recent failed jobs related to this import
            $recentFailures = DB::table('failed_jobs')
                ->where('created_at', '>', now()->subMinutes(10))
                ->where('payload', 'like', '%ExtractWordPressXmlJob%')
                ->where('payload', 'like', '%"import_id":' . $record->getKey() . '%')
                ->orderBy('created_at', 'desc')
                ->first();
                
            if ($recentFailures) {
                $payload = json_decode($recentFailures->payload, true);
                return [
                    'failed_at' => $recentFailures->failed_at,
                    'exception' => $recentFailures->exception,
                    'payload' => $payload,
                    'queue' => $recentFailures->queue ?? 'unknown'
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }


}