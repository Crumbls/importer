<?php

namespace Crumbls\Importer\StorageDrivers\Contracts;

interface StorageDriverContract
{
    // Storage Management
    public function createOrFindStore(string $name): static;
    public function getStorePath(): string;
    
    // Table/Schema Management
    public function createTable(string $tableName, callable $schemaCallback): static;
    public function createTableFromSchema(string $tableName, array $schema): static;
    public function dropTable(string $tableName): static;
    public function tableExists(string $tableName): bool;
    public function getTables(): array;
    
    // Data Operations (CRUD)
    public function insert(string $tableName, array $data): static;
    public function insertBatch(string $tableName, array $rows): static;
    public function select(string $tableName, array $conditions = []): array;
    public function update(string $tableName, array $data, array $conditions): static;
    public function delete(string $tableName, array $conditions): static;
    
    // Query Operations
    public function count(string $tableName, array $conditions = []): int;
    public function exists(string $tableName, array $conditions): bool;
    
    // Analysis Operations
    public function limit(string $tableName, int $limit, int $offset = 0, array $conditions = []): array;
    public function countWhere(string $tableName, $conditions, $value = null): int;
    public function countDistinct(string $tableName, string $column): int;
    public function min(string $tableName, string $column);
    public function max(string $tableName, string $column);
    public function sampleNonNull(string $tableName, string $column, int $limit = 100): array;
    
    // Metadata
    public function getColumns(string $tableName): array;
    public function getSize(): int;
}