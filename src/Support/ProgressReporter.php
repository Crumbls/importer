<?php

namespace Crumbls\Importer\Support;

class ProgressReporter
{
    protected array $config;
    protected array $progress = [];
    protected float $startTime;
    protected $progressCallback = null;
    protected array $milestones = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'update_interval' => 1.0, // Update every second
            'estimate_eta' => true,
            'track_memory' => true,
            'track_performance' => true,
            'detailed_reporting' => false
        ], $config);
        
        $this->startTime = microtime(true);
        $this->initializeProgress();
    }
    
    public function initialize(array $totalCounts): self
    {
        foreach ($totalCounts as $entityType => $count) {
            $this->progress[$entityType] = [
                'total' => $count,
                'processed' => 0,
                'imported' => 0,
                'failed' => 0,
                'skipped' => 0,
                'percentage' => 0,
                'rate' => 0,
                'eta' => null,
                'started_at' => null,
                'completed_at' => null,
                'status' => 'pending'
            ];
        }
        
        return $this;
    }
    
    public function startEntity(string $entityType): self
    {
        if (isset($this->progress[$entityType])) {
            $this->progress[$entityType]['started_at'] = microtime(true);
            $this->progress[$entityType]['status'] = 'processing';
            
            $this->reportProgress('entity_started', [
                'entity_type' => $entityType,
                'total_records' => $this->progress[$entityType]['total']
            ]);
        }
        
        return $this;
    }
    
    public function updateProgress(string $entityType, array $stats): self
    {
        if (!isset($this->progress[$entityType])) {
            return $this;
        }
        
        $entity = &$this->progress[$entityType];
        
        // Update counters
        $entity['processed'] = $stats['processed'] ?? $entity['processed'];
        $entity['imported'] = $stats['imported'] ?? $entity['imported'];
        $entity['failed'] = $stats['failed'] ?? $entity['failed'];
        $entity['skipped'] = $stats['skipped'] ?? $entity['skipped'];
        
        // Calculate percentage
        if ($entity['total'] > 0) {
            $entity['percentage'] = round(($entity['processed'] / $entity['total']) * 100, 2);
        }
        
        // Calculate processing rate (records per second)
        if ($entity['started_at']) {
            $elapsed = microtime(true) - $entity['started_at'];
            if ($elapsed > 0) {
                $entity['rate'] = round($entity['processed'] / $elapsed, 2);
            }
        }
        
        // Estimate time remaining
        if ($this->config['estimate_eta'] && $entity['rate'] > 0) {
            $remaining = $entity['total'] - $entity['processed'];
            $entity['eta'] = round($remaining / $entity['rate']);
        }
        
        $this->reportProgress('progress_updated', [
            'entity_type' => $entityType,
            'progress' => $entity
        ]);
        
        return $this;
    }
    
    public function completeEntity(string $entityType, array $finalStats = []): self
    {
        if (!isset($this->progress[$entityType])) {
            return $this;
        }
        
        $entity = &$this->progress[$entityType];
        $entity['completed_at'] = microtime(true);
        $entity['status'] = 'completed';
        $entity['percentage'] = 100;
        
        // Update with final stats if provided
        if (!empty($finalStats)) {
            $entity = array_merge($entity, $finalStats);
        }
        
        // Record milestone
        $this->addMilestone($entityType . '_completed', [
            'entity_type' => $entityType,
            'final_stats' => $entity
        ]);
        
        $this->reportProgress('entity_completed', [
            'entity_type' => $entityType,
            'final_stats' => $entity
        ]);
        
        return $this;
    }
    
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }
    
    public function getOverallProgress(): array
    {
        $totalRecords = 0;
        $totalProcessed = 0;
        $totalImported = 0;
        $totalFailed = 0;
        $totalSkipped = 0;
        
        foreach ($this->progress as $entity) {
            $totalRecords += $entity['total'];
            $totalProcessed += $entity['processed'];
            $totalImported += $entity['imported'];
            $totalFailed += $entity['failed'];
            $totalSkipped += $entity['skipped'];
        }
        
        $overallPercentage = $totalRecords > 0 ? round(($totalProcessed / $totalRecords) * 100, 2) : 0;
        $elapsedTime = microtime(true) - $this->startTime;
        $overallRate = $elapsedTime > 0 ? round($totalProcessed / $elapsedTime, 2) : 0;
        
        $eta = null;
        if ($overallRate > 0 && $totalRecords > $totalProcessed) {
            $remaining = $totalRecords - $totalProcessed;
            $eta = round($remaining / $overallRate);
        }
        
        return [
            'total_records' => $totalRecords,
            'processed' => $totalProcessed,
            'imported' => $totalImported,
            'failed' => $totalFailed,
            'skipped' => $totalSkipped,
            'percentage' => $overallPercentage,
            'rate' => $overallRate,
            'eta_seconds' => $eta,
            'eta_formatted' => $eta ? $this->formatDuration($eta) : null,
            'elapsed_time' => $elapsedTime,
            'elapsed_formatted' => $this->formatDuration($elapsedTime),
            'memory_usage' => $this->config['track_memory'] ? memory_get_usage(true) : null,
            'memory_formatted' => $this->config['track_memory'] ? $this->formatBytes(memory_get_usage(true)) : null
        ];
    }
    
    public function getEntityProgress(string $entityType): ?array
    {
        return $this->progress[$entityType] ?? null;
    }
    
    public function getAllEntityProgress(): array
    {
        return $this->progress;
    }
    
    public function getDetailedReport(): array
    {
        $overall = $this->getOverallProgress();
        
        $report = [
            'overall' => $overall,
            'entities' => $this->progress,
            'milestones' => $this->milestones,
            'performance_summary' => $this->getPerformanceSummary()
        ];
        
        if ($this->config['track_memory']) {
            $report['memory_info'] = [
                'current_usage' => memory_get_usage(true),
                'current_formatted' => $this->formatBytes(memory_get_usage(true)),
                'peak_usage' => memory_get_peak_usage(true),
                'peak_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
                'limit' => ini_get('memory_limit')
            ];
        }
        
        return $report;
    }
    
    public function getProgressBar(string $entityType, int $width = 50): string
    {
        $entity = $this->progress[$entityType] ?? null;
        if (!$entity) {
            return '';
        }
        
        $percentage = $entity['percentage'];
        $filled = (int) (($percentage / 100) * $width);
        $empty = $width - $filled;
        
        $bar = '[' . str_repeat('=', $filled) . str_repeat('-', $empty) . ']';
        $status = sprintf(' %s %6.2f%% (%d/%d)', $bar, $percentage, $entity['processed'], $entity['total']);
        
        if ($entity['rate'] > 0) {
            $status .= sprintf(' | %s rec/s', number_format($entity['rate'], 1));
        }
        
        if ($entity['eta']) {
            $status .= sprintf(' | ETA: %s', $this->formatDuration($entity['eta']));
        }
        
        return $status;
    }
    
    public function getConsoleOutput(bool $detailed = false): string
    {
        $output = [];
        $overall = $this->getOverallProgress();
        
        // Overall progress
        $output[] = "=== Migration Progress ===";
        $output[] = sprintf("Overall: %d/%d records (%s%%)", 
            $overall['processed'], 
            $overall['total_records'], 
            number_format($overall['percentage'], 1)
        );
        
        if ($overall['rate'] > 0) {
            $output[] = sprintf("Rate: %s records/sec", number_format($overall['rate'], 1));
        }
        
        if ($overall['eta_formatted']) {
            $output[] = sprintf("ETA: %s", $overall['eta_formatted']);
        }
        
        $output[] = sprintf("Elapsed: %s", $overall['elapsed_formatted']);
        
        if ($this->config['track_memory']) {
            $output[] = sprintf("Memory: %s", $overall['memory_formatted']);
        }
        
        $output[] = "";
        
        // Entity progress
        foreach ($this->progress as $entityType => $entity) {
            if ($entity['status'] === 'pending') {
                continue;
            }
            
            $output[] = sprintf("%s: %s", 
                ucfirst($entityType), 
                $this->getProgressBar($entityType, 30)
            );
            
            if ($detailed && $entity['status'] === 'completed') {
                $output[] = sprintf("  ✓ Imported: %d, Failed: %d, Skipped: %d", 
                    $entity['imported'], 
                    $entity['failed'], 
                    $entity['skipped']
                );
            }
        }
        
        return implode("\n", $output);
    }
    
    public function addMilestone(string $name, array $data = []): self
    {
        $this->milestones[] = [
            'name' => $name,
            'timestamp' => microtime(true),
            'elapsed_time' => microtime(true) - $this->startTime,
            'data' => $data
        ];
        
        return $this;
    }
    
    public function getMilestones(): array
    {
        return $this->milestones;
    }
    
    protected function initializeProgress(): void
    {
        $this->progress = [];
        $this->milestones = [];
        
        $this->addMilestone('migration_started', [
            'start_time' => $this->startTime,
            'memory_usage' => memory_get_usage(true)
        ]);
    }
    
    protected function reportProgress(string $eventType, array $data): void
    {
        if (!$this->progressCallback) {
            return;
        }
        
        $progressData = [
            'event_type' => $eventType,
            'timestamp' => microtime(true),
            'overall_progress' => $this->getOverallProgress(),
            'data' => $data
        ];
        
        try {
            call_user_func($this->progressCallback, $progressData);
        } catch (\Exception $e) {
            // Don't let callback failures break the migration
            error_log("Progress callback failed: " . $e->getMessage());
        }
    }
    
    protected function getPerformanceSummary(): array
    {
        if (!$this->config['track_performance']) {
            return [];
        }
        
        $completedEntities = array_filter($this->progress, fn($entity) => $entity['status'] === 'completed');
        
        if (empty($completedEntities)) {
            return [];
        }
        
        $rates = array_column($completedEntities, 'rate');
        $durations = [];
        
        foreach ($completedEntities as $entity) {
            if ($entity['started_at'] && $entity['completed_at']) {
                $durations[] = $entity['completed_at'] - $entity['started_at'];
            }
        }
        
        return [
            'avg_rate' => count($rates) > 0 ? round(array_sum($rates) / count($rates), 2) : 0,
            'max_rate' => count($rates) > 0 ? max($rates) : 0,
            'min_rate' => count($rates) > 0 ? min($rates) : 0,
            'avg_duration' => count($durations) > 0 ? round(array_sum($durations) / count($durations), 2) : 0,
            'completed_entities' => count($completedEntities),
            'total_entities' => count($this->progress)
        ];
    }
    
    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', (int) $seconds);
        } elseif ($seconds < 3600) {
            return sprintf('%dm %ds', (int) ($seconds / 60), (int) ($seconds % 60));
        } else {
            $hours = (int) ($seconds / 3600);
            $minutes = (int) (($seconds % 3600) / 60);
            $secs = (int) ($seconds % 60);
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
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
}