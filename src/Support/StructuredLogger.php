<?php

namespace Crumbls\Importer\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class StructuredLogger
{
    protected LoggerInterface $logger;
    protected string $correlationId;
    protected array $context = [];
    
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->correlationId = $this->generateCorrelationId();
    }
    
    public function withCorrelationId(string $correlationId): self
    {
        $clone = clone $this;
        $clone->correlationId = $correlationId;
        return $clone;
    }
    
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);
        return $clone;
    }
    
    public function importStarted(string $importId, string $source, string $driver, array $context = []): void
    {
        $this->log('info', 'Import started', array_merge($context, [
            'event' => 'import.started',
            'import_id' => $importId,
            'source' => basename($source),
            'source_path' => $source,
            'driver' => $driver,
            'file_size' => file_exists($source) ? filesize($source) : null
        ]));
    }
    
    public function importCompleted(string $importId, array $stats, float $duration, array $context = []): void
    {
        $this->log('info', 'Import completed successfully', array_merge($context, [
            'event' => 'import.completed',
            'import_id' => $importId,
            'duration_seconds' => round($duration, 3),
            'processed_records' => $stats['processed'] ?? 0,
            'imported_records' => $stats['imported'] ?? 0,
            'failed_records' => $stats['failed'] ?? 0,
            'processing_rate' => $this->calculateRate($stats['processed'] ?? 0, $duration)
        ]));
    }
    
    public function importFailed(string $importId, \Throwable $exception, string $step = null, array $context = []): void
    {
        $this->log('error', 'Import failed', array_merge($context, [
            'event' => 'import.failed',
            'import_id' => $importId,
            'error_message' => $exception->getMessage(),
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
            'failed_step' => $step,
            'stack_trace' => $exception->getTraceAsString()
        ]));
    }
    
    public function stepStarted(string $importId, string $step, array $context = []): void
    {
        $this->log('debug', "Pipeline step started: {$step}", array_merge($context, [
            'event' => 'step.started',
            'import_id' => $importId,
            'step' => $step,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]));
    }
    
    public function stepCompleted(string $importId, string $step, float $duration, array $context = []): void
    {
        $this->log('debug', "Pipeline step completed: {$step}", array_merge($context, [
            'event' => 'step.completed',
            'import_id' => $importId,
            'step' => $step,
            'duration_seconds' => round($duration, 3),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]));
    }
    
    public function validationError(string $importId, string $field, string $error, $value, int $lineNumber = null, array $context = []): void
    {
        $this->log('warning', "Validation error: {$error}", array_merge($context, [
            'event' => 'validation.error',
            'import_id' => $importId,
            'field' => $field,
            'error' => $error,
            'value' => is_scalar($value) ? (string) $value : gettype($value),
            'line_number' => $lineNumber
        ]));
    }
    
    public function performanceWarning(string $importId, string $metric, $value, $threshold, array $context = []): void
    {
        $this->log('warning', "Performance threshold exceeded: {$metric}", array_merge($context, [
            'event' => 'performance.warning',
            'import_id' => $importId,
            'metric' => $metric,
            'current_value' => $value,
            'threshold' => $threshold,
            'memory_usage' => memory_get_usage(true)
        ]));
    }
    
    public function recovery(string $importId, string $errorType, string $action, array $context = []): void
    {
        $this->log('info', "Recovery action taken: {$action}", array_merge($context, [
            'event' => 'recovery.action',
            'import_id' => $importId,
            'error_type' => $errorType,
            'recovery_action' => $action
        ]));
    }
    
    public function checkpoint(string $importId, string $checkpointId, int $progress, array $context = []): void
    {
        $this->log('debug', 'Checkpoint created', array_merge($context, [
            'event' => 'checkpoint.created',
            'import_id' => $importId,
            'checkpoint_id' => $checkpointId,
            'progress' => $progress,
            'memory_usage' => memory_get_usage(true)
        ]));
    }
    
    protected function log(string $level, string $message, array $context = []): void
    {
        $structuredContext = array_merge($this->context, $context, [
            'correlation_id' => $this->correlationId,
            'timestamp' => microtime(true),
            'service' => 'crumbls-importer',
            'version' => '1.0.0', // Should come from config or composer.json
            'environment' => app()->environment() ?? 'unknown',
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]);
        
        $this->logger->log($level, $message, $structuredContext);
    }
    
    protected function generateCorrelationId(): string
    {
        return uniqid('imp_', true);
    }
    
    protected function calculateRate(int $count, float $duration): string
    {
        if ($duration <= 0) {
            return '0 records/sec';
        }
        
        $rate = $count / $duration;
        
        if ($rate >= 1000) {
            return number_format($rate / 1000, 1) . 'K records/sec';
        }
        
        return number_format($rate, 0) . ' records/sec';
    }
}