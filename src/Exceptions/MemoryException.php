<?php

namespace Crumbls\Importer\Exceptions;

class MemoryException extends MigrationException
{
    public function __construct(
        string $message,
        string $migrationId,
        public readonly int $currentUsage,
        public readonly int $memoryLimit,
        public readonly string $phase = 'unknown',
        string $entityType = 'unknown',
        array $context = []
    ) {
        $recoveryOptions = [
            'create_checkpoint' => 'Create checkpoint and continue with reduced batch size',
            'flush_and_continue' => 'Flush temporary storage and continue',
            'increase_memory_limit' => 'Increase PHP memory limit if possible',
            'abort_with_checkpoint' => 'Save progress and abort migration'
        ];
        
        parent::__construct(
            $message,
            $migrationId,
            $entityType,
            array_merge($context, [
                'current_memory_usage' => $this->formatBytes($currentUsage),
                'memory_limit' => $this->formatBytes($memoryLimit),
                'usage_percentage' => round(($currentUsage / $memoryLimit) * 100, 2),
                'phase' => $phase
            ]),
            $recoveryOptions
        );
    }
    
    public function getUsagePercentage(): float
    {
        return ($this->currentUsage / $this->memoryLimit) * 100;
    }
    
    public function getCurrentUsage(): int
    {
        return $this->currentUsage;
    }
    
    public function getMemoryLimit(): int
    {
        return $this->memoryLimit;
    }
    
    public function getFormattedUsage(): string
    {
        return $this->formatBytes($this->currentUsage);
    }
    
    public function getFormattedLimit(): string
    {
        return $this->formatBytes($this->memoryLimit);
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}