<?php

namespace Crumbls\Importer\Support;

use Exception;

class MigrationCheckpointer
{
    protected string $checkpointDir;
    protected string $migrationId;
    protected array $currentState = [];
    protected int $checkpointInterval = 100; // Save every 100 records
    protected int $recordsProcessed = 0;
    
    public function __construct(string $migrationId, array $config = [])
    {
        $this->migrationId = $migrationId;
        $this->checkpointDir = $config['checkpoint_dir'] ?? sys_get_temp_dir() . '/migration_checkpoints';
        $this->checkpointInterval = $config['checkpoint_interval'] ?? 100;
        
        $this->ensureCheckpointDirectory();
        $this->initializeState();
    }
    
    public function saveCheckpoint(array $state): string
    {
        $checkpointId = $this->generateCheckpointId();
        $checkpoint = [
            'id' => $checkpointId,
            'migration_id' => $this->migrationId,
            'timestamp' => time(),
            'records_processed' => $this->recordsProcessed,
            'state' => $state,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => $this->getExecutionTime(),
            'error_count' => $state['error_count'] ?? 0,
            'success_count' => $state['success_count'] ?? 0,
            'current_batch' => $state['current_batch'] ?? 0,
            'total_batches' => $state['total_batches'] ?? 0,
            'source_info' => $state['source_info'] ?? [],
            'failed_records' => $state['failed_records'] ?? [],
            'last_processed_id' => $state['last_processed_id'] ?? null
        ];
        
        $checkpointFile = $this->getCheckpointPath($checkpointId);
        
        if (file_put_contents($checkpointFile, json_encode($checkpoint, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Failed to save checkpoint: {$checkpointFile}");
        }
        
        // Update current state
        $this->currentState = $checkpoint;
        
        // Clean up old checkpoints (keep last 10)
        $this->cleanupOldCheckpoints();
        
        return $checkpointId;
    }
    
    public function loadCheckpoint(string $checkpointId): array
    {
        $checkpointFile = $this->getCheckpointPath($checkpointId);
        
        if (!file_exists($checkpointFile)) {
            throw new Exception("Checkpoint not found: {$checkpointId}");
        }
        
        $content = file_get_contents($checkpointFile);
        if ($content === false) {
            throw new Exception("Failed to read checkpoint: {$checkpointId}");
        }
        
        $checkpoint = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid checkpoint format: " . json_last_error_msg());
        }
        
        // Validate checkpoint integrity
        $this->validateCheckpoint($checkpoint);
        
        $this->currentState = $checkpoint;
        $this->recordsProcessed = $checkpoint['records_processed'];
        
        return $checkpoint;
    }
    
    public function getLatestCheckpoint(): ?array
    {
        $checkpoints = $this->listCheckpoints();
        
        if (empty($checkpoints)) {
            return null;
        }
        
        // Sort by timestamp descending
        usort($checkpoints, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        
        return $this->loadCheckpoint($checkpoints[0]['id']);
    }
    
    public function listCheckpoints(): array
    {
        $checkpoints = [];
        $pattern = $this->checkpointDir . '/' . $this->migrationId . '_*.json';
        
        foreach (glob($pattern) as $file) {
            $content = file_get_contents($file);
            if ($content === false) continue;
            
            $checkpoint = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) continue;
            
            $checkpoints[] = [
                'id' => $checkpoint['id'],
                'timestamp' => $checkpoint['timestamp'],
                'records_processed' => $checkpoint['records_processed'],
                'error_count' => $checkpoint['error_count'],
                'success_count' => $checkpoint['success_count'],
                'memory_usage' => $checkpoint['memory_usage'],
                'file_path' => $file
            ];
        }
        
        return $checkpoints;
    }
    
    public function shouldCreateCheckpoint(): bool
    {
        return ($this->recordsProcessed % $this->checkpointInterval) === 0;
    }
    
    public function incrementProcessedCount(): void
    {
        $this->recordsProcessed++;
    }
    
    public function getProcessedCount(): int
    {
        return $this->recordsProcessed;
    }
    
    public function getCurrentState(): array
    {
        return $this->currentState;
    }
    
    public function canResume(): bool
    {
        return !empty($this->listCheckpoints());
    }
    
    public function deleteCheckpoint(string $checkpointId): bool
    {
        $checkpointFile = $this->getCheckpointPath($checkpointId);
        
        if (file_exists($checkpointFile)) {
            return unlink($checkpointFile);
        }
        
        return false;
    }
    
    public function deleteAllCheckpoints(): int
    {
        $deleted = 0;
        $checkpoints = $this->listCheckpoints();
        
        foreach ($checkpoints as $checkpoint) {
            if ($this->deleteCheckpoint($checkpoint['id'])) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    public function getCheckpointMetrics(): array
    {
        $checkpoints = $this->listCheckpoints();
        
        if (empty($checkpoints)) {
            return [
                'total_checkpoints' => 0,
                'latest_timestamp' => null,
                'total_records_processed' => 0,
                'avg_processing_rate' => 0
            ];
        }
        
        $latest = max(array_column($checkpoints, 'timestamp'));
        $oldest = min(array_column($checkpoints, 'timestamp'));
        $totalRecords = max(array_column($checkpoints, 'records_processed'));
        
        $duration = $latest - $oldest;
        $avgRate = $duration > 0 ? $totalRecords / $duration : 0;
        
        return [
            'total_checkpoints' => count($checkpoints),
            'latest_timestamp' => $latest,
            'oldest_timestamp' => $oldest,
            'total_records_processed' => $totalRecords,
            'avg_processing_rate' => round($avgRate, 2),
            'duration_seconds' => $duration,
            'memory_usage_trend' => $this->calculateMemoryTrend($checkpoints)
        ];
    }
    
    public function generateRecoveryReport(): array
    {
        $checkpoints = $this->listCheckpoints();
        
        if (empty($checkpoints)) {
            return ['status' => 'no_checkpoints'];
        }
        
        $latest = $this->getLatestCheckpoint();
        
        return [
            'status' => 'can_resume',
            'migration_id' => $this->migrationId,
            'latest_checkpoint' => [
                'id' => $latest['id'],
                'timestamp' => date('Y-m-d H:i:s', $latest['timestamp']),
                'records_processed' => $latest['records_processed'],
                'success_rate' => $this->calculateSuccessRate($latest),
                'estimated_progress' => $this->calculateProgress($latest),
                'time_elapsed' => $this->formatDuration($latest['execution_time'])
            ],
            'available_checkpoints' => count($checkpoints),
            'recovery_recommendations' => $this->generateRecoveryRecommendations($latest)
        ];
    }
    
    protected function ensureCheckpointDirectory(): void
    {
        if (!is_dir($this->checkpointDir)) {
            if (!mkdir($this->checkpointDir, 0755, true)) {
                throw new Exception("Failed to create checkpoint directory: {$this->checkpointDir}");
            }
        }
        
        if (!is_writable($this->checkpointDir)) {
            throw new Exception("Checkpoint directory is not writable: {$this->checkpointDir}");
        }
    }
    
    protected function initializeState(): void
    {
        $this->currentState = [
            'migration_id' => $this->migrationId,
            'start_time' => time(),
            'records_processed' => 0,
            'error_count' => 0,
            'success_count' => 0,
            'current_batch' => 0,
            'failed_records' => []
        ];
    }
    
    protected function generateCheckpointId(): string
    {
        return $this->migrationId . '_' . date('Y-m-d_H-i-s') . '_' . uniqid();
    }
    
    protected function getCheckpointPath(string $checkpointId): string
    {
        return $this->checkpointDir . '/' . $checkpointId . '.json';
    }
    
    protected function getExecutionTime(): float
    {
        $startTime = $this->currentState['start_time'] ?? time();
        return time() - $startTime;
    }
    
    protected function cleanupOldCheckpoints(): void
    {
        $checkpoints = $this->listCheckpoints();
        
        if (count($checkpoints) <= 10) {
            return;
        }
        
        // Sort by timestamp ascending (oldest first)
        usort($checkpoints, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        // Delete oldest checkpoints, keep last 10
        $toDelete = array_slice($checkpoints, 0, -10);
        
        foreach ($toDelete as $checkpoint) {
            $this->deleteCheckpoint($checkpoint['id']);
        }
    }
    
    protected function validateCheckpoint(array $checkpoint): void
    {
        $required = ['id', 'migration_id', 'timestamp', 'records_processed', 'state'];
        
        foreach ($required as $field) {
            if (!isset($checkpoint[$field])) {
                throw new Exception("Invalid checkpoint: missing field '{$field}'");
            }
        }
        
        if ($checkpoint['migration_id'] !== $this->migrationId) {
            throw new Exception("Checkpoint migration ID mismatch");
        }
    }
    
    protected function calculateMemoryTrend(array $checkpoints): string
    {
        if (count($checkpoints) < 2) {
            return 'insufficient_data';
        }
        
        $memoryUsages = array_column($checkpoints, 'memory_usage');
        $first = reset($memoryUsages);
        $last = end($memoryUsages);
        
        if ($last > $first * 1.5) {
            return 'increasing_significantly';
        } elseif ($last > $first * 1.1) {
            return 'increasing';
        } elseif ($last < $first * 0.9) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }
    
    protected function calculateSuccessRate(array $checkpoint): float
    {
        $total = $checkpoint['success_count'] + $checkpoint['error_count'];
        return $total > 0 ? round(($checkpoint['success_count'] / $total) * 100, 2) : 0;
    }
    
    protected function calculateProgress(array $checkpoint): float
    {
        $totalBatches = $checkpoint['total_batches'] ?? 0;
        $currentBatch = $checkpoint['current_batch'] ?? 0;
        
        return $totalBatches > 0 ? round(($currentBatch / $totalBatches) * 100, 2) : 0;
    }
    
    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }
    
    protected function generateRecoveryRecommendations(array $checkpoint): array
    {
        $recommendations = [];
        
        $successRate = $this->calculateSuccessRate($checkpoint);
        if ($successRate < 90) {
            $recommendations[] = "Low success rate ({$successRate}%) - review error logs before resuming";
        }
        
        $memoryUsage = $checkpoint['memory_usage'] ?? 0;
        if ($memoryUsage > 500 * 1024 * 1024) { // 500MB
            $recommendations[] = "High memory usage detected - consider reducing batch size";
        }
        
        $errorCount = $checkpoint['error_count'] ?? 0;
        if ($errorCount > 10) {
            $recommendations[] = "Multiple errors encountered - investigate data quality issues";
        }
        
        if (empty($recommendations)) {
            $recommendations[] = "Migration can be safely resumed from latest checkpoint";
        }
        
        return $recommendations;
    }
}