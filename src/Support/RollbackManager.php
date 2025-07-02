<?php

namespace Crumbls\Importer\Support;

use Exception;
use PDO;

class RollbackManager
{
    protected string $rollbackDir;
    protected string $migrationId;
    protected array $operations = [];
    protected bool $transactionMode = false;
    protected ?PDO $pdo = null;
    protected array $rollbackStrategies = [];
    
    public function __construct(string $migrationId, array $config = [])
    {
        $this->migrationId = $migrationId;
        $this->rollbackDir = $config['rollback_dir'] ?? sys_get_temp_dir() . '/migration_rollbacks';
        $this->transactionMode = $config['transaction_mode'] ?? false;
        
        $this->ensureRollbackDirectory();
        $this->initializeStrategies();
    }
    
    public function startRollbackTracking(?PDO $pdo = null): void
    {
        $this->pdo = $pdo;
        $this->operations = [];
        
        if ($this->transactionMode && $this->pdo) {
            $this->pdo->beginTransaction();
        }
        
        $this->saveRollbackMetadata([
            'started_at' => time(),
            'migration_id' => $this->migrationId,
            'transaction_mode' => $this->transactionMode,
            'initial_state' => $this->captureInitialState()
        ]);
    }
    
    public function trackOperation(string $type, array $data, ?string $table = null): void
    {
        $operation = [
            'id' => uniqid(),
            'timestamp' => microtime(true),
            'type' => $type,
            'table' => $table,
            'data' => $data,
            'rollback_data' => $this->prepareRollbackData($type, $data, $table)
        ];
        
        $this->operations[] = $operation;
        
        // Periodically save operations to disk
        if (count($this->operations) % 100 === 0) {
            $this->saveOperationsToFile();
        }
    }
    
    public function trackInsert(string $table, array $data, $insertedId = null): void
    {
        $this->trackOperation('insert', [
            'table' => $table,
            'data' => $data,
            'inserted_id' => $insertedId,
            'primary_key' => $this->guessPrimaryKey($table)
        ], $table);
    }
    
    public function trackUpdate(string $table, array $newData, array $whereConditions, ?array $oldData = null): void
    {
        // If old data not provided, try to fetch it
        if ($oldData === null && $this->pdo) {
            $oldData = $this->fetchOldData($table, $whereConditions);
        }
        
        $this->trackOperation('update', [
            'table' => $table,
            'new_data' => $newData,
            'old_data' => $oldData,
            'where_conditions' => $whereConditions
        ], $table);
    }
    
    public function trackDelete(string $table, array $whereConditions, ?array $deletedData = null): void
    {
        // If deleted data not provided, try to fetch it before deletion
        if ($deletedData === null && $this->pdo) {
            $deletedData = $this->fetchOldData($table, $whereConditions);
        }
        
        $this->trackOperation('delete', [
            'table' => $table,
            'deleted_data' => $deletedData,
            'where_conditions' => $whereConditions
        ], $table);
    }
    
    public function createRollbackPoint(string $name): string
    {
        $rollbackPoint = [
            'id' => uniqid('rollback_'),
            'name' => $name,
            'timestamp' => time(),
            'operations_count' => count($this->operations),
            'memory_usage' => memory_get_usage(true)
        ];
        
        $this->saveRollbackPoint($rollbackPoint);
        
        return $rollbackPoint['id'];
    }
    
    public function executeRollback(?string $rollbackPointId = null): array
    {
        if ($this->transactionMode && $this->pdo) {
            // Simple transaction rollback
            $this->pdo->rollBack();
            return [
                'success' => true,
                'method' => 'transaction_rollback',
                'operations_rolled_back' => count($this->operations)
            ];
        }
        
        // Manual rollback by reversing operations
        return $this->executeManualRollback($rollbackPointId);
    }
    
    public function executePartialRollback(array $operationIds): array
    {
        $operationsToRollback = array_filter(
            $this->operations,
            fn($op) => in_array($op['id'], $operationIds)
        );
        
        if (empty($operationsToRollback)) {
            return [
                'success' => false,
                'error' => 'No operations found for rollback',
                'requested_operations' => $operationIds
            ];
        }
        
        return $this->rollbackOperations($operationsToRollback);
    }
    
    public function getRollbackPlan(?string $rollbackPointId = null): array
    {
        $operations = $rollbackPointId 
            ? $this->getOperationsSinceRollbackPoint($rollbackPointId)
            : $this->operations;
        
        if (empty($operations)) {
            return [
                'operations_count' => 0,
                'rollback_steps' => [],
                'estimated_duration' => 0,
                'complexity' => 'none'
            ];
        }
        
        $reversedOps = array_reverse($operations);
        $rollbackSteps = [];
        $estimatedDuration = 0;
        
        foreach ($reversedOps as $operation) {
            $step = $this->generateRollbackStep($operation);
            $rollbackSteps[] = $step;
            $estimatedDuration += $step['estimated_duration'];
        }
        
        return [
            'operations_count' => count($operations),
            'rollback_steps' => $rollbackSteps,
            'estimated_duration' => $estimatedDuration,
            'complexity' => $this->assessRollbackComplexity($operations),
            'warnings' => $this->generateRollbackWarnings($operations),
            'recommendations' => $this->generateRollbackRecommendations($operations)
        ];
    }
    
    public function canRollback(): bool
    {
        if ($this->transactionMode && $this->pdo) {
            return $this->pdo->inTransaction();
        }
        
        return !empty($this->operations) || $this->hasStoredOperations();
    }
    
    public function getRollbackStatistics(): array
    {
        $operationCounts = [];
        $tableCounts = [];
        $totalSize = 0;
        
        foreach ($this->operations as $operation) {
            $type = $operation['type'];
            $table = $operation['table'] ?? 'unknown';
            
            $operationCounts[$type] = ($operationCounts[$type] ?? 0) + 1;
            $tableCounts[$table] = ($tableCounts[$table] ?? 0) + 1;
            $totalSize += strlen(serialize($operation['data']));
        }
        
        return [
            'total_operations' => count($this->operations),
            'operations_by_type' => $operationCounts,
            'operations_by_table' => $tableCounts,
            'data_size_bytes' => $totalSize,
            'memory_usage' => memory_get_usage(true),
            'can_rollback' => $this->canRollback(),
            'rollback_method' => $this->transactionMode ? 'transaction' : 'manual',
            'complexity_score' => $this->calculateComplexityScore()
        ];
    }
    
    public function cleanupRollbackData(): int
    {
        $cleaned = 0;
        
        // Remove operation files
        $pattern = $this->rollbackDir . '/' . $this->migrationId . '_operations_*.json';
        foreach (glob($pattern) as $file) {
            if (unlink($file)) {
                $cleaned++;
            }
        }
        
        // Remove rollback point files
        $pattern = $this->rollbackDir . '/' . $this->migrationId . '_rollback_point_*.json';
        foreach (glob($pattern) as $file) {
            if (unlink($file)) {
                $cleaned++;
            }
        }
        
        // Remove metadata file
        $metadataFile = $this->getRollbackMetadataPath();
        if (file_exists($metadataFile) && unlink($metadataFile)) {
            $cleaned++;
        }
        
        return $cleaned;
    }
    
    protected function executeManualRollback(?string $rollbackPointId = null): array
    {
        $operations = $rollbackPointId 
            ? $this->getOperationsSinceRollbackPoint($rollbackPointId)
            : $this->loadAllOperations();
        
        if (empty($operations)) {
            return [
                'success' => false,
                'error' => 'No operations to rollback'
            ];
        }
        
        return $this->rollbackOperations($operations);
    }
    
    protected function rollbackOperations(array $operations): array
    {
        $reversedOps = array_reverse($operations);
        $successful = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($reversedOps as $operation) {
            try {
                $this->rollbackSingleOperation($operation);
                $successful++;
            } catch (Exception $e) {
                $failed++;
                $errors[] = [
                    'operation_id' => $operation['id'],
                    'error' => $e->getMessage(),
                    'operation_type' => $operation['type']
                ];
                
                // Decide whether to continue or abort
                if ($this->shouldAbortRollback($e, $operation)) {
                    break;
                }
            }
        }
        
        return [
            'success' => $failed === 0,
            'operations_processed' => $successful + $failed,
            'successful_rollbacks' => $successful,
            'failed_rollbacks' => $failed,
            'errors' => $errors
        ];
    }
    
    protected function rollbackSingleOperation(array $operation): void
    {
        if (!$this->pdo) {
            throw new Exception("Database connection required for manual rollback");
        }
        
        switch ($operation['type']) {
            case 'insert':
                $this->rollbackInsert($operation);
                break;
            case 'update':
                $this->rollbackUpdate($operation);
                break;
            case 'delete':
                $this->rollbackDelete($operation);
                break;
            default:
                throw new Exception("Unknown operation type: {$operation['type']}");
        }
    }
    
    protected function rollbackInsert(array $operation): void
    {
        $data = $operation['data'];
        $table = $data['table'];
        $primaryKey = $data['primary_key'] ?? 'id';
        $insertedId = $data['inserted_id'];
        
        if ($insertedId) {
            $sql = "DELETE FROM `{$table}` WHERE `{$primaryKey}` = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$insertedId]);
        }
    }
    
    protected function rollbackUpdate(array $operation): void
    {
        $data = $operation['data'];
        $table = $data['table'];
        $oldData = $data['old_data'];
        $whereConditions = $data['where_conditions'];
        
        if ($oldData) {
            $setParts = [];
            $values = [];
            
            foreach ($oldData as $column => $value) {
                $setParts[] = "`{$column}` = ?";
                $values[] = $value;
            }
            
            $whereParts = [];
            foreach ($whereConditions as $column => $value) {
                $whereParts[] = "`{$column}` = ?";
                $values[] = $value;
            }
            
            $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . 
                   " WHERE " . implode(' AND ', $whereParts);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
        }
    }
    
    protected function rollbackDelete(array $operation): void
    {
        $data = $operation['data'];
        $table = $data['table'];
        $deletedData = $data['deleted_data'];
        
        if ($deletedData) {
            foreach ($deletedData as $row) {
                $columns = array_keys($row);
                $placeholders = array_fill(0, count($columns), '?');
                
                $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . 
                       "`) VALUES (" . implode(', ', $placeholders) . ")";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_values($row));
            }
        }
    }
    
    protected function prepareRollbackData(string $type, array $data, ?string $table): array
    {
        // This method prepares additional data needed for rollback
        // Implementation depends on the specific operation type
        return [
            'prepared_at' => time(),
            'rollback_strategy' => $this->determineRollbackStrategy($type, $table)
        ];
    }
    
    protected function fetchOldData(string $table, array $whereConditions): ?array
    {
        if (!$this->pdo) {
            return null;
        }
        
        $whereParts = [];
        $values = [];
        
        foreach ($whereConditions as $column => $value) {
            $whereParts[] = "`{$column}` = ?";
            $values[] = $value;
        }
        
        $sql = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $whereParts);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    protected function guessPrimaryKey(string $table): string
    {
        // Common primary key patterns in WordPress
        $commonKeys = ['ID', 'id', $table . '_id', 'term_id', 'user_id', 'comment_id'];
        
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    return $result['Column_name'];
                }
            } catch (Exception $e) {
                // Fall back to common patterns
            }
        }
        
        return $commonKeys[0]; // Default to 'ID'
    }
    
    protected function captureInitialState(): array
    {
        return [
            'timestamp' => time(),
            'memory_usage' => memory_get_usage(true),
            'database_info' => $this->pdo ? $this->getDatabaseInfo() : null
        ];
    }
    
    protected function getDatabaseInfo(): array
    {
        if (!$this->pdo) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = DATABASE()");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'table_count' => $result['table_count'] ?? 0,
                'database_name' => $this->pdo->query("SELECT DATABASE()")->fetchColumn()
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    protected function generateRollbackStep(array $operation): array
    {
        $baseTime = 0.1; // Base time per operation in seconds
        
        return [
            'operation_id' => $operation['id'],
            'type' => $operation['type'],
            'table' => $operation['table'],
            'description' => $this->generateStepDescription($operation),
            'estimated_duration' => $baseTime,
            'complexity' => $this->assessOperationComplexity($operation),
            'risks' => $this->identifyOperationRisks($operation)
        ];
    }
    
    protected function generateStepDescription(array $operation): string
    {
        switch ($operation['type']) {
            case 'insert':
                return "Delete inserted record from {$operation['table']}";
            case 'update':
                return "Restore original values in {$operation['table']}";
            case 'delete':
                return "Restore deleted records to {$operation['table']}";
            default:
                return "Rollback {$operation['type']} operation";
        }
    }
    
    protected function shouldAbortRollback(Exception $error, array $operation): bool
    {
        // Define when to abort rollback process
        $fatalErrors = [
            'table doesn\'t exist',
            'unknown column',
            'access denied'
        ];
        
        $message = strtolower($error->getMessage());
        foreach ($fatalErrors as $fatalError) {
            if (strpos($message, $fatalError) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    protected function assessRollbackComplexity(array $operations): string
    {
        $count = count($operations);
        $types = array_unique(array_column($operations, 'type'));
        
        if ($count === 0) return 'none';
        if ($count < 10 && count($types) === 1) return 'simple';
        if ($count < 100) return 'moderate';
        if ($count < 1000) return 'complex';
        return 'very_complex';
    }
    
    protected function calculateComplexityScore(): float
    {
        $score = count($this->operations) * 0.1; // Base complexity
        
        $types = array_count_values(array_column($this->operations, 'type'));
        $score += count($types) * 0.5; // Multiple operation types add complexity
        
        return round($score, 2);
    }
    
    protected function ensureRollbackDirectory(): void
    {
        if (!is_dir($this->rollbackDir)) {
            if (!mkdir($this->rollbackDir, 0755, true)) {
                throw new Exception("Failed to create rollback directory: {$this->rollbackDir}");
            }
        }
    }
    
    protected function initializeStrategies(): void
    {
        $this->rollbackStrategies = [
            'insert' => 'delete_by_id',
            'update' => 'restore_original_values',
            'delete' => 'reinsert_deleted_data'
        ];
    }
    
    protected function determineRollbackStrategy(string $type, ?string $table): string
    {
        return $this->rollbackStrategies[$type] ?? 'unknown';
    }
    
    // Additional helper methods for file operations
    protected function saveOperationsToFile(): void
    {
        $filename = $this->migrationId . '_operations_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = $this->rollbackDir . '/' . $filename;
        
        file_put_contents($filepath, json_encode($this->operations, JSON_PRETTY_PRINT));
    }
    
    protected function saveRollbackMetadata(array $metadata): void
    {
        $filepath = $this->getRollbackMetadataPath();
        file_put_contents($filepath, json_encode($metadata, JSON_PRETTY_PRINT));
    }
    
    protected function saveRollbackPoint(array $rollbackPoint): void
    {
        $filename = $this->migrationId . '_rollback_point_' . $rollbackPoint['id'] . '.json';
        $filepath = $this->rollbackDir . '/' . $filename;
        
        file_put_contents($filepath, json_encode($rollbackPoint, JSON_PRETTY_PRINT));
    }
    
    protected function getRollbackMetadataPath(): string
    {
        return $this->rollbackDir . '/' . $this->migrationId . '_metadata.json';
    }
    
    protected function loadAllOperations(): array
    {
        $operations = $this->operations;
        
        // Load operations from files if any
        $pattern = $this->rollbackDir . '/' . $this->migrationId . '_operations_*.json';
        foreach (glob($pattern) as $file) {
            $content = file_get_contents($file);
            if ($content) {
                $fileOps = json_decode($content, true);
                if (is_array($fileOps)) {
                    $operations = array_merge($operations, $fileOps);
                }
            }
        }
        
        return $operations;
    }
    
    protected function hasStoredOperations(): bool
    {
        $pattern = $this->rollbackDir . '/' . $this->migrationId . '_operations_*.json';
        return !empty(glob($pattern));
    }
    
    protected function getOperationsSinceRollbackPoint(string $rollbackPointId): array
    {
        // Implementation would filter operations after the rollback point
        // For now, return all operations
        return $this->operations;
    }
    
    protected function generateRollbackWarnings(array $operations): array
    {
        $warnings = [];
        
        if (count($operations) > 1000) {
            $warnings[] = 'Large number of operations - rollback may take significant time';
        }
        
        $tables = array_unique(array_column($operations, 'table'));
        if (count($tables) > 10) {
            $warnings[] = 'Multiple tables affected - ensure referential integrity';
        }
        
        return $warnings;
    }
    
    protected function generateRollbackRecommendations(array $operations): array
    {
        $recommendations = [];
        
        if ($this->transactionMode) {
            $recommendations[] = 'Using transaction mode - rollback will be atomic';
        } else {
            $recommendations[] = 'Manual rollback mode - create database backup before proceeding';
        }
        
        if (count($operations) > 100) {
            $recommendations[] = 'Consider partial rollback if only recent changes need to be reverted';
        }
        
        return $recommendations;
    }
    
    protected function assessOperationComplexity(array $operation): string
    {
        switch ($operation['type']) {
            case 'insert':
                return 'low';
            case 'update':
                return isset($operation['data']['old_data']) ? 'low' : 'medium';
            case 'delete':
                return isset($operation['data']['deleted_data']) ? 'medium' : 'high';
            default:
                return 'unknown';
        }
    }
    
    protected function identifyOperationRisks(array $operation): array
    {
        $risks = [];
        
        if ($operation['type'] === 'delete' && !isset($operation['data']['deleted_data'])) {
            $risks[] = 'Data may be permanently lost - no backup of deleted records';
        }
        
        if ($operation['type'] === 'update' && !isset($operation['data']['old_data'])) {
            $risks[] = 'Original values unknown - cannot guarantee complete restoration';
        }
        
        return $risks;
    }
}