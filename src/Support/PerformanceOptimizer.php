<?php

namespace Crumbls\Importer\Support;

class PerformanceOptimizer
{
    protected array $config;
    protected array $performanceMetrics = [];
    protected float $startTime;
    protected array $adaptiveBatchSizes = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'adaptive_batching' => true,
            'auto_gc' => true,
            'gc_probability' => 10, // Percentage chance to run GC
            'optimize_memory_interval' => 100, // Every N operations
            'performance_sampling' => true,
            'target_records_per_second' => 1000,
            'max_batch_size' => 2000,
            'min_batch_size' => 10,
            'batch_adjustment_threshold' => 0.2 // 20% performance difference
        ], $config);
        
        $this->startTime = microtime(true);
    }
    
    public function optimizeBatchSize(string $entityType, int $currentBatchSize, array $performanceData): int
    {
        if (!$this->config['adaptive_batching']) {
            return $currentBatchSize;
        }
        
        $recordsPerSecond = $performanceData['records_per_second'] ?? 0;
        $memoryUsage = $performanceData['memory_usage'] ?? 0;
        $target = $this->config['target_records_per_second'];
        
        // Track performance history for this entity
        if (!isset($this->adaptiveBatchSizes[$entityType])) {
            $this->adaptiveBatchSizes[$entityType] = [
                'current_size' => $currentBatchSize,
                'performance_history' => [],
                'last_adjustment' => 0
            ];
        }
        
        $entityData = &$this->adaptiveBatchSizes[$entityType];
        
        // Record current performance
        $entityData['performance_history'][] = [
            'batch_size' => $currentBatchSize,
            'records_per_second' => $recordsPerSecond,
            'memory_usage' => $memoryUsage,
            'timestamp' => microtime(true)
        ];
        
        // Keep only last 10 measurements
        if (count($entityData['performance_history']) > 10) {
            array_shift($entityData['performance_history']);
        }
        
        // Need at least 2 measurements to optimize
        if (count($entityData['performance_history']) < 2) {
            return $currentBatchSize;
        }
        
        $newBatchSize = $this->calculateOptimalBatchSize($entityType, $target);
        
        // Only adjust if the change is significant
        $changePercentage = abs($newBatchSize - $currentBatchSize) / $currentBatchSize;
        if ($changePercentage > $this->config['batch_adjustment_threshold']) {
            $entityData['current_size'] = $newBatchSize;
            $entityData['last_adjustment'] = microtime(true);
            
            $this->recordMetric('batch_size_optimized', [
                'entity_type' => $entityType,
                'old_size' => $currentBatchSize,
                'new_size' => $newBatchSize,
                'performance_improvement' => $recordsPerSecond
            ]);
            
            return $newBatchSize;
        }
        
        return $currentBatchSize;
    }
    
    public function shouldOptimizeMemory(int $operationCount): bool
    {
        return $operationCount % $this->config['optimize_memory_interval'] === 0;
    }
    
    public function optimizeMemory(): array
    {
        $beforeUsage = memory_get_usage(true);
        $beforePeak = memory_get_peak_usage(true);
        
        // Force garbage collection if configured
        if ($this->config['auto_gc'] && rand(1, 100) <= $this->config['gc_probability']) {
            if (function_exists('gc_collect_cycles')) {
                $cycles = gc_collect_cycles();
            } else {
                $cycles = 0;
            }
        } else {
            $cycles = 0;
        }
        
        // Clear any internal caches
        $this->clearInternalCaches();
        
        $afterUsage = memory_get_usage(true);
        $afterPeak = memory_get_peak_usage(true);
        
        $optimization = [
            'before_usage' => $beforeUsage,
            'after_usage' => $afterUsage,
            'memory_freed' => $beforeUsage - $afterUsage,
            'gc_cycles_collected' => $cycles,
            'peak_memory' => $afterPeak,
            'optimization_time' => microtime(true)
        ];
        
        $this->recordMetric('memory_optimized', $optimization);
        
        return $optimization;
    }
    
    public function getOptimalChunkSizeForEntity(string $entityType, int $recordSize = 1024): int
    {
        // Base chunk size on available memory and record size
        $availableMemory = $this->getAvailableMemory();
        $safeMemoryUsage = (int) ($availableMemory * 0.5); // Use 50% of available memory
        
        $optimalChunkSize = max(10, (int) ($safeMemoryUsage / $recordSize));
        
        // Apply entity-specific optimizations
        $entityOptimizations = [
            'posts' => 0.8, // Posts tend to be larger with content
            'postmeta' => 1.5, // Meta records are smaller
            'users' => 1.0, // Standard size
            'comments' => 1.2, // Comments are medium size
            'attachments' => 0.6 // Attachments metadata can be large
        ];
        
        $multiplier = $entityOptimizations[$entityType] ?? 1.0;
        $adjustedChunkSize = (int) ($optimalChunkSize * $multiplier);
        
        // Enforce limits
        return max(
            $this->config['min_batch_size'],
            min($adjustedChunkSize, $this->config['max_batch_size'])
        );
    }
    
    public function trackPerformance(string $operation, array $stats): void
    {
        if (!$this->config['performance_sampling']) {
            return;
        }
        
        $this->performanceMetrics[] = [
            'operation' => $operation,
            'timestamp' => microtime(true),
            'elapsed_time' => microtime(true) - $this->startTime,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'stats' => $stats
        ];
        
        // Keep only last 1000 metrics to prevent memory bloat
        if (count($this->performanceMetrics) > 1000) {
            array_shift($this->performanceMetrics);
        }
    }
    
    public function getPerformanceReport(): array
    {
        if (empty($this->performanceMetrics)) {
            return [];
        }
        
        $totalTime = microtime(true) - $this->startTime;
        $operationsCount = count($this->performanceMetrics);
        
        // Calculate overall statistics
        $memoryUsages = array_column($this->performanceMetrics, 'memory_usage');
        $peakMemory = max(array_column($this->performanceMetrics, 'memory_peak'));
        
        // Group by operation type
        $operationStats = [];
        foreach ($this->performanceMetrics as $metric) {
            $op = $metric['operation'];
            if (!isset($operationStats[$op])) {
                $operationStats[$op] = [
                    'count' => 0,
                    'total_records' => 0,
                    'avg_memory' => 0,
                    'operations' => []
                ];
            }
            
            $operationStats[$op]['count']++;
            $operationStats[$op]['total_records'] += $metric['stats']['processed'] ?? 0;
            $operationStats[$op]['operations'][] = $metric;
        }
        
        // Calculate averages for each operation
        foreach ($operationStats as $op => &$stats) {
            $memoryUsages = array_column($stats['operations'], 'memory_usage');
            $stats['avg_memory'] = array_sum($memoryUsages) / count($memoryUsages);
            $stats['avg_records_per_operation'] = $stats['total_records'] / $stats['count'];
        }
        
        return [
            'total_duration' => round($totalTime, 2),
            'operations_count' => $operationsCount,
            'operations_per_second' => round($operationsCount / $totalTime, 2),
            'peak_memory_usage' => $this->formatBytes($peakMemory),
            'avg_memory_usage' => $this->formatBytes(array_sum($memoryUsages) / count($memoryUsages)),
            'operation_breakdown' => $operationStats,
            'performance_trends' => $this->calculatePerformanceTrends(),
            'adaptive_batch_optimization' => $this->adaptiveBatchSizes
        ];
    }
    
    public function getBottlenecks(): array
    {
        $bottlenecks = [];
        
        // Analyze memory usage trends
        $memoryTrend = $this->analyzeMemoryTrend();
        if ($memoryTrend['is_concerning']) {
            $bottlenecks[] = [
                'type' => 'memory',
                'severity' => $memoryTrend['severity'],
                'description' => 'Memory usage is increasing rapidly',
                'recommendation' => 'Reduce batch size or increase memory optimization frequency'
            ];
        }
        
        // Analyze processing speed
        $speedAnalysis = $this->analyzeProcessingSpeed();
        if ($speedAnalysis['is_slow']) {
            $bottlenecks[] = [
                'type' => 'performance',
                'severity' => $speedAnalysis['severity'],
                'description' => 'Processing speed is below target',
                'recommendation' => 'Increase batch size or optimize data processing logic'
            ];
        }
        
        // Check for inefficient operations
        $inefficientOps = $this->findInefficientOperations();
        foreach ($inefficientOps as $op) {
            $bottlenecks[] = [
                'type' => 'operation',
                'severity' => 'medium',
                'description' => "Operation '{$op['name']}' is performing poorly",
                'recommendation' => $op['recommendation']
            ];
        }
        
        return $bottlenecks;
    }
    
    public function suggestOptimizations(): array
    {
        $suggestions = [];
        
        $report = $this->getPerformanceReport();
        
        // Memory optimization suggestions
        if (isset($report['peak_memory_usage'])) {
            $suggestions[] = [
                'category' => 'memory',
                'suggestion' => 'Consider increasing memory limit or reducing batch sizes',
                'priority' => 'medium'
            ];
        }
        
        // Batch size suggestions
        foreach ($this->adaptiveBatchSizes as $entityType => $data) {
            if (count($data['performance_history']) >= 3) {
                $trend = $this->calculatePerformanceTrendForEntity($entityType);
                if ($trend['recommendation']) {
                    $suggestions[] = [
                        'category' => 'batch_optimization',
                        'entity_type' => $entityType,
                        'suggestion' => $trend['recommendation'],
                        'priority' => 'high'
                    ];
                }
            }
        }
        
        return $suggestions;
    }
    
    protected function calculateOptimalBatchSize(string $entityType, int $targetRecordsPerSecond): int
    {
        $entityData = $this->adaptiveBatchSizes[$entityType];
        $history = $entityData['performance_history'];
        
        if (count($history) < 2) {
            return $entityData['current_size'];
        }
        
        // Get the two most recent measurements
        $recent = end($history);
        $previous = $history[count($history) - 2];
        
        $currentRPS = $recent['records_per_second'];
        $currentBatchSize = $recent['batch_size'];
        
        // If we're below target, try to increase batch size
        if ($currentRPS < $targetRecordsPerSecond) {
            $increaseFactor = min(1.5, $targetRecordsPerSecond / max($currentRPS, 1));
            $newSize = (int) ($currentBatchSize * $increaseFactor);
        }
        // If we're above target but memory usage is high, reduce batch size
        elseif ($recent['memory_usage'] > $this->getMemoryThreshold()) {
            $newSize = (int) ($currentBatchSize * 0.8);
        }
        // If performance is good, maintain current size
        else {
            $newSize = $currentBatchSize;
        }
        
        // Enforce limits
        return max(
            $this->config['min_batch_size'],
            min($newSize, $this->config['max_batch_size'])
        );
    }
    
    protected function getAvailableMemory(): int
    {
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $currentUsage = memory_get_usage(true);
        return max(0, $memoryLimit - $currentUsage);
    }
    
    protected function getMemoryThreshold(): int
    {
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        return (int) ($memoryLimit * 0.8); // 80% of memory limit
    }
    
    protected function clearInternalCaches(): void
    {
        // Clear older performance metrics
        if (count($this->performanceMetrics) > 500) {
            $this->performanceMetrics = array_slice($this->performanceMetrics, -500);
        }
        
        // Clear older adaptive batch data
        foreach ($this->adaptiveBatchSizes as $entityType => &$data) {
            if (count($data['performance_history']) > 5) {
                $data['performance_history'] = array_slice($data['performance_history'], -5);
            }
        }
    }
    
    protected function calculatePerformanceTrends(): array
    {
        if (count($this->performanceMetrics) < 5) {
            return ['trend' => 'insufficient_data'];
        }
        
        $recent = array_slice($this->performanceMetrics, -5);
        $memoryUsages = array_column($recent, 'memory_usage');
        
        // Calculate memory trend
        $memoryTrend = $this->calculateTrend($memoryUsages);
        
        return [
            'memory_trend' => $memoryTrend,
            'trend_analysis' => $memoryTrend > 0 ? 'increasing' : ($memoryTrend < 0 ? 'decreasing' : 'stable')
        ];
    }
    
    protected function calculateTrend(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0;
        
        $x = range(1, $n);
        $sumX = array_sum($x);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumXX = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $values[$i];
            $sumXX += $x[$i] * $x[$i];
        }
        
        // Linear regression slope
        return ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
    }
    
    protected function analyzeMemoryTrend(): array
    {
        $memoryUsages = array_column($this->performanceMetrics, 'memory_usage');
        if (count($memoryUsages) < 5) {
            return ['is_concerning' => false];
        }
        
        $recent = array_slice($memoryUsages, -5);
        $trend = $this->calculateTrend($recent);
        $memoryLimit = $this->getMemoryThreshold();
        $currentUsage = end($recent);
        
        $isConcerning = $trend > 0 && $currentUsage > ($memoryLimit * 0.7);
        
        return [
            'is_concerning' => $isConcerning,
            'severity' => $currentUsage > ($memoryLimit * 0.9) ? 'high' : 'medium',
            'trend' => $trend,
            'current_usage' => $currentUsage
        ];
    }
    
    protected function analyzeProcessingSpeed(): array
    {
        // Implementation for speed analysis
        return ['is_slow' => false]; // Placeholder
    }
    
    protected function findInefficientOperations(): array
    {
        // Implementation for finding inefficient operations
        return []; // Placeholder
    }
    
    protected function calculatePerformanceTrendForEntity(string $entityType): array
    {
        // Implementation for entity-specific trend analysis
        return ['recommendation' => null]; // Placeholder
    }
    
    protected function recordMetric(string $name, array $data): void
    {
        $this->trackPerformance($name, $data);
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