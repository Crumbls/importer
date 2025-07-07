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
    
    // Metadata
    public function getColumns(string $tableName): array;
    public function getSize(): int;
}