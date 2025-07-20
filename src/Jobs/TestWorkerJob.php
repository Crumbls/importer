<?php

namespace Crumbls\Importer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Simple test job used to detect if queue workers are active and responsive
 */
class TestWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $testId;

    /**
     * Job timeout in seconds
     */
    public int $timeout = 10;

    /**
     * Maximum number of attempts
     */
    public int $tries = 1;

    public function __construct(string $testId)
    {
        $this->testId = $testId;
    }

    /**
     * Execute the job - simply mark completion in cache
     */
    public function handle(): void
    {
        try {
            // Mark test completion in cache (TTL: 1 minute)
            Cache::put("worker_test_completed_{$this->testId}", true, 60);
            
            Log::debug("Test worker job completed", [
                'test_id' => $this->testId,
                'queue' => $this->queue ?? 'default'
            ]);
        } catch (\Exception $e) {
            Log::warning("Test worker job failed", [
                'test_id' => $this->testId,
                'error' => $e->getMessage()
            ]);
            
            // Still mark as completed so the test doesn't hang
            Cache::put("worker_test_completed_{$this->testId}", false, 60);
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning("Test worker job failed completely", [
            'test_id' => $this->testId,
            'error' => $exception->getMessage()
        ]);
        
        // Mark as completed (failed) so the test doesn't hang
        Cache::put("worker_test_completed_{$this->testId}", false, 60);
    }
}