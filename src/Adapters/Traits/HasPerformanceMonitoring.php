<?php

namespace Crumbls\Importer\Adapters\Traits;

trait HasPerformanceMonitoring
{
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    protected function getCurrentMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => $this->getMemoryLimit(),
            'formatted' => [
                'current' => $this->formatBytes(memory_get_usage(true)),
                'peak' => $this->formatBytes(memory_get_peak_usage(true)),
                'limit' => $this->formatBytes($this->getMemoryLimit())
            ]
        ];
    }
    
    protected function calculateMemoryUsagePercentage(): float
    {
        $current = memory_get_usage(true);
        $limit = $this->getMemoryLimit();
        
        return $limit > 0 ? ($current / $limit) * 100 : 0;
    }
    
    protected function shouldTriggerMemoryWarning(float $threshold = 80.0): bool
    {
        return $this->calculateMemoryUsagePercentage() >= $threshold;
    }
    
    protected function getPerformanceSnapshot(): array
    {
        return [
            'memory' => $this->getCurrentMemoryUsage(),
            'memory_percentage' => $this->calculateMemoryUsagePercentage(),
            'timestamp' => microtime(true),
            'formatted_time' => date('Y-m-d H:i:s')
        ];
    }
    
    protected function recordPerformanceMetric(string $name, $value, array $context = []): void
    {
        if (method_exists($this, 'log')) {
            $this->log('debug', "Performance metric: {$name}", array_merge([
                'metric' => $name,
                'value' => $value,
                'memory_usage' => $this->getCurrentMemoryUsage()
            ], $context));
        }
    }
    
    protected function getMemoryLimit(): int
    {
        if (method_exists($this, 'config')) {
            $memoryLimitStr = $this->config('memory_limit', '256M');
        } else {
            $memoryLimitStr = '256M';
        }
        
        return $this->parseMemoryLimit($memoryLimitStr);
    }
    
    protected function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $lastChar = strtoupper(substr($memoryLimit, -1));
        $numericValue = (int) substr($memoryLimit, 0, -1);
        
        return match ($lastChar) {
            'G' => $numericValue * 1024 * 1024 * 1024,
            'M' => $numericValue * 1024 * 1024,
            'K' => $numericValue * 1024,
            default => (int) $memoryLimit
        };
    }
}