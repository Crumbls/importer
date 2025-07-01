<?php

namespace Crumbls\Importer\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackupManager
{
    protected array $config;
    protected string $migrationId;
    protected array $backupLog = [];
    
    public function __construct(string $migrationId, array $config = [])
    {
        $this->migrationId = $migrationId;
        $this->config = array_merge([
            'strategy' => 'incremental', // full, incremental, schema_only
            'storage_disk' => 'local',
            'backup_path' => 'backups/migrations',
            'retention_days' => 30,
            'compress' => true,
            'verify_backup' => true,
            'max_backup_size' => '1GB'
        ], $config);
    }
    
    public function createPreMigrationBackup(array $tables): array
    {
        $backupId = $this->generateBackupId('pre_migration');
        $backupPath = $this->getBackupPath($backupId);
        
        try {
            $this->log("Creating pre-migration backup: {$backupId}");
            
            $backup = [
                'id' => $backupId,
                'type' => 'pre_migration',
                'migration_id' => $this->migrationId,
                'created_at' => date('Y-m-d H:i:s'),
                'tables' => [],
                'files' => [],
                'strategy' => $this->config['strategy']
            ];
            
            foreach ($tables as $table) {
                $tableBackup = $this->backupTable($table, $backupPath);
                $backup['tables'][$table] = $tableBackup;
            }
            
            // Create backup manifest
            $manifestPath = $backupPath . '/manifest.json';
            $this->storeFile($manifestPath, json_encode($backup, JSON_PRETTY_PRINT));
            
            if ($this->config['verify_backup']) {
                $this->verifyBackup($backup);
            }
            
            $this->log("Pre-migration backup completed: {$backupId}");
            
            return $backup;
            
        } catch (\Exception $e) {
            $this->log("Backup failed: " . $e->getMessage(), 'error');
            throw new \RuntimeException("Pre-migration backup failed: " . $e->getMessage());
        }
    }
    
    public function createIncrementalBackup(array $changedData): array
    {
        $backupId = $this->generateBackupId('incremental');
        $backupPath = $this->getBackupPath($backupId);
        
        try {
            $this->log("Creating incremental backup: {$backupId}");
            
            $backup = [
                'id' => $backupId,
                'type' => 'incremental',
                'migration_id' => $this->migrationId,
                'created_at' => date('Y-m-d H:i:s'),
                'changes' => [],
                'rollback_script' => null
            ];
            
            // Store changed data
            foreach ($changedData as $entityType => $records) {
                $changeFile = $backupPath . "/{$entityType}_changes.json";
                $this->storeFile($changeFile, json_encode($records, JSON_PRETTY_PRINT));
                
                $backup['changes'][$entityType] = [
                    'file' => $changeFile,
                    'record_count' => count($records),
                    'size' => strlen(json_encode($records))
                ];
            }
            
            // Generate rollback script
            $rollbackScript = $this->generateRollbackScript($changedData);
            $scriptPath = $backupPath . '/rollback.sql';
            $this->storeFile($scriptPath, $rollbackScript);
            $backup['rollback_script'] = $scriptPath;
            
            // Create backup manifest
            $manifestPath = $backupPath . '/manifest.json';
            $this->storeFile($manifestPath, json_encode($backup, JSON_PRETTY_PRINT));
            
            $this->log("Incremental backup completed: {$backupId}");
            
            return $backup;
            
        } catch (\Exception $e) {
            $this->log("Incremental backup failed: " . $e->getMessage(), 'error');
            throw new \RuntimeException("Incremental backup failed: " . $e->getMessage());
        }
    }
    
    public function restoreFromBackup(string $backupId): bool
    {
        try {
            $this->log("Starting restore from backup: {$backupId}");
            
            $backup = $this->loadBackup($backupId);
            
            if (!$backup) {
                throw new \RuntimeException("Backup not found: {$backupId}");
            }
            
            switch ($backup['type']) {
                case 'pre_migration':
                    return $this->restoreFullBackup($backup);
                case 'incremental':
                    return $this->restoreIncrementalBackup($backup);
                default:
                    throw new \RuntimeException("Unknown backup type: {$backup['type']}");
            }
            
        } catch (\Exception $e) {
            $this->log("Restore failed: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    public function generateRollbackScript(array $changedData): string
    {
        $script = [];
        $script[] = "-- Rollback script for migration: {$this->migrationId}";
        $script[] = "-- Generated at: " . date('Y-m-d H:i:s');
        $script[] = "-- WARNING: Review this script before execution!";
        $script[] = "";
        $script[] = "START TRANSACTION;";
        $script[] = "";
        
        foreach ($changedData as $entityType => $changes) {
            $script[] = "-- Rollback {$entityType}";
            
            foreach ($changes as $change) {
                switch ($change['action']) {
                    case 'insert':
                        // Delete inserted records
                        $table = $change['table'];
                        $id = $change['record']['id'] ?? null;
                        if ($id) {
                            $script[] = "DELETE FROM {$table} WHERE id = {$id};";
                        }
                        break;
                        
                    case 'update':
                        // Restore original values
                        $table = $change['table'];
                        $id = $change['record']['id'] ?? null;
                        $original = $change['original'] ?? [];
                        
                        if ($id && !empty($original)) {
                            $sets = [];
                            foreach ($original as $field => $value) {
                                $sets[] = "{$field} = " . (is_null($value) ? 'NULL' : "'" . addslashes($value) . "'");
                            }
                            $script[] = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = {$id};";
                        }
                        break;
                        
                    case 'delete':
                        // Restore deleted records
                        $table = $change['table'];
                        $record = $change['record'] ?? [];
                        
                        if (!empty($record)) {
                            $fields = array_keys($record);
                            $values = array_map(function($value) {
                                return is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
                            }, array_values($record));
                            
                            $script[] = "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ");";
                        }
                        break;
                }
            }
            
            $script[] = "";
        }
        
        $script[] = "COMMIT;";
        $script[] = "-- End of rollback script";
        
        return implode("\n", $script);
    }
    
    public function listBackups(): array
    {
        $backups = [];
        $backupPattern = $this->config['backup_path'] . '/' . $this->migrationId . '_*';
        
        try {
            $storage = Storage::disk($this->config['storage_disk']);
            $directories = $storage->directories($this->config['backup_path']);
            
            foreach ($directories as $dir) {
                if (str_contains($dir, $this->migrationId)) {
                    $manifestPath = $dir . '/manifest.json';
                    if ($storage->exists($manifestPath)) {
                        $manifest = json_decode($storage->get($manifestPath), true);
                        if ($manifest) {
                            $backups[] = $manifest;
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->log("Failed to list backups: " . $e->getMessage(), 'error');
        }
        
        // Sort by creation date (newest first)
        usort($backups, function($a, $b) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });
        
        return $backups;
    }
    
    public function cleanupOldBackups(): int
    {
        $cleaned = 0;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->config['retention_days']} days"));
        
        try {
            $backups = $this->listBackups();
            
            foreach ($backups as $backup) {
                if ($backup['created_at'] < $cutoffDate) {
                    if ($this->deleteBackup($backup['id'])) {
                        $cleaned++;
                        $this->log("Cleaned up old backup: {$backup['id']}");
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->log("Cleanup failed: " . $e->getMessage(), 'error');
        }
        
        return $cleaned;
    }
    
    public function verifyBackup(array $backup): bool
    {
        try {
            $this->log("Verifying backup: {$backup['id']}");
            
            // Check if all files exist
            foreach ($backup['tables'] ?? [] as $table => $tableBackup) {
                if (!$this->fileExists($tableBackup['file'])) {
                    throw new \RuntimeException("Missing backup file for table: {$table}");
                }
            }
            
            // Verify file integrity (if compressed)
            if ($this->config['compress']) {
                // Add compression verification logic here
            }
            
            $this->log("Backup verification passed: {$backup['id']}");
            return true;
            
        } catch (\Exception $e) {
            $this->log("Backup verification failed: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    protected function backupTable(string $table, string $backupPath): array
    {
        $this->log("Backing up table: {$table}");
        
        // Get table structure
        $structure = DB::select("SHOW CREATE TABLE {$table}");
        $createStatement = $structure[0]->{'Create Table'} ?? '';
        
        // Get table data
        $data = DB::table($table)->get()->toArray();
        
        // Store structure
        $structureFile = $backupPath . "/{$table}_structure.sql";
        $this->storeFile($structureFile, $createStatement);
        
        // Store data
        $dataFile = $backupPath . "/{$table}_data.json";
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        $this->storeFile($dataFile, $jsonData);
        
        return [
            'table' => $table,
            'structure_file' => $structureFile,
            'data_file' => $dataFile,
            'record_count' => count($data),
            'data_size' => strlen($jsonData),
            'backed_up_at' => date('Y-m-d H:i:s')
        ];
    }
    
    protected function restoreFullBackup(array $backup): bool
    {
        $this->log("Restoring full backup: {$backup['id']}");
        
        try {
            DB::beginTransaction();
            
            foreach ($backup['tables'] as $table => $tableBackup) {
                $this->restoreTable($table, $tableBackup);
            }
            
            DB::commit();
            $this->log("Full backup restore completed: {$backup['id']}");
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->log("Full backup restore failed: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    protected function restoreIncrementalBackup(array $backup): bool
    {
        $this->log("Restoring incremental backup: {$backup['id']}");
        
        try {
            if (!empty($backup['rollback_script'])) {
                $script = $this->getFileContents($backup['rollback_script']);
                DB::unprepared($script);
            }
            
            $this->log("Incremental backup restore completed: {$backup['id']}");
            return true;
            
        } catch (\Exception $e) {
            $this->log("Incremental backup restore failed: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    protected function restoreTable(string $table, array $tableBackup): void
    {
        // Truncate existing table
        DB::table($table)->truncate();
        
        // Restore data
        $dataContent = $this->getFileContents($tableBackup['data_file']);
        $data = json_decode($dataContent, true);
        
        if (!empty($data)) {
            // Insert in chunks to avoid memory issues
            $chunks = array_chunk($data, 1000);
            foreach ($chunks as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }
    
    protected function loadBackup(string $backupId): ?array
    {
        $manifestPath = $this->getBackupPath($backupId) . '/manifest.json';
        
        if (!$this->fileExists($manifestPath)) {
            return null;
        }
        
        $content = $this->getFileContents($manifestPath);
        return json_decode($content, true);
    }
    
    protected function deleteBackup(string $backupId): bool
    {
        try {
            $backupPath = $this->getBackupPath($backupId);
            $storage = Storage::disk($this->config['storage_disk']);
            
            return $storage->deleteDirectory($backupPath);
            
        } catch (\Exception $e) {
            $this->log("Failed to delete backup {$backupId}: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    protected function generateBackupId(string $type): string
    {
        return $this->migrationId . '_' . $type . '_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    protected function getBackupPath(string $backupId): string
    {
        return trim($this->config['backup_path'], '/') . '/' . $backupId;
    }
    
    protected function storeFile(string $path, string $content): void
    {
        $storage = Storage::disk($this->config['storage_disk']);
        
        if ($this->config['compress'] && strlen($content) > 1024) {
            $content = gzcompress($content);
            $path .= '.gz';
        }
        
        $storage->put($path, $content);
    }
    
    protected function getFileContents(string $path): string
    {
        $storage = Storage::disk($this->config['storage_disk']);
        $content = $storage->get($path);
        
        if (str_ends_with($path, '.gz')) {
            $content = gzuncompress($content);
        }
        
        return $content;
    }
    
    protected function fileExists(string $path): bool
    {
        $storage = Storage::disk($this->config['storage_disk']);
        return $storage->exists($path);
    }
    
    protected function log(string $message, string $level = 'info'): void
    {
        $this->backupLog[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message
        ];
    }
    
    public function getBackupLog(): array
    {
        return $this->backupLog;
    }
}