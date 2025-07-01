<?php

namespace Crumbls\Importer\Contracts;

interface TemporaryStorageContract
{
    public function create(array $headers, string $table = 'data'): void;
    
    public function insert(array $row, string $table = 'data'): bool;
    
    public function insertBatch(array $rows, string $table = 'data'): int;
    
    public function count(string $table = 'data'): int;
    
    public function getHeaders(string $table = 'data'): array;
    
    public function chunk(int $size, callable $callback, string $table = 'data'): void;
    
    public function all(string $table = 'data'): \Generator;
    
    public function first(int $limit = 1, string $table = 'data'): array;
    
    public function exists(string $table = 'data'): bool;
    
    public function destroy(string $table = null): bool;
    
    public function getStorageInfo(): array;
    
    public function getTables(): array;
    
    public function createMultipleTables(array $schemas): void;
}