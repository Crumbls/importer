<?php

namespace Crumbls\Importer\Support;

use Exception;
use Symfony\Component\Process\Process;

class ParallelMigrationProcessor
{
    protected int $maxWorkers = 4;
    protected int $batchSize = 100;
    protected array $workerPool = [];
    protected array $completedBatches = [];
    protected array $failedBatches = [];
    protected float $startTime;
    protected string $tempDir;
    protected array $config;
    protected ?callable $progressCallback = null;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_workers' => 4,
            'batch_size' => 100,
            'memory_limit' => '256M',
            'timeout' => 300, // 5 minutes per batch
            'temp_dir' => sys_get_temp_dir(),
            'php_binary' => PHP_BINARY,
            'enable_progress_tracking' => true,
            'adaptive_batch_sizing' => true,
            'worker_restart_threshold' => 50 // Restart worker after 50 batches
        ], $config);
        
        $this->maxWorkers = $this->config['max_workers'];
        $this->batchSize = $this->config['batch_size'];
        $this->tempDir = $this->config['temp_dir'] . '/parallel_migration';
        
        $this->ensureTempDirectory();
    }
    
    public function processInParallel(array $data, callable $processor, array $options = []): array
    {
        $this->startTime = microtime(true);
        $this->progressCallback = $options['progress_callback'] ?? null;
        
        // Prepare batches
        $batches = $this->prepareBatches($data, $options);
        $totalBatches = count($batches);
        
        if ($totalBatches === 0) {
            return $this->generateEmptyResult();
        }
        
        // Optimize worker count based on workload
        $optimalWorkers = $this->calculateOptimalWorkerCount($totalBatches);
        
        $this->log("Starting parallel processing: {$totalBatches} batches, {$optimalWorkers} workers");
        
        // Create worker script
        $workerScript = $this->createWorkerScript($processor, $options);
        
        try {
            return $this->executeParallelProcessing($batches, $workerScript, $optimalWorkers);
        } finally {
            $this->cleanup();
        }
    }
    
    public function processWithAdaptiveBatching(array $data, callable $processor, array $options = []): array
    {
        $adaptiveBatcher = new AdaptiveBatchProcessor([
            'initial_batch_size' => $this->batchSize,
            'min_batch_size' => 10,
            'max_batch_size' => 1000,
            'performance_window' => 5 // Track last 5 batches for adaptation
        ]);
        
        $options['adaptive_batcher'] = $adaptiveBatcher;
        
        return $this->processInParallel($data, $processor, $options);
    }
    
    public function getPerformanceMetrics(): array
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalProcessed = array_sum(array_column($this->completedBatches, 'count'));
        $totalFailed = array_sum(array_column($this->failedBatches, 'count'));
        
        $throughput = $totalTime > 0 ? $totalProcessed / $totalTime : 0;
        
        return [
            'total_execution_time' => round($totalTime, 2),
            'total_processed' => $totalProcessed,
            'total_failed' => $totalFailed,
            'success_rate' => $totalProcessed + $totalFailed > 0 
                ? round(($totalProcessed / ($totalProcessed + $totalFailed)) * 100, 2) 
                : 0,
            'throughput_per_second' => round($throughput, 2),
            'completed_batches' => count($this->completedBatches),
            'failed_batches' => count($this->failedBatches),
            'worker_efficiency' => $this->calculateWorkerEfficiency(),
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ]
        ];
    }
    
    protected function prepareBatches(array $data, array $options): array
    {
        $batchSize = $options['batch_size'] ?? $this->batchSize;
        $batches = [];
        $batchIndex = 0;
        
        foreach (array_chunk($data, $batchSize) as $chunk) {
            $batches[] = [
                'id' => 'batch_' . $batchIndex,
                'index' => $batchIndex,
                'data' => $chunk,
                'count' => count($chunk),
                'priority' => $this->calculateBatchPriority($chunk, $options),
                'estimated_duration' => $this->estimateBatchDuration($chunk, $options)
            ];
            $batchIndex++;
        }
        
        // Sort by priority if needed
        if ($options['prioritize_batches'] ?? false) {
            usort($batches, fn($a, $b) => $b['priority'] <=> $a['priority']);
        }
        
        return $batches;
    }
    
    protected function calculateOptimalWorkerCount(int $totalBatches): int
    {
        // Don't use more workers than batches
        $maxUseful = min($totalBatches, $this->maxWorkers);
        
        // Consider system resources
        $availableCores = $this->getAvailableCores();
        $optimalForCores = max(1, $availableCores - 1); // Leave one core for main process
        
        return min($maxUseful, $optimalForCores);
    }
    
    protected function createWorkerScript(callable $processor, array $options): string
    {
        $workerScript = $this->tempDir . '/worker_script.php';
        
        // Create a PHP script that can process batches
        $scriptContent = $this->generateWorkerScriptContent($processor, $options);
        
        if (file_put_contents($workerScript, $scriptContent) === false) {
            throw new Exception("Failed to create worker script: {$workerScript}");
        }
        
        return $workerScript;
    }
    
    protected function executeParallelProcessing(array $batches, string $workerScript, int $workerCount): array
    {
        $queue = $batches;
        $activeWorkers = [];
        $results = [];
        $processedCount = 0;
        $totalBatches = count($batches);
        
        while (!empty($queue) || !empty($activeWorkers)) {
            // Start new workers if queue has items and we have capacity
            while (count($activeWorkers) < $workerCount && !empty($queue)) {
                $batch = array_shift($queue);
                $worker = $this->startWorker($batch, $workerScript);
                $activeWorkers[$worker['id']] = $worker;
                
                $this->log("Started worker {$worker['id']} for batch {$batch['id']}");
            }
            
            // Check for completed workers
            foreach ($activeWorkers as $workerId => $worker) {
                if (!$worker['process']->isRunning()) {
                    $result = $this->handleWorkerCompletion($worker);
                    $results[] = $result;
                    
                    if ($result['success']) {
                        $this->completedBatches[] = $result;
                    } else {
                        $this->failedBatches[] = $result;
                    }
                    
                    unset($activeWorkers[$workerId]);
                    $processedCount++;
                    
                    // Report progress
                    $this->reportProgress($processedCount, $totalBatches, $result);
                }
            }
            
            // Brief pause to avoid busy waiting
            usleep(100000); // 100ms
            
            // Check for memory pressure
            $this->checkMemoryPressure();
        }
        
        return $this->compileResults($results);
    }
    
    protected function startWorker(array $batch, string $workerScript): array
    {
        $workerId = uniqid('worker_');
        $batchFile = $this->saveBatchToFile($batch);
        $outputFile = $this->tempDir . "/{$workerId}_output.json";
        
        $command = [
            $this->config['php_binary'],
            '-d', 'memory_limit=' . $this->config['memory_limit'],
            $workerScript,
            $batchFile,
            $outputFile
        ];
        
        $process = new Process($command);
        $process->setTimeout($this->config['timeout']);
        $process->start();
        
        return [
            'id' => $workerId,
            'process' => $process,
            'batch' => $batch,
            'batch_file' => $batchFile,
            'output_file' => $outputFile,
            'start_time' => microtime(true)
        ];
    }
    
    protected function handleWorkerCompletion(array $worker): array
    {
        $process = $worker['process'];
        $batch = $worker['batch'];
        $duration = microtime(true) - $worker['start_time'];
        
        if ($process->isSuccessful()) {
            $output = $this->readWorkerOutput($worker['output_file']);
            
            return [
                'success' => true,
                'batch_id' => $batch['id'],
                'count' => $batch['count'],
                'duration' => $duration,
                'worker_id' => $worker['id'],
                'output' => $output,
                'throughput' => $batch['count'] / $duration
            ];
        } else {
            $error = $process->getErrorOutput() ?: $process->getOutput();
            
            return [
                'success' => false,
                'batch_id' => $batch['id'],
                'count' => $batch['count'],
                'duration' => $duration,
                'worker_id' => $worker['id'],
                'error' => $error,
                'exit_code' => $process->getExitCode()
            ];
        }
    }
    
    protected function generateWorkerScriptContent(callable $processor, array $options): string
    {
        // This is a simplified version - in practice, you'd need to serialize the processor
        // or create a more sophisticated worker architecture
        
        return <<<'PHP'
<?php
// Worker script for parallel processing
$batchFile = $argv[1] ?? null;
$outputFile = $argv[2] ?? null;

if (!$batchFile || !$outputFile) {
    exit(1);
}

try {
    // Load batch data
    $batchData = json_decode(file_get_contents($batchFile), true);
    
    // Process the batch
    $results = [];
    $startTime = microtime(true);
    
    foreach ($batchData['data'] as $item) {
        // Here you would call the actual processor
        // This is a placeholder that needs to be customized
        $results[] = [
            'item' => $item,
            'processed' => true,
            'timestamp' => time()
        ];
    }
    
    $duration = microtime(true) - $startTime;
    
    // Save results
    $output = [
        'batch_id' => $batchData['id'],
        'processed_count' => count($results),
        'duration' => $duration,
        'results' => $results,
        'memory_usage' => memory_get_usage(true),
        'success' => true
    ];
    
    file_put_contents($outputFile, json_encode($output));
    exit(0);
    
} catch (Exception $e) {
    $error = [
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ];
    
    file_put_contents($outputFile, json_encode($error));
    exit(1);
}
PHP;
    }
    
    protected function saveBatchToFile(array $batch): string
    {
        $batchFile = $this->tempDir . '/batch_' . $batch['id'] . '.json';
        
        if (file_put_contents($batchFile, json_encode($batch)) === false) {
            throw new Exception("Failed to save batch file: {$batchFile}");
        }
        
        return $batchFile;
    }
    
    protected function readWorkerOutput(string $outputFile): array
    {
        if (!file_exists($outputFile)) {
            return ['error' => 'No output file generated'];
        }
        
        $content = file_get_contents($outputFile);
        if ($content === false) {
            return ['error' => 'Failed to read output file'];
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON in output file'];
        }
        
        return $data;
    }
    
    protected function compileResults(array $results): array
    {
        $successful = array_filter($results, fn($r) => $r['success']);
        $failed = array_filter($results, fn($r) => !$r['success']);
        
        $totalProcessed = array_sum(array_column($successful, 'count'));
        $totalFailed = array_sum(array_column($failed, 'count'));
        $totalDuration = max(array_column($results, 'duration'));
        
        return [
            'success' => empty($failed),
            'total_processed' => $totalProcessed,
            'total_failed' => $totalFailed,
            'total_duration' => $totalDuration,
            'throughput' => $totalDuration > 0 ? ($totalProcessed + $totalFailed) / $totalDuration : 0,
            'successful_batches' => count($successful),
            'failed_batches' => count($failed),
            'batch_results' => $results,
            'performance_metrics' => $this->getPerformanceMetrics()
        ];
    }
    
    protected function calculateBatchPriority(array $data, array $options): float
    {
        // Simple priority calculation - can be customized
        $baseSize = count($data);
        $complexity = $options['complexity_factor'] ?? 1.0;
        
        return $baseSize * $complexity;
    }
    
    protected function estimateBatchDuration(array $data, array $options): float
    {
        // Estimate processing time based on data size and complexity
        $itemProcessingTime = $options['estimated_item_time'] ?? 0.01; // 10ms per item
        return count($data) * $itemProcessingTime;
    }
    
    protected function getAvailableCores(): int
    {
        // Try to detect number of CPU cores
        if (function_exists('shell_exec')) {
            $cores = shell_exec('nproc 2>/dev/null') ?: shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($cores) {
                return (int) trim($cores);
            }
        }
        
        // Fallback
        return 4;
    }
    
    protected function calculateWorkerEfficiency(): array
    {
        if (empty($this->completedBatches)) {
            return ['average_efficiency' => 0, 'worker_stats' => []];
        }
        
        $workerStats = [];
        $totalEfficiency = 0;
        
        foreach ($this->completedBatches as $batch) {
            $workerId = $batch['worker_id'];
            if (!isset($workerStats[$workerId])) {
                $workerStats[$workerId] = [
                    'batches_completed' => 0,
                    'total_items' => 0,
                    'total_time' => 0,
                    'throughput' => 0
                ];
            }
            
            $workerStats[$workerId]['batches_completed']++;
            $workerStats[$workerId]['total_items'] += $batch['count'];
            $workerStats[$workerId]['total_time'] += $batch['duration'];
        }
        
        foreach ($workerStats as $workerId => &$stats) {
            $stats['throughput'] = $stats['total_time'] > 0 
                ? $stats['total_items'] / $stats['total_time']
                : 0;
            $totalEfficiency += $stats['throughput'];
        }
        
        $averageEfficiency = count($workerStats) > 0 
            ? $totalEfficiency / count($workerStats)
            : 0;
        
        return [
            'average_efficiency' => round($averageEfficiency, 2),
            'worker_stats' => $workerStats
        ];
    }
    
    protected function checkMemoryPressure(): void
    {
        $currentUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        if ($currentUsage > $memoryLimit * 0.9) {
            $this->log("High memory usage detected: " . round($currentUsage / 1024 / 1024) . "MB");
            gc_collect_cycles();
        }
    }
    
    protected function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtoupper(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $limit
        };
    }
    
    protected function reportProgress(int $processed, int $total, array $result): void
    {
        if ($this->progressCallback) {
            $progress = [
                'processed' => $processed,
                'total' => $total,
                'percentage' => round(($processed / $total) * 100, 1),
                'last_batch' => $result,
                'throughput' => $result['throughput'] ?? 0,
                'estimated_remaining' => $this->estimateRemainingTime($processed, $total)
            ];
            
            call_user_func($this->progressCallback, $progress);
        }
    }
    
    protected function estimateRemainingTime(int $processed, int $total): float
    {
        if ($processed === 0) return 0;
        
        $elapsed = microtime(true) - $this->startTime;
        $rate = $processed / $elapsed;
        $remaining = $total - $processed;
        
        return $rate > 0 ? $remaining / $rate : 0;
    }
    
    protected function log(string $message): void
    {
        if ($this->config['enable_logging'] ?? true) {
            error_log("[ParallelProcessor] " . $message);
        }
    }
    
    protected function ensureTempDirectory(): void
    {
        if (!is_dir($this->tempDir)) {
            if (!mkdir($this->tempDir, 0755, true)) {
                throw new Exception("Failed to create temp directory: {$this->tempDir}");
            }
        }
    }
    
    protected function cleanup(): void
    {
        // Clean up temporary files
        $pattern = $this->tempDir . '/*';
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    
    protected function generateEmptyResult(): array
    {
        return [
            'success' => true,
            'total_processed' => 0,
            'total_failed' => 0,
            'total_duration' => 0,
            'throughput' => 0,
            'successful_batches' => 0,
            'failed_batches' => 0,
            'batch_results' => [],
            'performance_metrics' => [
                'total_execution_time' => 0,
                'throughput_per_second' => 0
            ]
        ];
    }
}