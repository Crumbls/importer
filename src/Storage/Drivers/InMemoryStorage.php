<?php

namespace Crumbls\Importer\Storage\Drivers;

use Crumbls\Importer\Contracts\TemporaryStorageContract;

class InMemoryStorage implements TemporaryStorageContract
{
    protected array $headers = [];
    protected array $data = [];
    protected bool $created = false;
    
    public function create(array $headers, string $table = 'data'): void
    {
        $this->headers = $headers;
        $this->data = [];
        $this->created = true;
    }
    
    public function insert(array $row, string $table = 'data'): bool
    {
        if (!$this->created) {
            return false;
        }
        
        $this->data[] = $row;
        return true;
    }
    
    public function insertBatch(array $rows, string $table = 'data'): int
    {
        if (!$this->created) {
            return 0;
        }
        
        $inserted = 0;
        foreach ($rows as $row) {
            if ($this->insert($row, $table)) {
                $inserted++;
            }
        }
        
        return $inserted;
    }
    
    public function count(string $table = 'data'): int
    {
        return count($this->data);
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
        
        $chunks = array_chunk($this->data, $size);
        foreach ($chunks as $chunk) {
            $callback($chunk);
        }
    }
    
    public function all(string $table = 'data'): \Generator
    {
        foreach ($this->data as $row) {
            yield $row;
        }
    }
    
    public function first(int $limit = 1, string $table = 'data'): array
    {
        return array_slice($this->data, 0, $limit);
    }
    
    public function exists(string $table = 'data'): bool
    {
        return $this->created;
    }
    
    public function destroy(string $table = null): bool
    {
        $this->data = [];
        $this->headers = [];
        $this->created = false;
        return true;
    }
    
    public function getTables(): array
    {
        return $this->created ? ['data'] : [];
    }
    
    public function createMultipleTables(array $schemas): void
    {
        // InMemoryStorage only supports single table
        // Just create with the first schema for backward compatibility
        if (!empty($schemas)) {
            $firstTable = array_key_first($schemas);
            $this->create($schemas[$firstTable], $firstTable);
        }
    }
    
    public function getStorageInfo(): array
    {
        return [
            'driver' => 'memory',
            'memory_usage' => memory_get_usage(true),
            'row_count' => $this->count(),
            'created' => $this->created,
            'headers_count' => count($this->headers)
        ];
    }
}