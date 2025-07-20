<?php

namespace Crumbls\Importer\States\Concerns;

use Crumbls\Importer\Jobs\TestWorkerJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

trait DetectsQueueWorkers
{
    /**
     * Check if queue workers are running for the specified queue
     */
    public function checkQueueWorkers(string $queueName): array
    {
        $cacheKey = "queue_workers_check_{$queueName}";
        
        // Use cache to avoid excessive checks (cache for 30 seconds)
        return Cache::remember($cacheKey, 30, function () use ($queueName) {
            return $this->performQueueWorkerCheck($queueName);
        });
    }

    /**
     * Perform the actual queue worker check
     */
    protected function performQueueWorkerCheck(string $queueName): array
    {
        try {
            $connection = config('queue.default');
            $driver = config("queue.connections.{$connection}.driver");

            Log::debug("Checking queue workers", [
                'queue' => $queueName,
                'connection' => $connection,
                'driver' => $driver
            ]);

            switch ($driver) {
                case 'redis':
                    return $this->checkRedisQueueWorkers($queueName, $connection);
                
                case 'database':
                    return $this->checkDatabaseQueueWorkers($queueName, $connection);
                
                case 'sync':
                    return $this->checkSyncQueueWorkers($queueName);
                
                case 'sqs':
                    return $this->checkSqsQueueWorkers($queueName, $connection);
                
                default:
                    return $this->checkGenericQueueWorkers($queueName, $driver);
            }
        } catch (\Exception $e) {
            Log::warning('Queue worker check failed', [
                'queue' => $queueName,
                'error' => $e->getMessage()
            ]);
            
            return [
                'has_workers' => false,
                'worker_count' => 0,
                'queue' => $queueName,
                'check_method' => 'error',
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString()
            ];
        }
    }

    /**
     * Check Redis-based queue workers
     */
    protected function checkRedisQueueWorkers(string $queueName, string $connection): array
    {
        try {
            $redis = Redis::connection(config("queue.connections.{$connection}.connection", 'default'));
            
            // Method 1: Check for worker heartbeats
            $workers = $redis->smembers('queues:workers') ?? [];
            $activeWorkers = 0;
            $queueWorkers = 0;
            
            foreach ($workers as $worker) {
                // Check if worker is alive (heartbeat within last 60 seconds)
                $lastSeen = $redis->get("worker:{$worker}:started_at");
                if ($lastSeen && (time() - $lastSeen) < 60) {
                    $activeWorkers++;
                    
                    // Check if this worker processes our queue
                    $workerQueues = $redis->get("worker:{$worker}:queues");
                    if ($workerQueues && (str_contains($workerQueues, $queueName) || str_contains($workerQueues, '*'))) {
                        $queueWorkers++;
                    }
                }
            }
            
            // Method 2: Check queue length and processing activity
            $queueLength = $redis->llen("queues:{$queueName}");
            $processingLength = $redis->llen("queues:{$queueName}:processing");
            
            // Method 3: Check for recent job processing activity
            $recentActivity = $this->checkRecentRedisActivity($redis, $queueName);
            
            $hasWorkers = $queueWorkers > 0 || ($recentActivity && $activeWorkers > 0);
            
            return [
                'has_workers' => $hasWorkers,
                'worker_count' => $queueWorkers > 0 ? $queueWorkers : ($recentActivity ? 'detected' : 0),
                'total_workers' => $activeWorkers,
                'queue' => $queueName,
                'queue_length' => $queueLength,
                'processing_length' => $processingLength,
                'recent_activity' => $recentActivity,
                'check_method' => 'redis_heartbeat',
                'checked_at' => now()->toISOString()
            ];
            
        } catch (\Exception $e) {
            Log::warning('Redis queue worker check failed', [
                'queue' => $queueName,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: assume no workers
            return [
                'has_workers' => false,
                'worker_count' => 0,
                'queue' => $queueName,
                'check_method' => 'redis_error',
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString()
            ];
        }
    }

    /**
     * Check for recent Redis activity
     */
    protected function checkRecentRedisActivity($redis, string $queueName): bool
    {
        try {
            // Check if jobs have been processed recently
            $activityKey = "queue:{$queueName}:last_activity";
            $lastActivity = $redis->get($activityKey);
            
            if ($lastActivity && (time() - $lastActivity) < 300) { // 5 minutes
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check database-based queue workers
     */
    protected function checkDatabaseQueueWorkers(string $queueName, string $connection): array
    {
        try {
            // Method 1: Check for jobs being processed (reserved_at is set)
            $processingJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '>', now()->subMinutes(5))
                ->count();

            // Method 2: Check for recent job activity (completed or failed)
            $recentCompleted = DB::table('failed_jobs')
                ->where('queue', $queueName)
                ->where('failed_at', '>', now()->subMinutes(5))
                ->count();

            // Method 3: Look for jobs that were recently processed
            $recentJobActivity = DB::table('jobs')
                ->where('queue', $queueName)
                ->where('created_at', '>', now()->subMinutes(10))
                ->count();

            $totalPendingJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->whereNull('reserved_at')
                ->count();

            // Method 4: Test actual worker responsiveness by dispatching a test job
            $workerTest = $this->testDatabaseWorkerResponsiveness($queueName);

            // Method 5: Check process list for running queue workers
            $processCheck = $this->checkQueueWorkersViaProcess($queueName);

            // Workers are likely active if:
            // 1. Jobs are currently being processed, OR
            // 2. Test job was processed quickly, OR
            // 3. Process check found workers, OR
            // 4. Recent failures indicate worker attempts, OR  
            // 5. Recent job activity with no backlog suggests active processing
            $hasWorkers = $processingJobs > 0 || 
                         $workerTest['responsive'] ||
                         ($processCheck['has_workers'] === true) ||
                         $recentCompleted > 0 || 
                         ($recentJobActivity > 0 && $totalPendingJobs < 10);

            $workerEstimate = 'inactive';
            if ($processingJobs > 0) {
                $workerEstimate = 'active';
            } elseif ($workerTest['responsive']) {
                $workerEstimate = 'responsive';
            } elseif ($processCheck['has_workers'] === true) {
                $workerEstimate = $processCheck['worker_count'];
            } elseif ($recentCompleted > 0) {
                $workerEstimate = 'detected';
            }

            return [
                'has_workers' => $hasWorkers,
                'worker_count' => $workerEstimate,
                'queue' => $queueName,
                'processing_jobs' => $processingJobs,
                'recent_failures' => $recentCompleted,
                'pending_jobs' => $totalPendingJobs,
                'recent_activity' => $recentJobActivity,
                'worker_test' => $workerTest,
                'process_check' => $processCheck,
                'check_method' => 'database_comprehensive',
                'checked_at' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            Log::warning('Database queue worker check failed', [
                'queue' => $queueName,
                'error' => $e->getMessage()
            ]);
            
            return [
                'has_workers' => false,
                'worker_count' => 0,
                'queue' => $queueName,
                'check_method' => 'database_error',
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString()
            ];
        }
    }

    /**
     * Test worker responsiveness by creating a test job and checking if it gets processed
     */
    protected function testDatabaseWorkerResponsiveness(string $queueName): array
    {
        try {
            // Rate limiting: only run test job once every 5 minutes per queue
            $rateLimitKey = "worker_test_rate_limit_{$queueName}";
            if (Cache::has($rateLimitKey)) {
                return Cache::get("worker_test_result_{$queueName}", [
                    'responsive' => false,
                    'method' => 'rate_limited',
                    'message' => 'Test skipped due to rate limiting'
                ]);
            }
            
            // Set rate limit (5 minutes)
            Cache::put($rateLimitKey, true, 300);
            
            // Create a unique test marker
            $testId = 'worker_test_' . uniqid();
            $testStartTime = microtime(true);
            
            // Insert a simple test job that just marks completion in cache
            $testJob = [
                'id' => null,
                'queue' => $queueName,
                'payload' => json_encode([
                    'uuid' => $testId,
                    'displayName' => 'Crumbls\\Importer\\Jobs\\TestWorkerJob',
                    'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'maxTries' => 1,
                    'delay' => null,
                    'timeout' => 10,
                    'data' => [
                        'commandName' => 'Crumbls\\Importer\\Jobs\\TestWorkerJob',
                        'command' => serialize(new TestWorkerJob($testId))
                    ]
                ]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp
            ];
            
            // Insert the test job
            DB::table('jobs')->insert($testJob);
            
            // Wait up to 5 seconds to see if the job gets processed
            $maxWaitTime = 5; // seconds
            $checkInterval = 0.1; // 100ms
            $elapsed = 0;
            
            while ($elapsed < $maxWaitTime) {
                // Check if test job completed (marked in cache)
                if (Cache::has("worker_test_completed_{$testId}")) {
                    $responseTime = microtime(true) - $testStartTime;
                    Cache::forget("worker_test_completed_{$testId}");
                    
                    $result = [
                        'responsive' => true,
                        'response_time' => round($responseTime, 3),
                        'test_id' => $testId,
                        'method' => 'test_job'
                    ];
                    
                    // Cache successful result for 5 minutes
                    Cache::put("worker_test_result_{$queueName}", $result, 300);
                    
                    return $result;
                }
                
                // Check if job was reserved (worker picked it up)
                $jobReserved = DB::table('jobs')
                    ->where('payload', 'like', "%{$testId}%")
                    ->whereNotNull('reserved_at')
                    ->exists();
                
                if ($jobReserved) {
                    $responseTime = microtime(true) - $testStartTime;
                    
                    // Clean up - delete the test job
                    DB::table('jobs')->where('payload', 'like', "%{$testId}%")->delete();
                    
                    $result = [
                        'responsive' => true,
                        'response_time' => round($responseTime, 3),
                        'test_id' => $testId,
                        'method' => 'job_reserved'
                    ];
                    
                    // Cache successful result for 5 minutes
                    Cache::put("worker_test_result_{$queueName}", $result, 300);
                    
                    return $result;
                }
                
                usleep($checkInterval * 1000000); // Convert to microseconds
                $elapsed += $checkInterval;
            }
            
            // Timeout - clean up test job
            DB::table('jobs')->where('payload', 'like', "%{$testId}%")->delete();
            
            $result = [
                'responsive' => false,
                'timeout' => $maxWaitTime,
                'test_id' => $testId,
                'method' => 'timeout'
            ];
            
            // Cache the result for 5 minutes to avoid repeated test jobs
            Cache::put("worker_test_result_{$queueName}", $result, 300);
            
            return $result;
            
        } catch (\Exception $e) {
            return [
                'responsive' => false,
                'error' => $e->getMessage(),
                'method' => 'error'
            ];
        }
    }

    /**
     * Check sync queue (always has "workers" since it runs immediately)
     */
    protected function checkSyncQueueWorkers(string $queueName): array
    {
        return [
            'has_workers' => true,
            'worker_count' => 'sync',
            'queue' => $queueName,
            'check_method' => 'sync_driver',
            'checked_at' => now()->toISOString()
        ];
    }

    /**
     * Check SQS queue workers (limited detection)
     */
    protected function checkSqsQueueWorkers(string $queueName, string $connection): array
    {
        try {
            // For SQS, we can't directly detect workers, but we can check queue attributes
            // This is a basic implementation - in production you might want to use CloudWatch metrics
            
            return [
                'has_workers' => true, // Assume workers exist for SQS
                'worker_count' => 'unknown',
                'queue' => $queueName,
                'check_method' => 'sqs_assumed',
                'note' => 'SQS worker detection is limited',
                'checked_at' => now()->toISOString()
            ];
        } catch (\Exception $e) {
            return [
                'has_workers' => false,
                'worker_count' => 0,
                'queue' => $queueName,
                'check_method' => 'sqs_error',
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString()
            ];
        }
    }

    /**
     * Generic check for unknown queue drivers
     */
    protected function checkGenericQueueWorkers(string $queueName, string $driver): array
    {
        Log::info("Unknown queue driver, assuming workers available", [
            'driver' => $driver,
            'queue' => $queueName
        ]);

        return [
            'has_workers' => true,
            'worker_count' => 'unknown',
            'queue' => $queueName,
            'check_method' => 'unknown_driver_assumed',
            'driver' => $driver,
            'checked_at' => now()->toISOString()
        ];
    }

    /**
     * Check if queue workers are running using system process detection (Linux/Mac)
     * This is an additional method that can be used for extra verification
     */
    public function checkQueueWorkersViaProcess(string $queueName): array
    {
        try {
            if (!function_exists('shell_exec') || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return [
                    'has_workers' => null,
                    'worker_count' => 'unknown',
                    'queue' => $queueName,
                    'check_method' => 'process_check_unavailable',
                    'checked_at' => now()->toISOString()
                ];
            }

            // Look for PHP processes running queue:work with our queue name
            $command = "ps aux | grep 'php.*queue:work' | grep -v grep";
            $processes = shell_exec($command);
            
            if (!$processes) {
                return [
                    'has_workers' => false,
                    'worker_count' => 0,
                    'queue' => $queueName,
                    'check_method' => 'process_check',
                    'checked_at' => now()->toISOString()
                ];
            }

            $lines = explode("\n", trim($processes));
            $relevantWorkers = 0;
            
            foreach ($lines as $line) {
                if (empty($line)) continue;
                
                // Check if this worker processes our specific queue or all queues
                if (str_contains($line, "--queue={$queueName}") || 
                    str_contains($line, "--queue=*") ||
                    !str_contains($line, '--queue=')) { // Default queue processing
                    $relevantWorkers++;
                }
            }

            return [
                'has_workers' => $relevantWorkers > 0,
                'worker_count' => $relevantWorkers,
                'queue' => $queueName,
                'total_processes' => count($lines),
                'check_method' => 'process_check',
                'checked_at' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            Log::warning('Process-based queue worker check failed', [
                'queue' => $queueName,
                'error' => $e->getMessage()
            ]);
            
            return [
                'has_workers' => null,
                'worker_count' => 'unknown',
                'queue' => $queueName,
                'check_method' => 'process_check_error',
                'error' => $e->getMessage(),
                'checked_at' => now()->toISOString()
            ];
        }
    }

    /**
     * Clear the queue worker check cache
     */
    public function clearQueueWorkerCache(string $queueName = null): void
    {
        if ($queueName) {
            Cache::forget("queue_workers_check_{$queueName}");
        } else {
            // Clear all queue worker caches
            $pattern = 'queue_workers_check_*';
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $keys = Cache::getRedis()->keys(Cache::getPrefix() . $pattern);
                if ($keys) {
                    Cache::getRedis()->del($keys);
                }
            }
        }
    }

    /**
     * Get a comprehensive queue status report
     */
    public function getQueueStatusReport(array $queues = ['default']): array
    {
        $report = [];
        
        foreach ($queues as $queue) {
            $report[$queue] = $this->checkQueueWorkers($queue);
            
            // Add process check if available
            $processCheck = $this->checkQueueWorkersViaProcess($queue);
            if ($processCheck['has_workers'] !== null) {
                $report[$queue]['process_check'] = $processCheck;
            }
        }
        
        return $report;
    }
}