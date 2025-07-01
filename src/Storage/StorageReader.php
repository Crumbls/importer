<?php

namespace Crumbls\Importer\Storage;

use Crumbls\Importer\Contracts\TemporaryStorageContract;
use Crumbls\Importer\RateLimit\RateLimiter;

class StorageReader
{
    protected TemporaryStorageContract $storage;
    protected ?RateLimiter $rateLimiter = null;
    protected string $table;
    
    public function __construct(TemporaryStorageContract $storage, string $table = 'data')
    {
        $this->storage = $storage;
        $this->table = $table;
    }
    
    public function withRateLimit(int $maxReadsPerSecond): self
    {
        $this->rateLimiter = new RateLimiter($maxReadsPerSecond, 1);
        return $this;
    }
    
    public function getHeaders(): array
    {
        return $this->storage->getHeaders($this->table);
    }
    
    public function count(): int
    {
        return $this->storage->count($this->table);
    }
    
    public function chunk(int $size, callable $callback): void
    {
        $this->storage->chunk($size, function($chunk) use ($callback) {
            if ($this->rateLimiter) {
                $this->rateLimiter->wait('reads', count($chunk));
            }
            
            $callback($chunk);
        }, $this->table);
    }
    
    public function all(): \Generator
    {
        foreach ($this->storage->all($this->table) as $row) {
            if ($this->rateLimiter) {
                $this->rateLimiter->wait('reads', 1);
            }
            
            yield $row;
        }
    }
    
    public function sample(int $limit = 10): array
    {
        return $this->storage->first($limit, $this->table);
    }
    
    public function paginate(int $page = 1, int $perPage = 100): array
    {
        $offset = ($page - 1) * $perPage;
        $results = [];
        $current = 0;
        
        foreach ($this->storage->all() as $row) {
            if ($current >= $offset && count($results) < $perPage) {
                $results[] = $row;
            }
            
            $current++;
            
            if (count($results) >= $perPage) {
                break;
            }
        }
        
        return [
            'data' => $results,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $this->storage->count(),
            'has_more' => ($offset + $perPage) < $this->storage->count()
        ];
    }
    
    public function where(string $column, $value): array
    {
        $headers = $this->storage->getHeaders();
        $columnIndex = array_search($column, $headers);
        
        if ($columnIndex === false) {
            return [];
        }
        
        $results = [];
        foreach ($this->storage->all() as $row) {
            if (isset($row[$columnIndex]) && $row[$columnIndex] == $value) {
                $results[] = $row;
            }
        }
        
        return $results;
    }
    
    public function filter(callable $callback): array
    {
        $results = [];
        $headers = $this->storage->getHeaders();
        
        foreach ($this->storage->all() as $row) {
            $associativeRow = array_combine($headers, $row);
            if ($callback($associativeRow, $row)) {
                $results[] = $row;
            }
        }
        
        return $results;
    }
    
    public function transform(callable $transformer): \Generator
    {
        $headers = $this->storage->getHeaders();
        
        foreach ($this->storage->all() as $row) {
            if ($this->rateLimiter) {
                $this->rateLimiter->wait('reads', 1);
            }
            
            $associativeRow = array_combine($headers, $row);
            yield $transformer($associativeRow, $row);
        }
    }
    
    public function getRateLimiterStats(): ?array
    {
        return $this->rateLimiter?->getStats('reads');
    }
    
    public function getStorageInfo(): array
    {
        return $this->storage->getStorageInfo();
    }
    
    public function export(): array
    {
        return [
            'headers' => $this->storage->getHeaders(),
            'data' => iterator_to_array($this->storage->all()),
            'count' => $this->storage->count(),
            'storage_info' => $this->storage->getStorageInfo()
        ];
    }
}