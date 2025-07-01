<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Exceptions\MemoryException;

class MemoryManager
{
    protected int $memoryLimit;
    protected int $warningThreshold;
    protected int $checkInterval;
    protected array $callbacks = [];
    protected array $memoryHistory = [];
    protected int $lastCheck = 0;
    
    public function __construct(array $config = [])
    {
        $this->memoryLimit = $this->parseMemoryLimit($config['memory_limit'] ?? '256M');
        $this->warningThreshold = (int) ($this->memoryLimit * ($config['warning_threshold'] ?? 0.8));
        $this->checkInterval = $config['check_interval'] ?? 100; // Check every N operations
    }
    
    public function monitor(string $phase = 'unknown', ?string $migrationId = null): void
    {
        $this->lastCheck++;
        
        // Only check every N operations to avoid overhead
        if ($this->lastCheck % $this->checkInterval !== 0) {
            return;
        }
        
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);
        
        // Record memory usage history
        $this->memoryHistory[] = [
            'timestamp' => microtime(true),
            'phase' => $phase,
            'current_usage' => $currentUsage,
            'peak_usage' => $peakUsage,
            'limit' => $this->memoryLimit
        ];
        
        // Keep only last 100 entries
        if (count($this->memoryHistory) > 100) {
            array_shift($this->memoryHistory);
        }
        
        // Check if we're approaching the limit
        if ($currentUsage > $this->warningThreshold) {
            $this->triggerWarningCallbacks($currentUsage, $phase);
        }
        
        // Check if we've exceeded the limit
        if ($currentUsage > $this->memoryLimit) {
            throw new MemoryException(
                "Memory limit exceeded during {$phase}",
                $migrationId ?? 'unknown',
                $currentUsage,
                $this->memoryLimit,
                $phase
            );
        }
    }
    
    public function onWarning(callable $callback): self
    {
        $this->callbacks['warning'][] = $callback;
        return $this;
    }
    
    public function onCritical(callable $callback): self
    {
        $this->callbacks['critical'][] = $callback;
        return $this;
    }
    
    public function getCurrentUsage(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        return [
            'current_bytes' => $current,
            'current_formatted' => $this->formatBytes($current),
            'peak_bytes' => $peak,
            'peak_formatted' => $this->formatBytes($peak),
            'limit_bytes' => $this->memoryLimit,
            'limit_formatted' => $this->formatBytes($this->memoryLimit),
            'usage_percentage' => round(($current / $this->memoryLimit) * 100, 2),
            'peak_percentage' => round(($peak / $this->memoryLimit) * 100, 2)
        ];
    }
    
    public function getMemoryHistory(): array
    {
        return $this->memoryHistory;
    }
    
    public function isApproachingLimit(): bool
    {
        return memory_get_usage(true) > $this->warningThreshold;
    }
    
    public function getRemainingMemory(): int
    {
        return max(0, $this->memoryLimit - memory_get_usage(true));
    }
    
    public function getRemainingMemoryFormatted(): string
    {
        return $this->formatBytes($this->getRemainingMemory());
    }
    
    public function optimizeMemory(): array
    {
        $beforeUsage = memory_get_usage(true);
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
        } else {
            $collected = 0;
        }
        
        $afterUsage = memory_get_usage(true);
        $freed = $beforeUsage - $afterUsage;
        
        return [
            'cycles_collected' => $collected,
            'memory_freed_bytes' => $freed,
            'memory_freed_formatted' => $this->formatBytes($freed),
            'before_usage' => $this->formatBytes($beforeUsage),
            'after_usage' => $this->formatBytes($afterUsage)
        ];
    }
    
    public function suggestBatchSize(int $currentBatchSize, int $recordSize = 1024): int
    {
        $availableMemory = $this->getRemainingMemory();
        
        // Reserve 25% of remaining memory for other operations
        $usableMemory = (int) ($availableMemory * 0.75);
        
        // Calculate how many records we can fit
        $maxRecords = max(1, (int) ($usableMemory / $recordSize));
        
        // Don't exceed current batch size by more than 50%
        $maxAllowed = (int) ($currentBatchSize * 1.5);
        
        // Don't go below 10% of current batch size
        $minAllowed = max(1, (int) ($currentBatchSize * 0.1));
        
        return max($minAllowed, min($maxRecords, $maxAllowed));
    }
    
    public function createCheckpointIfNeeded(callable $checkpointCallback): bool
    {
        if ($this->isApproachingLimit()) {
            $checkpointCallback();
            $this->optimizeMemory();
            return true;
        }
        
        return false;
    }
    
    public function getMemoryTrend(): array
    {
        if (count($this->memoryHistory) < 2) {
            return ['trend' => 'unknown', 'rate' => 0];
        }
        
        $recent = array_slice($this->memoryHistory, -10);
        $first = reset($recent);
        $last = end($recent);
        
        $timeDiff = $last['timestamp'] - $first['timestamp'];
        $memoryDiff = $last['current_usage'] - $first['current_usage'];
        
        if ($timeDiff <= 0) {
            return ['trend' => 'stable', 'rate' => 0];
        }
        
        $rate = $memoryDiff / $timeDiff; // bytes per second
        
        if ($rate > 1024 * 1024) { // More than 1MB/sec increase
            $trend = 'rapidly_increasing';
        } elseif ($rate > 1024 * 100) { // More than 100KB/sec increase
            $trend = 'increasing';
        } elseif ($rate < -1024 * 100) { // Decreasing
            $trend = 'decreasing';
        } else {
            $trend = 'stable';
        }
        
        return [
            'trend' => $trend,
            'rate' => $rate,
            'rate_formatted' => $this->formatBytes(abs($rate)) . '/sec'
        ];
    }
    
    protected function triggerWarningCallbacks(int $currentUsage, string $phase): void
    {
        $context = [
            'current_usage' => $currentUsage,
            'memory_limit' => $this->memoryLimit,
            'usage_percentage' => ($currentUsage / $this->memoryLimit) * 100,
            'phase' => $phase,
            'remaining_memory' => $this->getRemainingMemory()
        ];
        
        foreach ($this->callbacks['warning'] ?? [] as $callback) {
            try {
                $callback($context);
            } catch (\Exception $e) {
                // Don't let callback failures break the monitoring
                error_log("Memory warning callback failed: " . $e->getMessage());
            }
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