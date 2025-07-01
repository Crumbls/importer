<?php

namespace Crumbls\Importer\RateLimit;

class RateLimiter
{
    protected int $maxOperations;
    protected int $timeWindow;
    protected array $operations = [];
    protected float $lastCleanup;
    
    public function __construct(int $maxOperations, int $timeWindow = 60)
    {
        $this->maxOperations = $maxOperations;
        $this->timeWindow = $timeWindow;
        $this->lastCleanup = microtime(true);
    }
    
    public function attempt(string $key = 'default', int $cost = 1): bool
    {
        $this->cleanup();
        
        $now = microtime(true);
        
        if (!isset($this->operations[$key])) {
            $this->operations[$key] = [];
        }
        
        $currentCost = $this->getCurrentCost($key);
        
        if ($currentCost + $cost > $this->maxOperations) {
            return false;
        }
        
        $this->operations[$key][] = [
            'timestamp' => $now,
            'cost' => $cost
        ];
        
        return true;
    }
    
    public function wait(string $key = 'default', int $cost = 1): void
    {
        while (!$this->attempt($key, $cost)) {
            $waitTime = $this->getWaitTime($key, $cost);
            if ($waitTime > 0) {
                usleep($waitTime * 1000); // Convert to microseconds
            }
        }
    }
    
    public function getWaitTime(string $key = 'default', int $cost = 1): int
    {
        $this->cleanup();
        
        $currentCost = $this->getCurrentCost($key);
        
        if ($currentCost + $cost <= $this->maxOperations) {
            return 0;
        }
        
        if (!isset($this->operations[$key]) || empty($this->operations[$key])) {
            return 0;
        }
        
        $oldestOperation = reset($this->operations[$key]);
        $timeUntilExpiry = ($oldestOperation['timestamp'] + $this->timeWindow) - microtime(true);
        
        return max(0, (int) ($timeUntilExpiry * 1000));
    }
    
    public function getCurrentCost(string $key = 'default'): int
    {
        if (!isset($this->operations[$key])) {
            return 0;
        }
        
        return array_sum(array_column($this->operations[$key], 'cost'));
    }
    
    public function getRemainingCapacity(string $key = 'default'): int
    {
        return max(0, $this->maxOperations - $this->getCurrentCost($key));
    }
    
    public function getStats(string $key = 'default'): array
    {
        $currentCost = $this->getCurrentCost($key);
        
        return [
            'current_cost' => $currentCost,
            'max_operations' => $this->maxOperations,
            'remaining_capacity' => $this->getRemainingCapacity($key),
            'utilization_percentage' => round(($currentCost / $this->maxOperations) * 100, 2),
            'time_window' => $this->timeWindow,
            'operations_count' => isset($this->operations[$key]) ? count($this->operations[$key]) : 0
        ];
    }
    
    public function reset(string $key = 'default'): void
    {
        unset($this->operations[$key]);
    }
    
    public function resetAll(): void
    {
        $this->operations = [];
    }
    
    protected function cleanup(): void
    {
        $now = microtime(true);
        
        // Only cleanup every 5 seconds to avoid overhead
        if ($now - $this->lastCleanup < 5) {
            return;
        }
        
        $cutoff = $now - $this->timeWindow;
        
        foreach ($this->operations as $key => $operations) {
            $this->operations[$key] = array_filter($operations, function($operation) use ($cutoff) {
                return $operation['timestamp'] > $cutoff;
            });
            
            if (empty($this->operations[$key])) {
                unset($this->operations[$key]);
            }
        }
        
        $this->lastCleanup = $now;
    }
}