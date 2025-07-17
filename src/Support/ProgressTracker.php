<?php

namespace Crumbls\Importer\Support;

use Illuminate\Support\Facades\Log;

class ProgressTracker
{
    protected int $totalItems;
    protected int $processedItems = 0;
    protected float $startTime;
    protected float $lastUpdateTime = 0;
    protected int $lastUpdateCount = 0;
    protected array $callbacks = [];
    
    // Configuration
    protected float $minUpdateIntervalSeconds;
    protected float $minUpdatePercentage;
    protected int $forceUpdateEveryItems;
    
    // Performance metrics
    protected array $performanceMetrics = [];
    
    public function __construct(
        int $totalItems,
        array $config = []
    ) {
        $this->totalItems = max(1, $totalItems); // Prevent division by zero
        $this->startTime = microtime(true);
        $this->lastUpdateTime = $this->startTime;
        
        // Configure update thresholds to prevent database spam
        $this->minUpdateIntervalSeconds = $config['min_update_interval'] ?? 2.0; // Max 1 update per 2 seconds
        $this->minUpdatePercentage = $config['min_update_percentage'] ?? 1.0; // Min 1% progress
        $this->forceUpdateEveryItems = $config['force_update_every'] ?? 100; // Force update every 100 items
    }
    
    public function addCallback(string $type, callable $callback): self
    {
        if (!isset($this->callbacks[$type])) {
            $this->callbacks[$type] = [];
        }
        $this->callbacks[$type][] = $callback;
        return $this;
    }
    
    public function increment(int $count = 1): void
    {
        $this->processedItems += $count;
        
        if ($this->shouldUpdate()) {
            $this->performUpdate();
        }
    }
    
    public function setProgress(int $processedItems): void
    {
        $this->processedItems = min($processedItems, $this->totalItems);
        
        if ($this->shouldUpdate()) {
            $this->performUpdate();
        }
    }
    
    public function complete(): void
    {
        $this->processedItems = $this->totalItems;
        $this->performUpdate(true); // Force final update
    }
    
    public function getProgress(): array
    {
        $currentTime = microtime(true);
        $elapsed = $currentTime - $this->startTime;
        $percentage = ($this->processedItems / $this->totalItems) * 100;
        
        // Calculate performance metrics
        $itemsPerSecond = $elapsed > 0 ? $this->processedItems / $elapsed : 0;
        $remainingItems = $this->totalItems - $this->processedItems;
        $estimatedTimeRemaining = $itemsPerSecond > 0 ? $remainingItems / $itemsPerSecond : 0;
        
        return [
            'total_items' => $this->totalItems,
            'processed_items' => $this->processedItems,
            'remaining_items' => $remainingItems,
            'percentage' => round($percentage, 2),
            'elapsed_seconds' => round($elapsed, 2),
            'items_per_second' => round($itemsPerSecond, 2),
            'estimated_time_remaining' => round($estimatedTimeRemaining, 2),
            'estimated_completion' => date('Y-m-d H:i:s', time() + (int)$estimatedTimeRemaining),
        ];
    }
    
    protected function shouldUpdate(): bool
    {
        $currentTime = microtime(true);
        $timeSinceLastUpdate = $currentTime - $this->lastUpdateTime;
        $itemsSinceLastUpdate = $this->processedItems - $this->lastUpdateCount;
        $percentageChange = ($itemsSinceLastUpdate / $this->totalItems) * 100;
        
        // Force update conditions
        if ($this->processedItems >= $this->totalItems) {
            return true; // Always update on completion
        }
        
        if ($itemsSinceLastUpdate >= $this->forceUpdateEveryItems) {
            return true; // Force update every N items
        }
        
        // Throttling conditions
        if ($timeSinceLastUpdate < $this->minUpdateIntervalSeconds) {
            return false; // Too soon since last update
        }
        
        if ($percentageChange < $this->minUpdatePercentage) {
            return false; // Not enough progress made
        }
        
        return true;
    }
    
    protected function performUpdate(bool $force = false): void
    {
        $currentTime = microtime(true);
        $progress = $this->getProgress();
        
        // Update tracking variables
        $this->lastUpdateTime = $currentTime;
        $this->lastUpdateCount = $this->processedItems;
        
        // Store performance metrics for analysis
        $this->performanceMetrics[] = [
            'timestamp' => $currentTime,
            'processed_items' => $this->processedItems,
            'items_per_second' => $progress['items_per_second'],
            'memory_usage' => memory_get_usage(true),
        ];
        
        // Keep only last 10 metrics to prevent memory growth
        if (count($this->performanceMetrics) > 10) {
            $this->performanceMetrics = array_slice($this->performanceMetrics, -10);
        }
        
        // Execute callbacks
        $this->executeCallbacks('progress', $progress);
        
        // Log significant milestones
        if ($force || $progress['percentage'] % 10 == 0) {
            Log::info('Progress update', [
                'percentage' => $progress['percentage'],
                'processed' => $progress['processed_items'],
                'total' => $progress['total_items'],
                'rate' => $progress['items_per_second'] . ' items/sec',
                'eta' => gmdate('H:i:s', (int)$progress['estimated_time_remaining']),
            ]);
        }
    }
    
    protected function executeCallbacks(string $type, array $data): void
    {
        if (!isset($this->callbacks[$type])) {
            return;
        }
        
        foreach ($this->callbacks[$type] as $callback) {
            try {
                call_user_func($callback, $data);
            } catch (\Exception $e) {
                Log::warning("Progress callback failed: " . $e->getMessage());
            }
        }
    }
    
    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }
    
    public function getThroughputAnalysis(): array
    {
        if (count($this->performanceMetrics) < 2) {
            return ['status' => 'insufficient_data'];
        }
        
        $recent = array_slice($this->performanceMetrics, -5);
        $rates = array_column($recent, 'items_per_second');
        
        return [
            'status' => 'available',
            'current_rate' => end($rates),
            'average_rate' => array_sum($rates) / count($rates),
            'min_rate' => min($rates),
            'max_rate' => max($rates),
            'rate_stability' => $this->calculateStability($rates),
        ];
    }
    
    protected function calculateStability(array $rates): string
    {
        if (count($rates) < 2) {
            return 'unknown';
        }
        
        $mean = array_sum($rates) / count($rates);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $rates)) / count($rates);
        $coefficient = $mean > 0 ? sqrt($variance) / $mean : 1;
        
        if ($coefficient < 0.1) return 'very_stable';
        if ($coefficient < 0.25) return 'stable';
        if ($coefficient < 0.5) return 'moderate';
        return 'unstable';
    }
}