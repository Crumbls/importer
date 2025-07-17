<?php

namespace Crumbls\Importer\Support;

use Illuminate\Support\Facades\Log;

class MemoryManager
{
    protected int $memoryLimit;
    protected int $startMemory;
    protected int $peakMemory = 0;
    protected array $memoryHistory = [];
    protected array $callbacks = [];
    
    // Threshold levels (as percentage of memory limit)
    protected float $warningThreshold;
    protected float $criticalThreshold;
    protected float $emergencyThreshold;
    
    // Optimization settings
    protected int $originalBatchSize;
    protected int $currentBatchSize;
    protected int $minBatchSize;
    protected int $maxBatchSize;
    
    // Monitoring
    protected float $lastCleanupTime = 0;
    protected int $cleanupCount = 0;
    protected int $batchAdjustmentCount = 0;
    
    public function __construct(
        int $memoryLimit,
        int $initialBatchSize,
        array $config = []
    ) {
        $this->memoryLimit = $memoryLimit;
        $this->startMemory = memory_get_usage(true);
        $this->originalBatchSize = $initialBatchSize;
        $this->currentBatchSize = $initialBatchSize;
        
        // Configure thresholds
        $this->warningThreshold = $config['warning_threshold'] ?? 0.7;  // 70%
        $this->criticalThreshold = $config['critical_threshold'] ?? 0.85; // 85%
        $this->emergencyThreshold = $config['emergency_threshold'] ?? 0.95; // 95%
        
        // Configure batch size limits
        $this->minBatchSize = $config['min_batch_size'] ?? max(1, intval($initialBatchSize * 0.1));
        $this->maxBatchSize = $config['max_batch_size'] ?? intval($initialBatchSize * 2);
        
        Log::info('MemoryManager initialized', [
            'memory_limit' => $this->formatBytes($memoryLimit),
            'start_memory' => $this->formatBytes($this->startMemory),
            'thresholds' => [
                'warning' => ($this->warningThreshold * 100) . '%',
                'critical' => ($this->criticalThreshold * 100) . '%',
                'emergency' => ($this->emergencyThreshold * 100) . '%',
            ],
            'batch_size_range' => "{$this->minBatchSize}-{$this->maxBatchSize}",
        ]);
    }
    
    public function addCallback(string $event, callable $callback): self
    {
        if (!isset($this->callbacks[$event])) {
            $this->callbacks[$event] = [];
        }
        $this->callbacks[$event][] = $callback;
        return $this;
    }
    
    public function monitor(): array
    {
        $currentMemory = memory_get_usage(true);
        $this->peakMemory = max($this->peakMemory, $currentMemory);
        
        // Record memory history for trend analysis
        $this->recordMemoryUsage($currentMemory);
        
        // Calculate usage ratio
        $usageRatio = $currentMemory / $this->memoryLimit;
        
        // Determine memory pressure level
        $pressure = $this->assessMemoryPressure($usageRatio);
        
        // Take appropriate action based on pressure
        $actions = $this->handleMemoryPressure($pressure, $currentMemory);
        
        return [
            'current_memory' => $currentMemory,
            'memory_limit' => $this->memoryLimit,
            'usage_ratio' => $usageRatio,
            'usage_percentage' => round($usageRatio * 100, 1),
            'pressure_level' => $pressure,
            'peak_memory' => $this->peakMemory,
            'memory_growth' => $currentMemory - $this->startMemory,
            'current_batch_size' => $this->currentBatchSize,
            'actions_taken' => $actions,
            'trend_analysis' => $this->analyzeTrend(),
        ];
    }
    
    protected function recordMemoryUsage(int $currentMemory): void
    {
        $timestamp = microtime(true);
        
        $this->memoryHistory[] = [
            'timestamp' => $timestamp,
            'memory' => $currentMemory,
            'batch_size' => $this->currentBatchSize,
        ];
        
        // Keep only last 20 measurements to prevent memory growth
        if (count($this->memoryHistory) > 20) {
            $this->memoryHistory = array_slice($this->memoryHistory, -20);
        }
    }
    
    protected function assessMemoryPressure(float $usageRatio): string
    {
        if ($usageRatio >= $this->emergencyThreshold) {
            return 'emergency';
        } elseif ($usageRatio >= $this->criticalThreshold) {
            return 'critical';
        } elseif ($usageRatio >= $this->warningThreshold) {
            return 'warning';
        } else {
            return 'normal';
        }
    }
    
    protected function handleMemoryPressure(string $pressure, int $currentMemory): array
    {
        $actions = [];
        
        switch ($pressure) {
            case 'emergency':
                $actions = array_merge($actions, $this->handleEmergencyPressure($currentMemory));
                break;
                
            case 'critical':
                $actions = array_merge($actions, $this->handleCriticalPressure($currentMemory));
                break;
                
            case 'warning':
                $actions = array_merge($actions, $this->handleWarningPressure($currentMemory));
                break;
                
            case 'normal':
                $actions = array_merge($actions, $this->handleNormalPressure());
                break;
        }
        
        return $actions;
    }
    
    protected function handleEmergencyPressure(int $currentMemory): array
    {
        $actions = [];
        
        // Aggressive batch size reduction
        $newBatchSize = max($this->minBatchSize, intval($this->currentBatchSize * 0.5));
        if ($newBatchSize !== $this->currentBatchSize) {
            $this->currentBatchSize = $newBatchSize;
            $this->batchAdjustmentCount++;
            $actions[] = "reduced_batch_size_to_{$newBatchSize}";
        }
        
        // Force garbage collection
        $memoryBefore = memory_get_usage(true);
        gc_collect_cycles();
        $memoryAfter = memory_get_usage(true);
        $cleaned = $memoryBefore - $memoryAfter;
        
        $this->cleanupCount++;
        $this->lastCleanupTime = microtime(true);
        $actions[] = "forced_gc_cleaned_" . $this->formatBytes($cleaned);
        
        // Execute emergency callbacks
        $this->executeCallbacks('emergency', [
            'memory_usage' => $currentMemory,
            'memory_limit' => $this->memoryLimit,
            'cleaned_memory' => $cleaned,
        ]);
        
        // Log emergency situation
        Log::error('Emergency memory pressure detected', [
            'current_memory' => $this->formatBytes($currentMemory),
            'memory_limit' => $this->formatBytes($this->memoryLimit),
            'usage_percentage' => round(($currentMemory / $this->memoryLimit) * 100, 1) . '%',
            'new_batch_size' => $newBatchSize,
            'memory_cleaned' => $this->formatBytes($cleaned),
        ]);
        
        return $actions;
    }
    
    protected function handleCriticalPressure(int $currentMemory): array
    {
        $actions = [];
        
        // Moderate batch size reduction
        $newBatchSize = max($this->minBatchSize, intval($this->currentBatchSize * 0.7));
        if ($newBatchSize !== $this->currentBatchSize) {
            $this->currentBatchSize = $newBatchSize;
            $this->batchAdjustmentCount++;
            $actions[] = "reduced_batch_size_to_{$newBatchSize}";
        }
        
        // Trigger garbage collection if not done recently
        $timeSinceLastCleanup = microtime(true) - $this->lastCleanupTime;
        if ($timeSinceLastCleanup > 30) { // 30 seconds
            gc_collect_cycles();
            $this->cleanupCount++;
            $this->lastCleanupTime = microtime(true);
            $actions[] = "triggered_gc";
        }
        
        // Execute critical callbacks
        $this->executeCallbacks('critical', [
            'memory_usage' => $currentMemory,
            'memory_limit' => $this->memoryLimit,
        ]);
        
        Log::warning('Critical memory pressure detected', [
            'current_memory' => $this->formatBytes($currentMemory),
            'usage_percentage' => round(($currentMemory / $this->memoryLimit) * 100, 1) . '%',
            'new_batch_size' => $newBatchSize,
        ]);
        
        return $actions;
    }
    
    protected function handleWarningPressure(int $currentMemory): array
    {
        $actions = [];
        
        // Gentle batch size reduction
        $newBatchSize = max($this->minBatchSize, intval($this->currentBatchSize * 0.85));
        if ($newBatchSize !== $this->currentBatchSize) {
            $this->currentBatchSize = $newBatchSize;
            $this->batchAdjustmentCount++;
            $actions[] = "reduced_batch_size_to_{$newBatchSize}";
        }
        
        // Execute warning callbacks
        $this->executeCallbacks('warning', [
            'memory_usage' => $currentMemory,
            'memory_limit' => $this->memoryLimit,
        ]);
        
        return $actions;
    }
    
    protected function handleNormalPressure(): array
    {
        $actions = [];
        
        // Gradually increase batch size if memory usage is low
        if ($this->currentBatchSize < $this->originalBatchSize) {
            $newBatchSize = min($this->originalBatchSize, intval($this->currentBatchSize * 1.1));
            if ($newBatchSize !== $this->currentBatchSize) {
                $this->currentBatchSize = $newBatchSize;
                $this->batchAdjustmentCount++;
                $actions[] = "increased_batch_size_to_{$newBatchSize}";
            }
        }
        
        return $actions;
    }
    
    protected function analyzeTrend(): array
    {
        if (count($this->memoryHistory) < 3) {
            return ['status' => 'insufficient_data'];
        }
        
        $recent = array_slice($this->memoryHistory, -5);
        $memories = array_column($recent, 'memory');
        
        // Calculate trend
        $first = reset($memories);
        $last = end($memories);
        $growthRate = count($memories) > 1 ? ($last - $first) / (count($memories) - 1) : 0;
        
        // Predict future memory usage
        $timeToLimit = $growthRate > 0 ? 
            ($this->memoryLimit - $last) / $growthRate : 
            PHP_FLOAT_MAX;
        
        return [
            'status' => 'available',
            'memory_growth_rate' => $growthRate,
            'growth_rate_formatted' => $this->formatBytes($growthRate) . '/measurement',
            'predicted_time_to_limit' => min($timeToLimit, 9999),
            'trend_direction' => $growthRate > 1024 ? 'increasing' : ($growthRate < -1024 ? 'decreasing' : 'stable'),
        ];
    }
    
    protected function executeCallbacks(string $event, array $data): void
    {
        if (!isset($this->callbacks[$event])) {
            return;
        }
        
        foreach ($this->callbacks[$event] as $callback) {
            try {
                call_user_func($callback, $data);
            } catch (\Exception $e) {
                Log::warning("Memory callback failed: " . $e->getMessage());
            }
        }
    }
    
    public function getCurrentBatchSize(): int
    {
        return $this->currentBatchSize;
    }
    
    public function getMemoryEfficiencyReport(): array
    {
        $currentMemory = memory_get_usage(true);
        $memoryGrowth = $currentMemory - $this->startMemory;
        
        return [
            'start_memory' => $this->startMemory,
            'current_memory' => $currentMemory,
            'peak_memory' => $this->peakMemory,
            'memory_growth' => $memoryGrowth,
            'memory_limit' => $this->memoryLimit,
            'efficiency_ratio' => $this->memoryLimit > 0 ? ($currentMemory / $this->memoryLimit) : 0,
            'batch_adjustments_made' => $this->batchAdjustmentCount,
            'cleanups_performed' => $this->cleanupCount,
            'final_batch_size' => $this->currentBatchSize,
            'batch_size_efficiency' => $this->originalBatchSize > 0 ? 
                ($this->currentBatchSize / $this->originalBatchSize) : 1,
        ];
    }
    
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}