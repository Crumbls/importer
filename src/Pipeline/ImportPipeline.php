<?php

namespace Crumbls\Importer\Pipeline;

use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Support\DelimiterDetector;
use Crumbls\Importer\Adapters\Traits\HasDataTransformation;
use Crumbls\Importer\Adapters\Traits\HasPerformanceMonitoring;

class ImportPipeline
{
    use HasDataTransformation, HasPerformanceMonitoring;
    protected array $steps = [];
    protected PipelineContext $context;
    protected string $stateHash;
    protected array $stateConfig;
    protected int $currentStepIndex = 0;
    protected array $stepProgress = [];
    protected int $memoryLimit;
    protected int $memoryCheckInterval = 100;
    protected bool $resumedFromState = false;

    public function __construct()
    {
        $this->context = new PipelineContext();
        
        // Check if we're running in Laravel by looking for app instance
        $inLaravel = false;
        try {
            if (function_exists('app') && app()->bound('config')) {
                $inLaravel = true;
            }
        } catch (\Exception $e) {
            $inLaravel = false;
        }
        
        if ($inLaravel) {
            try {
                $this->stateConfig = config('importer.pipeline.state', [
                    'driver' => 'file',
                    'path' => storage_path('pipeline'),
                    'cleanup_after' => 3600,
                ]);
                $memoryLimitStr = config('importer.pipeline.memory_limit', '256M');
            } catch (\Exception $e) {
                $inLaravel = false;
            }
        }
        
        if (!$inLaravel) {
            // Standalone configuration
            $this->stateConfig = [
                'driver' => 'file',
                'path' => sys_get_temp_dir() . '/importer-pipeline',
                'cleanup_after' => 3600,
            ];
            $memoryLimitStr = '256M';
        }
        
        $this->memoryLimit = $this->parseMemoryLimit($memoryLimitStr);
    }

    public function withTempStorage(): self
    {
        $this->context->set('use_temp_storage', true);
        return $this;
    }

    public function process($source, array $options = []): ImportResult
    {
        // Include pipeline configuration in options for hash
        $hashOptions = array_merge($options, [
            'use_temp_storage' => $this->context->get('use_temp_storage', false)
        ]);
        
        $this->stateHash = $this->generateStateHash($source, $hashOptions);
        
        // Check if we have existing valid state
        if ($this->hasValidExistingState($source)) {
            return $this->resumeFromState();
        }
        
        // Fresh start
        return $this->startFresh($source, $options);
    }

    public function setDriverConfig(array $config): self
    {
        $this->context->set('driver_config', $config);
        return $this;
    }

    protected function generateStateHash(string $source, array $options): string
    {
        $sourceData = [
            'source' => realpath($source),
            'source_mtime' => file_exists($source) ? filemtime($source) : null,
            'source_size' => file_exists($source) ? filesize($source) : null,
            'driver' => 'csv',
            'options' => $options,
            'driver_config' => $this->context->get('driver_config', []),
        ];

        return hash('sha256', serialize($sourceData));
    }

    protected function hasValidExistingState(string $source): bool
    {
        if (!$this->stateExists()) {
            return false;
        }

        $state = $this->loadState();
        return $this->isStateValid($state, $source);
    }

    protected function isStateValid(array $state, string $source): bool
    {
        // Check if source file exists and hasn't changed
        if (!file_exists($source)) {
            return false;
        }

        if (isset($state['source_mtime']) && filemtime($source) !== $state['source_mtime']) {
            return false;
        }

        if (isset($state['source_size']) && filesize($source) !== $state['source_size']) {
            return false;
        }

        return true;
    }

    protected function startFresh(string $source, array $options): ImportResult
    {
        $this->context->merge($options);
        $this->context->set('source', $source);
        
        $this->saveState([
            'source' => $source,
            'source_mtime' => file_exists($source) ? filemtime($source) : null,
            'source_size' => file_exists($source) ? filesize($source) : null,
            'options' => $options,
            'status' => 'started',
            'current_step' => 'initial',
            'current_step_index' => 0,
            'step_progress' => [],
            'context' => $this->context->toArray(),
            'started_at' => time()
        ], false); // Don't merge - this is a fresh start

        return $this->executeSteps($source, $options);
    }

    protected function resumeFromState(): ImportResult
    {
        $state = $this->loadState();
        $this->context = PipelineContext::fromArray($state['context'] ?? []);

        $this->currentStepIndex = $state['current_step_index'] ?? 0;
        $this->stepProgress = $state['step_progress'] ?? [];
        $this->resumedFromState = true;
        
        return $this->executeSteps($state['source'], $state['options'] ?? []);
    }

    protected function saveState(array $stateData, bool $merge = true): void
    {
        try {
            if ($merge && $this->stateExists()) {
                $existingState = $this->loadState();
                $stateData = array_merge($existingState, $stateData);
            }
            
            $stateData['updated_at'] = time();
            
            $statePath = $this->getStatePath();
            $jsonData = json_encode($stateData, JSON_PRETTY_PRINT);
            
            if ($jsonData === false) {
                throw new \RuntimeException('Failed to encode state data to JSON');
            }
            
            $result = file_put_contents($statePath, $jsonData, LOCK_EX);
            if ($result === false) {
                throw new \RuntimeException('Failed to write state file: ' . $statePath);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('State save failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function loadState(): array
    {
        try {
            $statePath = $this->getStatePath();
            
            if (!file_exists($statePath)) {
                return [];
            }
            
            if (!is_readable($statePath)) {
                throw new \RuntimeException('State file is not readable: ' . $statePath);
            }

            $content = file_get_contents($statePath);
            if ($content === false) {
                throw new \RuntimeException('Failed to read state file: ' . $statePath);
            }
            
            if (empty($content)) {
                return [];
            }
            
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if ($this->attemptStateRecovery($statePath, $content)) {
                    return $this->loadState();
                }
                throw new \RuntimeException('Invalid JSON in state file: ' . json_last_error_msg());
            }
            
            if (!$this->validateStateStructure($decoded)) {
                if ($this->attemptStateRecovery($statePath, $content)) {
                    return $this->loadState();
                }
                throw new \RuntimeException('Invalid state structure in file: ' . $statePath);
            }
            
            return $decoded ?: [];
        } catch (\Exception $e) {
            throw new \RuntimeException('State load failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function stateExists(): bool
    {
        return file_exists($this->getStatePath());
    }

    protected function markCompleted(): void
    {
        $this->saveState([
            'status' => 'completed',
            'completed_at' => time()
        ]); // Will auto-merge with existing state

        // Schedule cleanup
        $this->scheduleCleanup();
    }

    protected function scheduleCleanup(): void
    {
        $cleanupAfter = $this->stateConfig['cleanup_after'] ?? 3600;
        $cleanupTime = time() + $cleanupAfter;
        
        $this->saveState([
            'cleanup_scheduled_at' => $cleanupTime,
            'cleanup_after' => $cleanupAfter
        ]);
    }

    public function cleanupExpiredStates(): int
    {
        $stateDir = $this->stateConfig['path'];
        $cleaned = 0;
        
        if (!is_dir($stateDir)) {
            return $cleaned;
        }
        
        $files = glob($stateDir . '/*.json');
        $currentTime = time();
        
        foreach ($files as $file) {
            try {
                $content = file_get_contents($file);
                if (!$content) continue;
                
                $state = json_decode($content, true);
                if (!$state) continue;
                
                $cleanupTime = $state['cleanup_scheduled_at'] ?? null;
                if ($cleanupTime && $currentTime >= $cleanupTime) {
                    unlink($file);
                    $cleaned++;
                }
            } catch (\Exception $e) {
                // Log error but continue cleanup
                continue;
            }
        }
        
        return $cleaned;
    }

    protected function getStatePath(): string
    {
        try {
            $stateDir = $this->stateConfig['path'];
            
            if (!is_dir($stateDir)) {
                if (!mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
                    throw new \RuntimeException('Failed to create state directory: ' . $stateDir);
                }
            }
            
            if (!is_writable($stateDir)) {
                throw new \RuntimeException('State directory is not writable: ' . $stateDir);
            }

            return $stateDir . '/' . $this->stateHash . '.json';
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to get state path: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getContext(): PipelineContext
    {
        return $this->context;
    }

    public function getStateHash(): string
    {
        return $this->stateHash;
    }

    protected function executeSteps(string $source, array $options): ImportResult
    {
        $totalSteps = count($this->steps);
        $errors = [];
        $processed = 0;
        $imported = 0;
        $failed = 0;
        
        try {
            for ($i = $this->currentStepIndex; $i < $totalSteps; $i++) {
                $stepName = $this->steps[$i] ?? "step_{$i}";
                $this->currentStepIndex = $i;
                
                $this->saveState([
                    'current_step' => $stepName,
                    'current_step_index' => $i,
                    'status' => 'processing'
                ]);
                
                try {
                    $this->checkMemoryUsage();
                    
                    $stepResult = $this->executeStep($stepName, $source, $options);
                    
                    $this->stepProgress[$stepName] = [
                        'status' => 'completed',
                        'completed_at' => time(),
                        'processed' => $stepResult['processed'] ?? 0,
                        'memory_usage' => memory_get_usage(true),
                        'memory_peak' => memory_get_peak_usage(true)
                    ];
                    
                    $processed += $stepResult['processed'] ?? 0;
                    $imported += $stepResult['imported'] ?? 0;
                    $failed += $stepResult['failed'] ?? 0;
                    
                    if (!empty($stepResult['errors'])) {
                        $errors = array_merge($errors, $stepResult['errors']);
                    }
                    
                } catch (\Exception $e) {
                    $this->stepProgress[$stepName] = [
                        'status' => 'failed',
                        'failed_at' => time(),
                        'error' => $e->getMessage(),
                        'memory_usage' => memory_get_usage(true)
                    ];
                    
                    $errors[] = "Step '{$stepName}' failed: " . $e->getMessage();
                    
                    $this->saveState([
                        'status' => 'failed',
                        'step_progress' => $this->stepProgress,
                        'errors' => $errors
                    ]);
                    
                    throw $e;
                }
                
                $this->saveState([
                    'step_progress' => $this->stepProgress
                ]);
            }
            
            $result = new ImportResult(
                success: empty($errors),
                processed: $processed,
                imported: $imported,
                failed: $failed,
                errors: $errors,
                meta: [
                    'source' => $source,
                    'state_hash' => $this->stateHash,
                    'resumed' => $this->resumedFromState,
                    'total_steps' => $totalSteps,
                    'completed_steps' => count($this->stepProgress),
                    'step_progress' => $this->stepProgress
                ]
            );
            
            $this->markCompleted();
            return $result;
            
        } catch (\Exception $e) {
            return new ImportResult(
                success: false,
                processed: $processed,
                imported: $imported,
                failed: $failed,
                errors: array_merge($errors, [$e->getMessage()]),
                meta: [
                    'source' => $source,
                    'state_hash' => $this->stateHash,
                    'resumed' => $this->resumedFromState,
                    'failed_at_step' => $this->currentStepIndex,
                    'step_progress' => $this->stepProgress
                ]
            );
        }
    }
    
    protected function executeStep(string $stepName, string $source, array $options): array
    {
        $driverConfig = $this->context->get('driver_config', []);
        
        return match ($stepName) {
            'validate' => $this->executeValidationStep($source, $options, $driverConfig),
            'detect_delimiter' => $this->executeDelimiterDetectionStep($source, $options, $driverConfig),
            'parse_headers' => $this->executeParseHeadersStep($source, $options, $driverConfig),
            'create_storage' => $this->executeCreateStorageStep($source, $options, $driverConfig),
            'process_rows' => $this->executeProcessRowsStep($source, $options, $driverConfig),
            'parse_xml_structure' => $this->executeParseXmlStructureStep($source, $options, $driverConfig),
            'extract_entities' => $this->executeExtractEntitiesStep($source, $options, $driverConfig),
            'extract_users' => $this->executeExtractUsersStep($source, $options, $driverConfig),
            'extract_posts' => $this->executeExtractPostsStep($source, $options, $driverConfig),
            'extract_comments' => $this->executeExtractCommentsStep($source, $options, $driverConfig),
            'extract_categories' => $this->executeExtractCategoriesStep($source, $options, $driverConfig),
            default => [
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'errors' => []
            ]
        };
    }
    
    public function addStep(string $stepName): self
    {
        $this->steps[] = $stepName;
        return $this;
    }
    
    public function getSteps(): array
    {
        return $this->steps;
    }
    
    public function getCurrentStepIndex(): int
    {
        return $this->currentStepIndex;
    }
    
    public function getStepProgress(): array
    {
        return $this->stepProgress;
    }
    
    public function pause(): void
    {
        $this->saveState([
            'status' => 'paused',
            'paused_at' => time()
        ]);
    }
    
    public function resume(): self
    {
        $this->saveState([
            'status' => 'processing',
            'resumed_at' => time()
        ]);
        
        return $this;
    }
    
    public function getProgress(): array
    {
        $totalSteps = count($this->steps);
        $completedSteps = count(array_filter($this->stepProgress, fn($step) => $step['status'] === 'completed'));
        
        return [
            'total_steps' => $totalSteps,
            'completed_steps' => $completedSteps,
            'current_step_index' => $this->currentStepIndex,
            'percentage' => $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100, 2) : 0,
            'step_details' => $this->stepProgress,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }
    
    public function isPaused(): bool
    {
        if (!$this->stateExists()) {
            return false;
        }
        
        $state = $this->loadState();
        return ($state['status'] ?? '') === 'paused';
    }
    
    public function isCompleted(): bool
    {
        if (!$this->stateExists()) {
            return false;
        }
        
        $state = $this->loadState();
        return ($state['status'] ?? '') === 'completed';
    }
    
    public function isFailed(): bool
    {
        if (!$this->stateExists()) {
            return false;
        }
        
        $state = $this->loadState();
        return ($state['status'] ?? '') === 'failed';
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
    
    protected function checkMemoryUsage(): void
    {
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);
        
        if ($currentUsage > $this->memoryLimit) {
            throw new \RuntimeException(
                "Memory limit exceeded: Current usage {$this->formatBytes($currentUsage)} exceeds limit {$this->formatBytes($this->memoryLimit)}"
            );
        }
        
        if ($peakUsage > ($this->memoryLimit * 0.9)) {
            $this->saveState([
                'memory_warning' => true,
                'memory_usage' => $currentUsage,
                'memory_peak' => $peakUsage,
                'memory_limit' => $this->memoryLimit,
                'warning_at' => time()
            ]);
        }
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
    
    public function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'current_formatted' => $this->formatBytes(memory_get_usage(true)),
            'peak' => memory_get_peak_usage(true),
            'peak_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
            'limit' => $this->memoryLimit,
            'limit_formatted' => $this->formatBytes($this->memoryLimit),
            'percentage' => round((memory_get_usage(true) / $this->memoryLimit) * 100, 2)
        ];
    }
    
    public function setMemoryLimit(string $limit): self
    {
        $this->memoryLimit = $this->parseMemoryLimit($limit);
        return $this;
    }
    
    protected function validateStateStructure(array $state): bool
    {
        $requiredKeys = ['status', 'updated_at'];
        
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $state)) {
                return false;
            }
        }
        
        $validStatuses = ['started', 'processing', 'paused', 'completed', 'failed'];
        if (!in_array($state['status'], $validStatuses)) {
            return false;
        }
        
        if (!is_numeric($state['updated_at'])) {
            return false;
        }
        
        return true;
    }
    
    protected function attemptStateRecovery(string $statePath, string $content): bool
    {
        $backupPath = $statePath . '.backup.' . time();
        
        try {
            file_put_contents($backupPath, $content);
            
            $recoveredState = $this->createMinimalState();
            $jsonData = json_encode($recoveredState, JSON_PRETTY_PRINT);
            
            if ($jsonData === false) {
                return false;
            }
            
            $result = file_put_contents($statePath, $jsonData, LOCK_EX);
            return $result !== false;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    protected function createMinimalState(): array
    {
        return [
            'status' => 'started',
            'current_step' => 'recovery',
            'current_step_index' => 0,
            'step_progress' => [],
            'context' => [],
            'started_at' => time(),
            'updated_at' => time(),
            'recovered' => true,
            'recovery_at' => time()
        ];
    }
    
    public function recoverFromCorruption(): bool
    {
        try {
            if (!$this->stateExists()) {
                return false;
            }
            
            $statePath = $this->getStatePath();
            $content = file_get_contents($statePath);
            
            if ($content === false) {
                return false;
            }
            
            return $this->attemptStateRecovery($statePath, $content);
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    protected function executeValidationStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        
        if (!file_exists($source)) {
            $errors[] = 'Source file does not exist: ' . $source;
        } elseif (!is_readable($source)) {
            $errors[] = 'Source file is not readable: ' . $source;
        } elseif (filesize($source) === 0) {
            $errors[] = 'Source file is empty: ' . $source;
        }
        
        return [
            'processed' => 0, // Don't count validation as processed rows
            'imported' => 0,
            'failed' => count($errors) > 0 ? 1 : 0,
            'errors' => $errors
        ];
    }
    
    protected function executeDelimiterDetectionStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $delimiter = $driverConfig['delimiter'] ?? null;
        
        if (!$delimiter || ($driverConfig['auto_detect_delimiter'] ?? false)) {
            $delimiter = $this->detectDelimiter($source);
            
            if (!$delimiter) {
                $errors[] = 'Could not detect CSV delimiter';
                $delimiter = ',';
            }
            
            $this->context->set('detected_delimiter', $delimiter);
        }
        
        $this->context->set('delimiter', $delimiter);
        
        return [
            'processed' => 0, // Don't count delimiter detection as processed rows
            'imported' => 0,
            'failed' => count($errors),
            'errors' => $errors
        ];
    }
    
    protected function executeParseHeadersStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $hasHeaders = $driverConfig['has_headers'] ?? true;
        
        if (!$hasHeaders) {
            return [
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        try {
            $delimiter = $this->context->get('delimiter', ',');
            $enclosure = $driverConfig['enclosure'] ?? '"';
            $escape = $driverConfig['escape'] ?? '\\';
            
            $handle = fopen($source, 'r');
            if (!$handle) {
                $errors[] = 'Could not open file for reading';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            $headers = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
            fclose($handle);
            
            if ($headers === false) {
                $errors[] = 'Could not parse headers from CSV file';
            } else {
                // Store raw headers for reference
                $this->context->set('raw_headers', $headers);
                
                // Process headers using driver-specific logic
                $processedHeaders = $this->processHeaders($headers, $driverConfig);
                
                $this->context->set('headers', $processedHeaders);
                $this->context->set('header_count', count($processedHeaders));
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error parsing headers: ' . $e->getMessage();
        }
        
        return [
            'processed' => 0, // Don't count header parsing as processed rows
            'imported' => 0,
            'failed' => count($errors),
            'errors' => $errors
        ];
    }
    
    protected function executeCreateStorageStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        
        try {
            $storageDriver = $driverConfig['storage_driver'] ?? 'memory';
            $storageConfig = $driverConfig['storage_config'] ?? [];
            
            $storageManager = new \Crumbls\Importer\Storage\TemporaryStorageManager([
                'driver' => $storageDriver,
                'sqlite' => $storageConfig
            ]);
            
            $storage = $storageManager->driver($storageDriver);
            
            // Handle multi-table storage (XML drivers)
            if ($storageDriver === 'multi_table_sqlite') {
                $schema = $driverConfig['schema'] ?? null;
                if ($schema) {
                    // Use schema-defined table structures
                    $tableSchemas = $schema->getTableSchemas();
                    $storage->createMultipleTables($tableSchemas);
                } else {
                    // Fallback to hardcoded WPXML schemas for backward compatibility
                    $this->createWpxmlTables($storage, $driverConfig);
                }
            } else {
                // Regular single-table storage (CSV)
                $headers = $this->context->get('headers', []);
                if (empty($headers)) {
                    $errors[] = 'No headers available for storage creation';
                } else {
                    $storage->create($headers);
                }
            }
            
            $this->context->set('temporary_storage', $storage);
            $this->context->set('storage_manager', $storageManager);
            
        } catch (\Exception $e) {
            $errors[] = 'Failed to create temporary storage: ' . $e->getMessage();
        }
        
        return [
            'processed' => 0, // Don't count storage creation as processed rows
            'imported' => 0,
            'failed' => count($errors),
            'errors' => $errors
        ];
    }
    
    protected function executeProcessRowsStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $processed = 0;
        $imported = 0;
        $failed = 0;
        
        try {
            $storage = $this->context->get('temporary_storage');
            if (!$storage) {
                $errors[] = 'No temporary storage available';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            $delimiter = $this->context->get('delimiter', ',');
            $enclosure = $driverConfig['enclosure'] ?? '"';
            $escape = $driverConfig['escape'] ?? '\\';
            $hasHeaders = $driverConfig['has_headers'] ?? true;
            $chunkSize = $driverConfig['chunk_size'] ?? 1000;
            $validationRules = $driverConfig['validation_rules'] ?? [];
            $skipInvalidRows = $driverConfig['skip_invalid_rows'] ?? false;
            $maxErrors = $driverConfig['max_errors'] ?? 1000;
            
            $lastProcessedLine = $this->context->get('last_processed_line', 0);
            
            $parser = new \Crumbls\Importer\Parser\StreamingCsvParser(
                $source,
                $delimiter,
                $enclosure,
                $escape,
                $hasHeaders
            );
            
            $parser->setValidationRules($this->convertValidationRules($validationRules, $parser->getHeaders()))
                   ->skipInvalidRows($skipInvalidRows)
                   ->setMaxErrors($maxErrors);
            
            if ($lastProcessedLine > 0) {
                $parser->seekToLine($lastProcessedLine + 1);
            }
            
            $rateLimiter = $driverConfig['rate_limiter'] ?? null;
            $maxRowsPerSecond = $driverConfig['max_rows_per_second'] ?? 0;
            $maxChunksPerMinute = $driverConfig['max_chunks_per_minute'] ?? 0;
            
            $stats = $parser->chunk($chunkSize, function($chunk, $progress) use ($storage, $rateLimiter, $maxRowsPerSecond, $maxChunksPerMinute, $chunkSize) {
                // Rate limiting for chunks
                if ($rateLimiter && $maxChunksPerMinute > 0) {
                    $rateLimiter->wait('chunks', 1);
                }
                
                // Rate limiting for rows
                if ($rateLimiter && $maxRowsPerSecond > 0) {
                    $rateLimiter->wait('rows', count($chunk));
                }
                
                $this->saveState([
                    'last_processed_line' => $progress['current_line'],
                    'bytes_processed' => $progress['bytes_read'],
                    'processing_progress' => $progress['percentage'],
                    'rate_limiter_stats' => $rateLimiter?->getStats()
                ]);
                
                $this->checkMemoryUsage();
                
                $inserted = $storage->insertBatch($chunk);
                
                return [
                    'imported' => $inserted,
                    'failed' => count($chunk) - $inserted,
                    'errors' => []
                ];
            }, function($progress, $stats) use ($rateLimiter) {
                $this->saveState([
                    'current_progress' => $progress,
                    'processing_stats' => $stats,
                    'rate_limiter_stats' => $rateLimiter?->getStats()
                ]);
            });
            
            $processed = $stats['processed'];
            $imported = $stats['imported'];
            $failed = $stats['failed'];
            $errors = array_merge($errors, $stats['errors']);
            
            $parserErrors = $parser->getErrors();
            foreach ($parserErrors as $error) {
                $errors[] = "Line {$error['line']}: {$error['message']}";
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error processing rows: ' . $e->getMessage();
            $failed++;
        }
        
        return [
            'processed' => $processed,
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors
        ];
    }
    
    protected function convertValidationRules(array $rules, array $headers): array
    {
        $convertedRules = [];
        
        foreach ($rules as $column => $columnRules) {
            $columnIndex = array_search($column, $headers);
            if ($columnIndex !== false) {
                $convertedRules[$columnIndex] = $columnRules;
            }
        }
        
        return $convertedRules;
    }
    
    protected function detectDelimiter(string $source, int $sampleSize = 1024): ?string
    {
        return DelimiterDetector::detect($source);
    }
    
    protected function processHeaders(array $rawHeaders, array $driverConfig): array
    {
        // If user explicitly defined columns, use those
        $userColumns = $driverConfig['columns'] ?? null;
        if ($userColumns !== null) {
            return $userColumns;
        }
        
        $columnMapping = $driverConfig['column_mapping'] ?? [];
        $cleanColumnNames = $driverConfig['clean_column_names'] ?? true;
        
        $processedHeaders = [];
        
        foreach ($rawHeaders as $index => $header) {
            $cleanHeader = $header;
            
            // Apply column mapping first
            if (isset($columnMapping[$header])) {
                $cleanHeader = $columnMapping[$header];
            } 
            // Auto-clean if enabled and no explicit mapping
            elseif ($cleanColumnNames) {
                $cleanHeader = $this->cleanColumnName($header);
            }
            
            $processedHeaders[$index] = $cleanHeader;
        }
        
        return $processedHeaders;
    }
    
    
    protected function executeParseXmlStructureStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        
        try {
            $xml = simplexml_load_file($source);
            if (!$xml) {
                $errors[] = 'Failed to parse XML file';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            // Register WordPress namespaces
            $xml->registerXPathNamespace('wp', 'https://wordpress.org/export/1.2/');
            $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
            $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            
            // Store XML structure in context
            $this->context->set('xml_document', $xml);
            $this->context->set('site_url', (string) $xml->channel->children('wp', true)->base_site_url);
            $this->context->set('export_version', (string) $xml->channel->children('wp', true)->wxr_version);
            
            // Count items for progress tracking
            $items = $xml->xpath('//item');
            $this->context->set('total_items', count($items));
            
        } catch (\Exception $e) {
            $errors[] = 'Error parsing XML structure: ' . $e->getMessage();
        }
        
        return [
            'processed' => 0,
            'imported' => 0,
            'failed' => count($errors),
            'errors' => $errors
        ];
    }
    
    protected function executeExtractEntitiesStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $totalProcessed = 0;
        $totalImported = 0;
        $totalFailed = 0;
        
        try {
            $schema = $driverConfig['schema'] ?? null;
            $enabledEntities = $driverConfig['enabled_entities'] ?? [];
            $storage = $this->context->get('temporary_storage');
            
            if (!$schema || !$storage) {
                $errors[] = 'XML schema or storage not available';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            // Create parser
            $parser = \Crumbls\Importer\Xml\XmlParser::fromFile($source);
            $parser->registerNamespaces($schema->getNamespaces());
            
            // Get enabled entities from schema
            $entities = $schema->getEnabledEntities($enabledEntities);
            
            foreach ($entities as $entityName => $config) {
                if (!($enabledEntities[$entityName] ?? true)) {
                    continue;
                }
                
                $processed = 0;
                $imported = 0;
                $tableName = $config['table'] ?? $entityName;
                $chunkSize = $driverConfig['chunk_size'] ?? 100;
                
                // Extract records for this entity
                $batch = [];
                foreach ($parser->extractRecords($config['xpath'], $config['fields']) as $record) {
                    // Process field values through schema
                    $processedRecord = [];
                    foreach ($record as $fieldName => $rawValue) {
                        $processedRecord[$fieldName] = $schema->processFieldValue($fieldName, $rawValue, $record);
                    }
                    
                    $batch[] = $processedRecord;
                    $processed++;
                    
                    // Insert batch when it reaches chunk size
                    if (count($batch) >= $chunkSize) {
                        $imported += $storage->insertBatch($batch, $tableName);
                        $batch = [];
                    }
                }
                
                // Insert remaining records in batch
                if (!empty($batch)) {
                    $imported += $storage->insertBatch($batch, $tableName);
                }
                
                $totalProcessed += $processed;
                $totalImported += $imported;
                $totalFailed += ($processed - $imported);
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error extracting entities: ' . $e->getMessage();
            $totalFailed++;
        }
        
        return [
            'processed' => $totalProcessed,
            'imported' => $totalImported,
            'failed' => $totalFailed,
            'errors' => $errors
        ];
    }
    
    protected function executeExtractUsersStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $processed = 0;
        $imported = 0;
        
        if (!($driverConfig['extract_users'] ?? true)) {
            return [
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        try {
            $xml = $this->context->get('xml_document');
            $storage = $this->context->get('temporary_storage');
            
            // If XML document is not in context (from resume), reload it
            if (!$xml) {
                $xml = simplexml_load_file($source);
                if ($xml) {
                    $xml->registerXPathNamespace('wp', 'https://wordpress.org/export/1.2/');
                    $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
                    $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                    $this->context->set('xml_document', $xml);
                }
            }
            
            if (!$xml || !$storage) {
                $errors[] = 'XML document or storage not available';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            // Extract unique users from dc:creator elements
            $creators = $xml->xpath('//dc:creator');
            $uniqueUsers = [];
            
            foreach ($creators as $creator) {
                $username = (string) $creator;
                if (!empty($username) && !isset($uniqueUsers[$username])) {
                    $uniqueUsers[$username] = [
                        'username' => $username,
                        'display_name' => $username, // WordPress XML doesn't usually have display names
                        'email' => '', // Not available in this format
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $processed++;
                }
            }
            
            // Insert users in batches
            $userBatches = array_chunk($uniqueUsers, $driverConfig['chunk_size'] ?? 100);
            foreach ($userBatches as $batch) {
                $imported += $storage->insertBatch(array_values($batch), 'users');
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error extracting users: ' . $e->getMessage();
        }
        
        return [
            'processed' => $processed,
            'imported' => $imported,
            'failed' => $processed - $imported,
            'errors' => $errors
        ];
    }
    
    protected function executeExtractPostsStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $processed = 0;
        $imported = 0;
        
        if (!($driverConfig['extract_posts'] ?? true)) {
            return [
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        try {
            $xml = $this->context->get('xml_document');
            $storage = $this->context->get('temporary_storage');
            
            if (!$xml || !$storage) {
                $errors[] = 'XML document or storage not available';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            $items = $xml->xpath('//item');
            $posts = [];
            
            foreach ($items as $item) {
                $wpChildren = $item->children('wp', true);
                $dcChildren = $item->children('dc', true);
                $contentChildren = $item->children('content', true);
                
                $posts[] = [
                    'title' => (string) $item->title,
                    'content' => (string) $contentChildren->encoded,
                    'excerpt' => (string) $item->description,
                    'post_type' => (string) $wpChildren->post_type,
                    'status' => (string) $wpChildren->status,
                    'post_date' => (string) $wpChildren->post_date,
                    'author' => (string) $dcChildren->creator,
                    'link' => (string) $item->link,
                    'guid' => (string) $item->guid,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $processed++;
                
                // Process in batches
                if (count($posts) >= ($driverConfig['chunk_size'] ?? 100)) {
                    $imported += $storage->insertBatch($posts, 'posts');
                    $posts = [];
                }
            }
            
            // Insert remaining posts
            if (!empty($posts)) {
                $imported += $storage->insertBatch($posts, 'posts');
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error extracting posts: ' . $e->getMessage();
        }
        
        return [
            'processed' => $processed,
            'imported' => $imported,
            'failed' => $processed - $imported,
            'errors' => $errors
        ];
    }
    
    protected function executeExtractCommentsStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $processed = 0;
        $imported = 0;
        
        if (!($driverConfig['extract_comments'] ?? true)) {
            return [
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        try {
            $xml = $this->context->get('xml_document');
            $storage = $this->context->get('temporary_storage');
            
            if (!$xml || !$storage) {
                $errors[] = 'XML document or storage not available';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            $comments = $xml->xpath('//wp:comment');
            $commentData = [];
            
            foreach ($comments as $comment) {
                $wpChildren = $comment->children('wp', true);
                
                $commentData[] = [
                    'comment_id' => (string) $wpChildren->comment_id,
                    'author' => (string) $wpChildren->comment_author,
                    'author_email' => (string) $wpChildren->comment_author_email,
                    'author_url' => (string) $wpChildren->comment_author_url,
                    'content' => (string) $wpChildren->comment_content,
                    'approved' => (string) $wpChildren->comment_approved,
                    'comment_type' => (string) $wpChildren->comment_type,
                    'parent_id' => (string) $wpChildren->comment_parent,
                    'user_id' => (string) $wpChildren->comment_user_id,
                    'comment_date' => (string) $wpChildren->comment_date,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $processed++;
                
                // Process in batches
                if (count($commentData) >= ($driverConfig['chunk_size'] ?? 100)) {
                    $imported += $storage->insertBatch($commentData, 'comments');
                    $commentData = [];
                }
            }
            
            // Insert remaining comments
            if (!empty($commentData)) {
                $imported += $storage->insertBatch($commentData, 'comments');
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error extracting comments: ' . $e->getMessage();
        }
        
        return [
            'processed' => $processed,
            'imported' => $imported,
            'failed' => $processed - $imported,
            'errors' => $errors
        ];
    }
    
    protected function executeExtractCategoriesStep(string $source, array $options, array $driverConfig): array
    {
        $errors = [];
        $processed = 0;
        $imported = 0;
        
        if (!($driverConfig['extract_categories'] ?? true)) {
            return [
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        try {
            $xml = $this->context->get('xml_document');
            $storage = $this->context->get('temporary_storage');
            
            if (!$xml || !$storage) {
                $errors[] = 'XML document or storage not available';
                return [
                    'processed' => 0,
                    'imported' => 0,
                    'failed' => 1,
                    'errors' => $errors
                ];
            }
            
            // Extract unique categories
            $categories = $xml->xpath('//category');
            $uniqueCategories = [];
            
            foreach ($categories as $category) {
                $categoryName = (string) $category;
                if (!empty($categoryName) && !isset($uniqueCategories[$categoryName])) {
                    $uniqueCategories[$categoryName] = [
                        'name' => $categoryName,
                        'slug' => \Illuminate\Support\Str::slug($categoryName),
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $processed++;
                }
            }
            
            // Insert categories in batches
            $categoryBatches = array_chunk($uniqueCategories, $driverConfig['chunk_size'] ?? 100);
            foreach ($categoryBatches as $batch) {
                $imported += $storage->insertBatch(array_values($batch), 'categories');
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Error extracting categories: ' . $e->getMessage();
        }
        
        return [
            'processed' => $processed,
            'imported' => $imported,
            'failed' => $processed - $imported,
            'errors' => $errors
        ];
    }
    
    protected function createWpxmlTables($storage, array $driverConfig): void
    {
        $schemas = [
            'users' => [
                'username',
                'display_name', 
                'email',
                'created_at'
            ],
            'posts' => [
                'title',
                'content',
                'excerpt',
                'post_type',
                'status',
                'post_date',
                'author',
                'link',
                'guid',
                'created_at'
            ],
            'comments' => [
                'comment_id',
                'author',
                'author_email',
                'author_url',
                'content',
                'approved',
                'comment_type',
                'parent_id',
                'user_id',
                'comment_date',
                'created_at'
            ],
            'categories' => [
                'name',
                'slug',
                'created_at'
            ]
        ];
        
        // Only create tables for enabled extractions
        $enabledSchemas = [];
        
        if ($driverConfig['extract_users'] ?? true) {
            $enabledSchemas['users'] = $schemas['users'];
        }
        
        if ($driverConfig['extract_posts'] ?? true) {
            $enabledSchemas['posts'] = $schemas['posts'];
        }
        
        if ($driverConfig['extract_comments'] ?? true) {
            $enabledSchemas['comments'] = $schemas['comments'];
        }
        
        if ($driverConfig['extract_categories'] ?? true) {
            $enabledSchemas['categories'] = $schemas['categories'];
        }
        
        $storage->createMultipleTables($enabledSchemas);
    }
}
