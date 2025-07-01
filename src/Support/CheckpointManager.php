<?php

namespace Crumbls\Importer\Support;

class CheckpointManager
{
    protected string $migrationId;
    protected string $checkpointPath;
    protected array $checkpoints = [];
    
    public function __construct(string $migrationId, ?string $basePath = null)
    {
        $this->migrationId = $migrationId;
        $this->checkpointPath = $basePath ?? storage_path('importer/checkpoints');
        
        // Ensure checkpoint directory exists
        if (!is_dir($this->checkpointPath)) {
            mkdir($this->checkpointPath, 0755, true);
        }
        
        $this->loadExistingCheckpoints();
    }
    
    public function createCheckpoint(string $name, array $data): string
    {
        $checkpointId = $this->generateCheckpointId($name);
        
        $checkpoint = [
            'id' => $checkpointId,
            'name' => $name,
            'migration_id' => $this->migrationId,
            'created_at' => date('Y-m-d H:i:s'),
            'data' => $data,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
        
        $this->checkpoints[$checkpointId] = $checkpoint;
        $this->saveCheckpoint($checkpointId, $checkpoint);
        
        return $checkpointId;
    }
    
    public function getCheckpoint(string $checkpointId): ?array
    {
        return $this->checkpoints[$checkpointId] ?? null;
    }
    
    public function getLatestCheckpoint(): ?array
    {
        if (empty($this->checkpoints)) {
            return null;
        }
        
        return end($this->checkpoints);
    }
    
    public function getAllCheckpoints(): array
    {
        return $this->checkpoints;
    }
    
    public function hasCheckpoints(): bool
    {
        return !empty($this->checkpoints);
    }
    
    public function removeCheckpoint(string $checkpointId): bool
    {
        if (!isset($this->checkpoints[$checkpointId])) {
            return false;
        }
        
        unset($this->checkpoints[$checkpointId]);
        
        $filePath = $this->getCheckpointFilePath($checkpointId);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        return true;
    }
    
    public function cleanup(): void
    {
        foreach ($this->checkpoints as $checkpointId => $checkpoint) {
            $this->removeCheckpoint($checkpointId);
        }
        
        $this->checkpoints = [];
    }
    
    public function canResumeFrom(string $checkpointId): bool
    {
        $checkpoint = $this->getCheckpoint($checkpointId);
        
        if (!$checkpoint) {
            return false;
        }
        
        // Check if checkpoint data is valid
        return isset($checkpoint['data']) && 
               isset($checkpoint['migration_id']) && 
               $checkpoint['migration_id'] === $this->migrationId;
    }
    
    public function resumeFrom(string $checkpointId): array
    {
        if (!$this->canResumeFrom($checkpointId)) {
            throw new \RuntimeException("Cannot resume from checkpoint: {$checkpointId}");
        }
        
        $checkpoint = $this->getCheckpoint($checkpointId);
        
        return [
            'checkpoint_id' => $checkpointId,
            'checkpoint_name' => $checkpoint['name'],
            'created_at' => $checkpoint['created_at'],
            'data' => $checkpoint['data'],
            'resumed_at' => date('Y-m-d H:i:s')
        ];
    }
    
    protected function loadExistingCheckpoints(): void
    {
        $pattern = $this->checkpointPath . '/' . $this->migrationId . '_*.json';
        $files = glob($pattern);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content) {
                $checkpoint = json_decode($content, true);
                if ($checkpoint && isset($checkpoint['id'])) {
                    $this->checkpoints[$checkpoint['id']] = $checkpoint;
                }
            }
        }
        
        // Sort by creation time
        uasort($this->checkpoints, function($a, $b) {
            return strtotime($a['created_at']) <=> strtotime($b['created_at']);
        });
    }
    
    protected function saveCheckpoint(string $checkpointId, array $checkpoint): void
    {
        $filePath = $this->getCheckpointFilePath($checkpointId);
        $content = json_encode($checkpoint, JSON_PRETTY_PRINT);
        
        if (file_put_contents($filePath, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to save checkpoint: {$checkpointId}");
        }
    }
    
    protected function getCheckpointFilePath(string $checkpointId): string
    {
        return $this->checkpointPath . '/' . $this->migrationId . '_' . $checkpointId . '.json';
    }
    
    protected function generateCheckpointId(string $name): string
    {
        return $name . '_' . date('His') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    public function getCheckpointSummary(): array
    {
        return [
            'migration_id' => $this->migrationId,
            'total_checkpoints' => count($this->checkpoints),
            'latest_checkpoint' => $this->getLatestCheckpoint()['name'] ?? null,
            'latest_checkpoint_time' => $this->getLatestCheckpoint()['created_at'] ?? null,
            'can_resume' => $this->hasCheckpoints()
        ];
    }
}