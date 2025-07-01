<?php

namespace Crumbls\Importer\Storage\Drivers;

use Crumbls\Importer\Contracts\TemporaryStorageContract;

class MultiTableSqliteStorage implements TemporaryStorageContract
{
    protected array $config;
    protected ?\PDO $connection = null;
    protected array $tableHeaders = [];
    protected string $dbPath;
    protected array $createdTables = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'path' => storage_path('temp/import_storage'),
            'cleanup_after' => 3600
        ], $config);
        
        $this->dbPath = $this->generateDbPath();
    }
    
    public function create(array $headers, string $table = 'data'): void
    {
        $this->tableHeaders[$table] = $headers;
        $this->ensureConnection();
        $this->createTable($table, $headers);
        $this->createdTables[] = $table;
    }
    
    public function createMultipleTables(array $schemas): void
    {
        foreach ($schemas as $table => $headers) {
            $this->create($headers, $table);
        }
    }
    
    public function insert(array $row, string $table = 'data'): bool
    {
        if (!isset($this->tableHeaders[$table])) {
            return false;
        }
        
        try {
            $this->ensureConnection();
            
            $headers = $this->tableHeaders[$table];
            $placeholders = str_repeat('?,', count($headers) - 1) . '?';
            $sql = "INSERT INTO {$table} VALUES ({$placeholders})";
            
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute(array_values($row));
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function insertBatch(array $rows, string $table = 'data'): int
    {
        if (!isset($this->tableHeaders[$table]) || empty($rows)) {
            return 0;
        }
        
        try {
            $this->ensureConnection();
            $this->connection->beginTransaction();
            
            $headers = $this->tableHeaders[$table];
            $placeholders = str_repeat('?,', count($headers) - 1) . '?';
            $sql = "INSERT INTO {$table} VALUES ({$placeholders})";
            $stmt = $this->connection->prepare($sql);
            
            $inserted = 0;
            foreach ($rows as $row) {
                if ($stmt->execute(array_values($row))) {
                    $inserted++;
                }
            }
            
            $this->connection->commit();
            return $inserted;
            
        } catch (\Exception $e) {
            if ($this->connection) {
                $this->connection->rollBack();
            }
            return 0;
        }
    }
    
    public function count(string $table = 'data'): int
    {
        if (!isset($this->tableHeaders[$table])) {
            return 0;
        }
        
        try {
            $this->ensureConnection();
            $stmt = $this->connection->query("SELECT COUNT(*) FROM {$table}");
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    public function getHeaders(string $table = 'data'): array
    {
        return $this->tableHeaders[$table] ?? [];
    }
    
    public function chunk(int $size, callable $callback, string $table = 'data'): void
    {
        if (!isset($this->tableHeaders[$table])) {
            return;
        }
        
        try {
            $this->ensureConnection();
            $offset = 0;
            
            do {
                $stmt = $this->connection->prepare("SELECT * FROM {$table} LIMIT ? OFFSET ?");
                $stmt->execute([$size, $offset]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $callback($rows);
                    $offset += $size;
                }
            } while (count($rows) === $size);
            
        } catch (\Exception $e) {
            // Handle error silently or log
        }
    }
    
    public function all(string $table = 'data'): \Generator
    {
        if (!isset($this->tableHeaders[$table])) {
            return;
        }
        
        try {
            $this->ensureConnection();
            $stmt = $this->connection->query("SELECT * FROM {$table}");
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } catch (\Exception $e) {
            return;
        }
    }
    
    public function first(int $limit = 1, string $table = 'data'): array
    {
        if (!isset($this->tableHeaders[$table])) {
            return [];
        }
        
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare("SELECT * FROM {$table} LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function exists(string $table = 'data'): bool
    {
        return isset($this->tableHeaders[$table]);
    }
    
    public function getTables(): array
    {
        return array_keys($this->tableHeaders);
    }
    
    public function destroy(string $table = null): bool
    {
        try {
            if ($table) {
                // Drop specific table
                if (isset($this->tableHeaders[$table])) {
                    $this->ensureConnection();
                    $this->connection->exec("DROP TABLE IF EXISTS {$table}");
                    unset($this->tableHeaders[$table]);
                    $this->createdTables = array_diff($this->createdTables, [$table]);
                }
            } else {
                // Destroy entire database
                if ($this->connection) {
                    $this->connection = null;
                }
                
                if (file_exists($this->dbPath)) {
                    unlink($this->dbPath);
                }
                
                $this->tableHeaders = [];
                $this->createdTables = [];
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getStorageInfo(): array
    {
        return [
            'type' => 'multi_table_sqlite',
            'path' => $this->dbPath,
            'tables' => $this->getTables(),
            'total_tables' => count($this->tableHeaders),
            'size' => file_exists($this->dbPath) ? filesize($this->dbPath) : 0
        ];
    }
    
    protected function ensureConnection(): void
    {
        if ($this->connection) {
            return;
        }
        
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $this->connection = new \PDO('sqlite:' . $this->dbPath);
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
    
    protected function createTable(string $table, array $headers): void
    {
        $columns = [];
        foreach ($headers as $header) {
            $columns[] = "`{$header}` TEXT";
        }
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $columns) . ")";
        $this->connection->exec($sql);
    }
    
    protected function generateDbPath(): string
    {
        $dir = $this->config['path'];
        $filename = 'import_' . uniqid() . '.sqlite';
        return $dir . '/' . $filename;
    }
}