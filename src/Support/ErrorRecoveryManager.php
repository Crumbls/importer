<?php

namespace Crumbls\Importer\Support;

use Exception;
use Throwable;

class ErrorRecoveryManager
{
    protected array $errorCategories = [];
    protected array $retryStrategies = [];
    protected array $errorLog = [];
    protected int $maxRetries = 3;
    protected array $backoffDelays = [1, 2, 4, 8]; // seconds
    protected array $recoverableErrorTypes = [
        'connection_timeout',
        'temporary_lock',
        'memory_limit',
        'rate_limit',
        'network_error'
    ];
    protected array $fatalErrorTypes = [
        'invalid_data_format',
        'permission_denied',
        'file_not_found',
        'authentication_failed'
    ];
    
    public function __construct(array $config = [])
    {
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->backoffDelays = $config['backoff_delays'] ?? [1, 2, 4, 8];
        $this->recoverableErrorTypes = array_merge($this->recoverableErrorTypes, $config['recoverable_errors'] ?? []);
        $this->fatalErrorTypes = array_merge($this->fatalErrorTypes, $config['fatal_errors'] ?? []);
        
        $this->initializeErrorCategories();
        $this->initializeRetryStrategies();
    }
    
    public function handleError(Throwable $error, array $context = []): array
    {
        $errorInfo = $this->analyzeError($error, $context);
        $this->logError($errorInfo);
        
        $strategy = $this->determineRecoveryStrategy($errorInfo);
        
        return [
            'error_info' => $errorInfo,
            'recovery_strategy' => $strategy,
            'should_retry' => $strategy['action'] === 'retry',
            'should_continue' => $strategy['action'] === 'continue',
            'should_abort' => $strategy['action'] === 'abort',
            'retry_delay' => $strategy['delay'] ?? 0,
            'recommendations' => $strategy['recommendations'] ?? []
        ];
    }
    
    public function executeWithRetry(callable $operation, array $context = [], int $maxAttempts = null): mixed
    {
        $maxAttempts = $maxAttempts ?? $this->maxRetries;
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $maxAttempts) {
            try {
                $result = $operation();
                
                // Log successful retry if this wasn't the first attempt
                if ($attempt > 0) {
                    $this->logSuccessfulRetry($context, $attempt);
                }
                
                return $result;
                
            } catch (Throwable $error) {
                $attempt++;
                $lastError = $error;
                
                $recovery = $this->handleError($error, array_merge($context, ['attempt' => $attempt]));
                
                // If error is fatal or we've exhausted retries, give up
                if (!$recovery['should_retry'] || $attempt >= $maxAttempts) {
                    break;
                }
                
                // Apply backoff delay
                if ($recovery['retry_delay'] > 0) {
                    sleep($recovery['retry_delay']);
                }
                
                $this->logRetryAttempt($context, $attempt, $error, $recovery['retry_delay']);
            }
        }
        
        // All retries exhausted, throw the last error
        throw new Exception(
            "Operation failed after {$attempt} attempts. Last error: " . $lastError->getMessage(),
            $lastError->getCode(),
            $lastError
        );
    }
    
    public function processWithPartialFailures(array $items, callable $processor, array $options = []): array
    {
        $continueOnFailure = $options['continue_on_failure'] ?? true;
        $maxFailures = $options['max_failures'] ?? count($items) * 0.1; // 10% failure threshold
        $batchSize = $options['batch_size'] ?? 50;
        
        $results = [];
        $failures = [];
        $processed = 0;
        $failureCount = 0;
        
        // Process in batches
        $batches = array_chunk($items, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            $batchResults = [];
            $batchFailures = [];
            
            foreach ($batch as $itemIndex => $item) {
                try {
                    $result = $this->executeWithRetry(
                        fn() => $processor($item, $processed),
                        ['item_index' => $processed, 'batch_index' => $batchIndex]
                    );
                    
                    $batchResults[] = [
                        'index' => $processed,
                        'item' => $item,
                        'result' => $result,
                        'status' => 'success'
                    ];
                    
                } catch (Throwable $error) {
                    $failureCount++;
                    
                    $failureInfo = [
                        'index' => $processed,
                        'item' => $item,
                        'error' => $error->getMessage(),
                        'error_type' => $this->categorizeError($error),
                        'status' => 'failed',
                        'recoverable' => $this->isRecoverableError($error)
                    ];
                    
                    $batchFailures[] = $failureInfo;
                    
                    // Check if we should abort due to too many failures
                    if (!$continueOnFailure || $failureCount > $maxFailures) {
                        return [
                            'success' => false,
                            'processed' => $processed,
                            'successful' => array_merge($results, $batchResults),
                            'failed' => array_merge($failures, $batchFailures),
                            'error' => "Too many failures ({$failureCount}). Aborting processing.",
                            'abort_reason' => 'max_failures_exceeded'
                        ];
                    }
                }
                
                $processed++;
            }
            
            $results = array_merge($results, $batchResults);
            $failures = array_merge($failures, $batchFailures);
            
            // Yield control to avoid memory buildup
            if ($batchIndex % 10 === 0) {
                gc_collect_cycles();
            }
        }
        
        return [
            'success' => true,
            'processed' => $processed,
            'successful' => $results,
            'failed' => $failures,
            'success_rate' => $processed > 0 ? (count($results) / $processed) * 100 : 0,
            'failure_rate' => $processed > 0 ? (count($failures) / $processed) * 100 : 0
        ];
    }
    
    public function categorizeError(Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        $code = $error->getCode();
        
        // Check for specific error patterns
        foreach ($this->errorCategories as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($message, $pattern) !== false) {
                    return $category;
                }
            }
        }
        
        // Check by error codes
        $codeCategories = [
            'connection_error' => [2002, 2003, 2006, 2013], // MySQL connection errors
            'permission_error' => [1045, 1142, 1143], // MySQL permission errors
            'memory_error' => [1037, 1038], // MySQL memory errors
        ];
        
        foreach ($codeCategories as $category => $codes) {
            if (in_array($code, $codes)) {
                return $category;
            }
        }
        
        // Fallback classification
        if ($error instanceof \PDOException) {
            return 'database_error';
        } elseif ($error instanceof \InvalidArgumentException) {
            return 'invalid_data';
        } elseif ($error instanceof \RuntimeException) {
            return 'runtime_error';
        }
        
        return 'unknown_error';
    }
    
    public function isRecoverableError(Throwable $error): bool
    {
        $category = $this->categorizeError($error);
        return in_array($category, $this->recoverableErrorTypes);
    }
    
    public function isFatalError(Throwable $error): bool
    {
        $category = $this->categorizeError($error);
        return in_array($category, $this->fatalErrorTypes);
    }
    
    public function getErrorStatistics(): array
    {
        $totalErrors = count($this->errorLog);
        
        if ($totalErrors === 0) {
            return [
                'total_errors' => 0,
                'error_rate' => 0,
                'most_common_errors' => [],
                'recovery_success_rate' => 0
            ];
        }
        
        $categoryCounts = [];
        $recoverableCount = 0;
        $retrySuccessCount = 0;
        
        foreach ($this->errorLog as $errorEntry) {
            $category = $errorEntry['category'];
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            
            if ($errorEntry['recoverable']) {
                $recoverableCount++;
            }
            
            if ($errorEntry['retry_successful'] ?? false) {
                $retrySuccessCount++;
            }
        }
        
        arsort($categoryCounts);
        
        return [
            'total_errors' => $totalErrors,
            'recoverable_errors' => $recoverableCount,
            'fatal_errors' => $totalErrors - $recoverableCount,
            'error_categories' => $categoryCounts,
            'most_common_error' => array_key_first($categoryCounts),
            'recovery_success_rate' => $recoverableCount > 0 ? ($retrySuccessCount / $recoverableCount) * 100 : 0,
            'retry_success_count' => $retrySuccessCount
        ];
    }
    
    public function generateErrorReport(): array
    {
        $stats = $this->getErrorStatistics();
        $recentErrors = array_slice($this->errorLog, -10); // Last 10 errors
        
        return [
            'summary' => $stats,
            'recent_errors' => $recentErrors,
            'recommendations' => $this->generateRecommendations($stats),
            'recovery_strategies' => $this->getAvailableStrategies()
        ];
    }
    
    protected function analyzeError(Throwable $error, array $context): array
    {
        return [
            'timestamp' => time(),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'category' => $this->categorizeError($error),
            'recoverable' => $this->isRecoverableError($error),
            'fatal' => $this->isFatalError($error),
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
    
    protected function determineRecoveryStrategy(array $errorInfo): array
    {
        $category = $errorInfo['category'];
        $attempt = $errorInfo['context']['attempt'] ?? 1;
        
        // Get strategy for this error category
        $strategy = $this->retryStrategies[$category] ?? $this->retryStrategies['default'];
        
        // If error is fatal, don't retry
        if ($errorInfo['fatal']) {
            return [
                'action' => 'abort',
                'reason' => 'fatal_error',
                'recommendations' => ["Fix the underlying issue: {$errorInfo['message']}"]
            ];
        }
        
        // If we've exceeded max retries, abort
        if ($attempt >= $this->maxRetries) {
            return [
                'action' => 'abort',
                'reason' => 'max_retries_exceeded',
                'recommendations' => ['Review error logs and fix underlying issues']
            ];
        }
        
        // Apply strategy with attempt-based modifications
        $delay = $strategy['delay'];
        if ($strategy['backoff'] === 'exponential') {
            $delay = $this->backoffDelays[min($attempt - 1, count($this->backoffDelays) - 1)];
        }
        
        return [
            'action' => 'retry',
            'delay' => $delay,
            'reason' => 'recoverable_error',
            'strategy' => $strategy['name'],
            'recommendations' => $strategy['recommendations'] ?? []
        ];
    }
    
    protected function logError(array $errorInfo): void
    {
        $this->errorLog[] = $errorInfo;
        
        // Keep only last 1000 errors to prevent memory buildup
        if (count($this->errorLog) > 1000) {
            $this->errorLog = array_slice($this->errorLog, -1000);
        }
    }
    
    protected function logRetryAttempt(array $context, int $attempt, Throwable $error, int $delay): void
    {
        // This could be enhanced to log to external systems
        error_log("Migration retry attempt {$attempt}: {$error->getMessage()}, waiting {$delay}s");
    }
    
    protected function logSuccessfulRetry(array $context, int $attempt): void
    {
        // Mark the last error as successfully recovered
        if (!empty($this->errorLog)) {
            $this->errorLog[count($this->errorLog) - 1]['retry_successful'] = true;
            $this->errorLog[count($this->errorLog) - 1]['successful_attempt'] = $attempt;
        }
    }
    
    protected function initializeErrorCategories(): void
    {
        $this->errorCategories = [
            'connection_timeout' => ['connection timed out', 'timeout', 'connection lost'],
            'memory_limit' => ['memory limit', 'out of memory', 'memory exhausted'],
            'rate_limit' => ['rate limit', 'too many requests', 'quota exceeded'],
            'network_error' => ['network error', 'dns lookup failed', 'connection refused'],
            'temporary_lock' => ['lock wait timeout', 'deadlock', 'table locked'],
            'invalid_data_format' => ['invalid format', 'parse error', 'malformed data'],
            'permission_denied' => ['permission denied', 'access denied', 'insufficient privileges'],
            'file_not_found' => ['file not found', 'no such file', 'file does not exist'],
            'authentication_failed' => ['authentication failed', 'invalid credentials', 'access denied']
        ];
    }
    
    protected function initializeRetryStrategies(): void
    {
        $this->retryStrategies = [
            'connection_timeout' => [
                'name' => 'exponential_backoff',
                'delay' => 2,
                'backoff' => 'exponential',
                'recommendations' => ['Check network connectivity', 'Verify database server status']
            ],
            'memory_limit' => [
                'name' => 'reduce_batch_size',
                'delay' => 1,
                'backoff' => 'linear',
                'recommendations' => ['Reduce batch size', 'Free up memory', 'Increase PHP memory limit']
            ],
            'rate_limit' => [
                'name' => 'long_backoff',
                'delay' => 5,
                'backoff' => 'exponential',
                'recommendations' => ['Reduce request rate', 'Implement rate limiting', 'Contact API provider']
            ],
            'temporary_lock' => [
                'name' => 'short_backoff',
                'delay' => 1,
                'backoff' => 'exponential',
                'recommendations' => ['Wait for lock to release', 'Check for long-running queries']
            ],
            'default' => [
                'name' => 'standard_retry',
                'delay' => 2,
                'backoff' => 'exponential',
                'recommendations' => ['Review error details', 'Check system resources']
            ]
        ];
    }
    
    protected function generateRecommendations(array $stats): array
    {
        $recommendations = [];
        
        if ($stats['total_errors'] === 0) {
            return ['No errors detected - migration appears stable'];
        }
        
        // High error rate
        if ($stats['recovery_success_rate'] < 50) {
            $recommendations[] = 'Low recovery success rate - review error handling strategies';
        }
        
        // Most common error type recommendations
        $mostCommon = $stats['most_common_error'] ?? null;
        $errorSpecificRecommendations = [
            'connection_timeout' => 'Check network stability and database server performance',
            'memory_limit' => 'Reduce batch sizes or increase available memory',
            'rate_limit' => 'Implement throttling or request API limit increases',
            'invalid_data' => 'Validate and clean source data before migration'
        ];
        
        if ($mostCommon && isset($errorSpecificRecommendations[$mostCommon])) {
            $recommendations[] = $errorSpecificRecommendations[$mostCommon];
        }
        
        // Fatal errors
        if ($stats['fatal_errors'] > 0) {
            $recommendations[] = 'Fatal errors detected - fix configuration and data issues before continuing';
        }
        
        return $recommendations;
    }
    
    protected function getAvailableStrategies(): array
    {
        return array_keys($this->retryStrategies);
    }
}