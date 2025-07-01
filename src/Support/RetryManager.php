<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Exceptions\ConnectionException;
use Crumbls\Importer\Exceptions\MigrationException;

class RetryManager
{
    protected array $config;
    protected array $attemptHistory = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_attempts' => 3,
            'backoff_strategy' => 'exponential', // linear, exponential, fixed
            'base_delay' => 1, // seconds
            'max_delay' => 60, // seconds
            'jitter' => true, // add randomness to prevent thundering herd
            'retry_on' => [
                ConnectionException::class,
                'connection_timeout',
                'lock_timeout'
            ]
        ], $config);
    }
    
    public function retry(callable $operation, array $context = []): mixed
    {
        $attempt = 1;
        $lastException = null;
        
        while ($attempt <= $this->config['max_attempts']) {
            try {
                $result = $operation($attempt, $context);
                
                // Success - record attempt history and return
                $this->recordSuccess($attempt, $context);
                return $result;
                
            } catch (\Exception $exception) {
                $lastException = $exception;
                
                // Check if this exception should trigger a retry
                if (!$this->shouldRetry($exception, $attempt)) {
                    throw $exception;
                }
                
                // Record failed attempt
                $this->recordAttempt($attempt, $exception, $context);
                
                // Calculate delay before next attempt
                if ($attempt < $this->config['max_attempts']) {
                    $delay = $this->calculateDelay($attempt);
                    $this->sleep($delay);
                }
                
                $attempt++;
            }
        }
        
        // All attempts failed
        throw new MigrationException(
            "Operation failed after {$this->config['max_attempts']} attempts. Last error: " . $lastException->getMessage(),
            $context['migration_id'] ?? 'unknown',
            $context['entity_type'] ?? 'unknown',
            [
                'attempt_history' => $this->attemptHistory,
                'last_exception' => $lastException->getMessage()
            ],
            [
                'manual_retry' => 'Retry operation manually',
                'check_configuration' => 'Review connection and configuration settings',
                'contact_support' => 'Contact support with attempt history'
            ]
        );
    }
    
    protected function shouldRetry(\Exception $exception, int $attempt): bool
    {
        // Don't retry if we've exceeded max attempts
        if ($attempt >= $this->config['max_attempts']) {
            return false;
        }
        
        // Check if exception type is in retry list
        foreach ($this->config['retry_on'] as $retryCondition) {
            if (is_string($retryCondition)) {
                // String matching (for error codes/types)
                if (str_contains(strtolower($exception->getMessage()), strtolower($retryCondition))) {
                    return true;
                }
            } elseif (is_a($exception, $retryCondition)) {
                // Exception class matching
                return true;
            }
        }
        
        return false;
    }
    
    protected function calculateDelay(int $attempt): int
    {
        $delay = match ($this->config['backoff_strategy']) {
            'linear' => $attempt * $this->config['base_delay'],
            'exponential' => pow(2, $attempt - 1) * $this->config['base_delay'],
            'fixed' => $this->config['base_delay'],
            default => $this->config['base_delay']
        };
        
        // Apply maximum delay limit
        $delay = min($delay, $this->config['max_delay']);
        
        // Add jitter to prevent thundering herd
        if ($this->config['jitter']) {
            $jitter = rand(0, (int) ($delay * 0.1)); // Up to 10% jitter
            $delay += $jitter;
        }
        
        return $delay;
    }
    
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
    
    protected function recordAttempt(int $attempt, \Exception $exception, array $context): void
    {
        $this->attemptHistory[] = [
            'attempt' => $attempt,
            'failed_at' => date('Y-m-d H:i:s'),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'context' => $context
        ];
    }
    
    protected function recordSuccess(int $attempt, array $context): void
    {
        $this->attemptHistory[] = [
            'attempt' => $attempt,
            'succeeded_at' => date('Y-m-d H:i:s'),
            'context' => $context
        ];
    }
    
    public function getAttemptHistory(): array
    {
        return $this->attemptHistory;
    }
    
    public function clearHistory(): void
    {
        $this->attemptHistory = [];
    }
}