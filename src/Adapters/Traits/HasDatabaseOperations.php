<?php

namespace Crumbls\Importer\Adapters\Traits;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

trait HasDatabaseOperations
{
    protected ?DB $db = null;
    
    protected function getDatabase(): DB
    {
        if ($this->db === null) {
            $this->db = new DB;
            $connection = $this->getConnection();
            
            if (is_array($connection)) {
                // Production MySQL connection
                $this->db->addConnection([
                    'driver' => 'mysql',
                    'host' => $connection['host'] ?? 'localhost',
                    'port' => $connection['port'] ?? 3306,
                    'database' => $connection['database'] ?? '',
                    'username' => $connection['username'] ?? '',
                    'password' => $connection['password'] ?? '',
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ], 'default');
            } else {
                // Testing SQLite connection
                $this->db->addConnection([
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                ], 'default');
            }
            
            // Set as global for static facade access
            $this->db->setAsGlobal();
            $this->db->bootEloquent();
            
            // Ensure the static instance is properly set
            try {
                if (!$this->db->getContainer()) {
                    // Container is not set, try to set it
                    $this->db->setAsGlobal();
                }
            } catch (\Exception $e) {
                // If static methods fail, that's ok, we'll use instance methods
            }
            
            // Create tables if using SQLite for testing
            if (is_string($connection)) {
                $this->createMockTables();
            }
        }
        
        return $this->db;
    }
    
    protected function tableExists(string $table): bool
    {
        try {
            $db = $this->getDatabase();
            $connection = $db->getConnection();
            return $connection->getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            // If we can't check, assume table doesn't exist
            return false;
        }
    }
    
    protected function insertRecord(string $table, array $data): string
    {
        $db = $this->getDatabase();
        $db->table($table)->insert($data);
        return 'created';
    }
    
    protected function updateRecord(string $table, array $data, array $conditions): string
    {
        $db = $this->getDatabase();
        $query = $db->table($table);
        
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        
        $rowsAffected = $query->update($data);
        return $rowsAffected > 0 ? 'updated' : 'skipped';
    }
    
    protected function findExistingRecord(string $table, array $data, array $uniqueFields): ?array
    {
        if (empty($uniqueFields)) {
            return null;
        }
        
        $db = $this->getDatabase();
        $query = $db->table($table);
        
        foreach ($uniqueFields as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        
        $result = $query->first();
        return $result ? (array) $result : null;
    }
    
    protected function deleteRecord(string $table, array $conditions): void
    {
        $db = $this->getDatabase();
        $query = $db->table($table);
        
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        
        $query->delete();
    }
    
    protected function beginTransaction(): void
    {
        $db = $this->getDatabase();
        $db->connection()->beginTransaction();
    }
    
    protected function commit(): void
    {
        $this->db->connection()->commit();
    }
    
    protected function rollbackTransaction(): void
    {
        $this->db->connection()->rollback();
    }
    
    protected function inTransaction(): bool
    {
        $db = $this->getDatabase();
        return $db->connection()->transactionLevel() > 0;
    }
    
    protected function createMockTables(): void
    {
        // Override in child classes to create specific table structures
    }
}