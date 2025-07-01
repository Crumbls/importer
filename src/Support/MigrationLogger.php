<?php

namespace Crumbls\Importer\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class MigrationLogger
{
    protected ?LoggerInterface $logger;
    protected string $migrationId;
    protected array $context = [];
    protected array $metrics = [];
    protected float $startTime;
    
    public function __construct(?LoggerInterface $logger = null, string $migrationId = 'unknown')
    {
        $this->logger = $logger;
        $this->migrationId = $migrationId;
        $this->startTime = microtime(true);
        $this->context = [
            'migration_id' => $migrationId,
            'started_at' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
    }
    
    public function migrationStarted(array $config = []): void
    {
        $this->log(LogLevel::INFO, 'Migration started', [
            'config' => $config,
            'system_info' => $this->getSystemInfo()
        ]);
        
        $this->recordMetric('migration_started', [
            'timestamp' => microtime(true),
            'config' => $config
        ]);
    }
    
    public function migrationCompleted(array $summary = []): void
    {
        $duration = microtime(true) - $this->startTime;
        
        $this->log(LogLevel::INFO, 'Migration completed successfully', [
            'summary' => $summary,
            'duration_seconds' => round($duration, 2),
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'metrics' => $this->getMetricsSummary()
        ]);
        
        $this->recordMetric('migration_completed', [
            'timestamp' => microtime(true),
            'duration' => $duration,
            'summary' => $summary
        ]);
    }
    
    public function migrationFailed(\Exception $exception, array $context = []): void
    {
        $duration = microtime(true) - $this->startTime;
        
        $this->log(LogLevel::ERROR, 'Migration failed', [
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ],
            'context' => $context,
            'duration_seconds' => round($duration, 2),
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'metrics' => $this->getMetricsSummary()
        ]);
        
        $this->recordMetric('migration_failed', [
            'timestamp' => microtime(true),
            'duration' => $duration,
            'exception' => get_class($exception),
            'context' => $context
        ]);
    }
    
    public function entityProcessingStarted(string $entityType, int $recordCount): void
    {
        $this->log(LogLevel::INFO, "Started processing {$entityType}", [
            'entity_type' => $entityType,
            'record_count' => $recordCount,
            'memory_usage' => $this->formatBytes(memory_get_usage(true))
        ]);
        
        $this->recordMetric("entity_{$entityType}_started", [
            'timestamp' => microtime(true),
            'record_count' => $recordCount
        ]);
    }
    
    public function entityProcessingCompleted(string $entityType, array $stats): void
    {
        $this->log(LogLevel::INFO, "Completed processing {$entityType}", [
            'entity_type' => $entityType,
            'stats' => $stats,
            'memory_usage' => $this->formatBytes(memory_get_usage(true))
        ]);
        
        $this->recordMetric("entity_{$entityType}_completed", [
            'timestamp' => microtime(true),
            'stats' => $stats
        ]);
    }
    
    public function batchProcessed(string $entityType, int $batchNumber, array $stats): void
    {
        $this->log(LogLevel::DEBUG, "Processed batch {$batchNumber} for {$entityType}", [
            'entity_type' => $entityType,
            'batch_number' => $batchNumber,
            'stats' => $stats,
            'memory_usage' => $this->formatBytes(memory_get_usage(true))
        ]);
        
        $this->recordMetric("batch_processed", [
            'timestamp' => microtime(true),
            'entity_type' => $entityType,
            'batch_number' => $batchNumber,
            'stats' => $stats
        ]);
    }
    
    public function validationIssue(string $level, string $message, array $context = []): void
    {
        $logLevel = match ($level) {
            'error' => LogLevel::ERROR,
            'warning' => LogLevel::WARNING,
            default => LogLevel::INFO
        };
        
        $this->log($logLevel, "Validation {$level}: {$message}", $context);
        
        $this->recordMetric('validation_issue', [
            'timestamp' => microtime(true),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ]);
    }
    
    public function performanceAlert(string $metric, $value, array $context = []): void
    {
        $this->log(LogLevel::WARNING, "Performance alert: {$metric}", [
            'metric' => $metric,
            'value' => $value,
            'context' => $context,
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'execution_time' => round(microtime(true) - $this->startTime, 2)
        ]);
        
        $this->recordMetric('performance_alert', [
            'timestamp' => microtime(true),
            'metric' => $metric,
            'value' => $value,
            'context' => $context
        ]);
    }
    
    public function checkpointCreated(string $checkpointId, array $data): void
    {
        $this->log(LogLevel::INFO, "Checkpoint created: {$checkpointId}", [
            'checkpoint_id' => $checkpointId,
            'data_summary' => [
                'entities' => array_keys($data),
                'total_records' => array_sum(array_map('count', $data))
            ],
            'memory_usage' => $this->formatBytes(memory_get_usage(true))
        ]);
        
        $this->recordMetric('checkpoint_created', [
            'timestamp' => microtime(true),
            'checkpoint_id' => $checkpointId,
            'data_size' => count($data)
        ]);
    }
    
    public function retryAttempt(int $attempt, string $reason, array $context = []): void
    {
        $this->log(LogLevel::WARNING, "Retry attempt {$attempt}: {$reason}", [
            'attempt_number' => $attempt,
            'reason' => $reason,
            'context' => $context
        ]);
        
        $this->recordMetric('retry_attempt', [
            'timestamp' => microtime(true),
            'attempt' => $attempt,
            'reason' => $reason
        ]);
    }
    
    public function recordMetric(string $name, array $data): void
    {
        $this->metrics[] = [
            'name' => $name,
            'timestamp' => microtime(true),
            'data' => $data,
            'memory_usage' => memory_get_usage(true)
        ];
    }
    
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    
    public function getMetricsSummary(): array
    {
        $summary = [
            'total_events' => count($this->metrics),
            'duration' => microtime(true) - $this->startTime,
            'events_by_type' => []
        ];
        
        foreach ($this->metrics as $metric) {
            $name = $metric['name'];
            if (!isset($summary['events_by_type'][$name])) {
                $summary['events_by_type'][$name] = 0;
            }
            $summary['events_by_type'][$name]++;
        }
        
        return $summary;
    }
    
    public function getPerformanceStats(): array
    {
        $batchMetrics = array_filter($this->metrics, fn($m) => $m['name'] === 'batch_processed');
        
        if (empty($batchMetrics)) {
            return [];
        }
        
        $totalRecords = 0;
        $batchCount = count($batchMetrics);
        $minTime = PHP_FLOAT_MAX;
        $maxTime = 0;
        $totalTime = 0;
        
        foreach ($batchMetrics as $metric) {
            $stats = $metric['data']['stats'] ?? [];
            $records = $stats['processed'] ?? 0;
            $totalRecords += $records;
            
            // Calculate batch processing time (rough estimate)
            $batchTime = 1; // placeholder - would need actual timing
            $minTime = min($minTime, $batchTime);
            $maxTime = max($maxTime, $batchTime);
            $totalTime += $batchTime;
        }
        
        $avgTime = $batchCount > 0 ? $totalTime / $batchCount : 0;
        $recordsPerSecond = $totalTime > 0 ? $totalRecords / $totalTime : 0;
        
        return [
            'total_records_processed' => $totalRecords,
            'total_batches' => $batchCount,
            'avg_batch_time' => round($avgTime, 3),
            'min_batch_time' => round($minTime, 3),
            'max_batch_time' => round($maxTime, 3),
            'records_per_second' => round($recordsPerSecond, 2),
            'total_processing_time' => round($totalTime, 2)
        ];
    }
    
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logger) {
            return;
        }
        
        $fullContext = array_merge($this->context, $context, [
            'elapsed_time' => round(microtime(true) - $this->startTime, 2)
        ]);
        
        $this->logger->log($level, $message, $fullContext);
    }
    
    protected function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'current_memory' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true))
        ];
    }
    
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}