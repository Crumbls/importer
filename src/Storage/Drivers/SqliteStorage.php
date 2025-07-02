<?php

namespace Crumbls\Importer\Storage\Drivers;

use Crumbls\Importer\Contracts\TemporaryStorageContract;

class SqliteStorage implements TemporaryStorageContract
{
    protected array $config;
    protected ?\PDO $connection = null;
    protected array $headers = [];
    protected array $tableHeaders = []; // Store headers per table
    protected string $tableName = 'temp_import_data';
    protected string $dbPath;
    protected bool $created = false;
    protected array $createdTables = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'path' => storage_path('temp/import_storage'),
            'cleanup_after' => 3600,
            'table_name' => 'temp_import_data'
        ], $config);
        
        $this->tableName = $this->config['table_name'];
        $this->dbPath = $this->generateDbPath();
    }
    
    public function create(array $headers, string $table = 'data'): void
    {
        $this->tableHeaders[$table] = $headers;
        
        // For backward compatibility, also set default headers
        if ($table === 'data' || empty($this->headers)) {
            $this->headers = $headers;
        }
        
        $this->ensureConnection();
        $this->createTable($table, $headers);
        $this->created = true;
        $this->createdTables[] = $table;
    }
    
    public function insert(array $row, string $table = 'data'): bool
    {
        if (!$this->created) {
            return false;
        }
        
        try {
            $this->ensureConnection();
            
            $placeholders = str_repeat('?,', count($this->headers) - 1) . '?';
            $sql = "INSERT INTO {$this->tableName} VALUES ({$placeholders})";
            
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute(array_values($row));
            
        } catch (\PDOException $e) {
            error_log("SQLite insert failed: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("Unexpected error during SQLite insert: " . $e->getMessage());
            return false;
        }
    }
    
    public function insertBatch(array $rows, string $table = 'data'): int
    {
        if (!$this->created || empty($rows)) {
            return 0;
        }
        
        try {
            $this->ensureConnection();
            $this->connection->beginTransaction();
            
            $placeholders = str_repeat('?,', count($this->headers) - 1) . '?';
            $sql = "INSERT INTO {$this->tableName} VALUES ({$placeholders})";
            $stmt = $this->connection->prepare($sql);
            
            $inserted = 0;
            foreach ($rows as $row) {
                if ($stmt->execute(array_values($row))) {
                    $inserted++;
                }
            }
            
            $this->connection->commit();
            return $inserted;
            
        } catch (\PDOException $e) {
            if ($this->connection) {
                $this->connection->rollBack();
            }
            error_log("SQLite batch insert failed: " . $e->getMessage());
            return 0;
        } catch (\Exception $e) {
            if ($this->connection) {
                $this->connection->rollBack();
            }
            error_log("Unexpected error during SQLite batch insert: " . $e->getMessage());
            return 0;
        }
    }
    
    public function count(string $table = 'data'): int
    {
        if (!$this->created) {
            return 0;
        }
        
        try {
            $this->ensureConnection();
            $stmt = $this->connection->query("SELECT COUNT(*) FROM {$this->tableName}");
            return (int) $stmt->fetchColumn();
            
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    public function getHeaders(string $table = 'data'): array
    {
        return $this->headers;
    }
    
    public function chunk(int $size, callable $callback, string $table = 'data'): void
    {
        if (!$this->created) {
            return;
        }
        
        try {
            $this->ensureConnection();
            $offset = 0;
            
            while (true) {
                $stmt = $this->connection->prepare("SELECT * FROM {$this->tableName} LIMIT ? OFFSET ?");
                $stmt->execute([$size, $offset]);
                $chunk = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                if (empty($chunk)) {
                    break;
                }
                
                $callback($chunk);
                $offset += $size;
            }
            
        } catch (\Exception $e) {
            return;
        }
    }
    
    public function all(string $table = 'data'): \Generator
    {
        if (!$this->created) {
            return;
        }
        
        try {
            $this->ensureConnection();
            $stmt = $this->connection->query("SELECT * FROM {$this->tableName}");
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                yield $row;
            }
            
        } catch (\Exception $e) {
            return;
        }
    }
    
    public function first(int $limit = 1, string $table = 'data'): array
    {
        if (!$this->created) {
            return [];
        }
        
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare("SELECT * FROM {$this->tableName} LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function exists(string $table = 'data'): bool
    {
        return $this->created && file_exists($this->dbPath);
    }
    
    public function destroy(string $table = null): bool
    {
        try {
            if ($this->connection) {
                $this->connection = null;
            }
            
            if (file_exists($this->dbPath)) {
                unlink($this->dbPath);
            }
            
            $this->created = false;
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getStorageInfo(): array
    {
        return [
            'driver' => 'sqlite',
            'db_path' => $this->dbPath,
            'db_size' => file_exists($this->dbPath) ? filesize($this->dbPath) : 0,
            'row_count' => $this->count(),
            'created' => $this->created,
            'headers_count' => count($this->headers),
            'table_name' => $this->tableName
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
        
        $this->connection = new \PDO("sqlite:{$this->dbPath}");
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
    
    protected function createTable(): void
    {
        $columns = [];
        foreach ($this->headers as $header) {
            $columnName = preg_replace('/[^a-zA-Z0-9_]/', '_', $header);
            $columns[] = "`{$columnName}` TEXT";
        }
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (" . implode(', ', $columns) . ")";
        $this->connection->exec($sql);
    }
    
    protected function generateDbPath(): string
    {
        $basePath = $this->config['path'];
        $filename = 'import_' . uniqid() . '_' . time() . '.sqlite';
        
        return $basePath . '/' . $filename;
    }
    
    public function getTables(): array
    {
        return $this->created ? ['data'] : [];
    }
    
    public function createMultipleTables(array $schemas): void
    {
        // SqliteStorage only supports single table
        // Just create with the first schema for backward compatibility
        if (!empty($schemas)) {
            $firstTable = array_key_first($schemas);
            $this->create($schemas[$firstTable], $firstTable);
        }
    }
}