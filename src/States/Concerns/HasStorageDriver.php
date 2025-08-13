<?php

namespace Crumbls\Importer\States\Concerns;

use Crumbls\Importer\Exceptions\StateTransitionException;
use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Exception;

trait HasStorageDriver
{
    /**
     * Get the storage driver instance for the current import
     */
    public function getStorageDriver(): StorageDriverContract
    {
        $record = $this->getRecord();
        $metadata = $record->metadata ?? [];
        
        if (!isset($metadata['storage_driver']) || !$metadata['storage_driver']) {
            throw StateTransitionException::storageNotConfigured($record->id);
        }
        
        return Storage::driver($metadata['storage_driver'])
            ->configureFromMetadata($metadata);
    }
    
    /**
     * Check if a storage driver is configured for the current import
     */
    public function hasStorageDriver(): bool
    {
        try {
            $record = $this->getRecord();
            $metadata = $record->metadata ?? [];
            return isset($metadata['storage_driver']) && !empty($metadata['storage_driver']);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get the storage driver name from the import metadata
     */
    public function getStorageDriverName(): ?string
    {
        try {
            $record = $this->getRecord();
            $metadata = $record->metadata ?? [];
            return $metadata['storage_driver'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Set the storage driver for the current import
     */
    public function setStorageDriver(string $driverName, array $additionalMetadata = []): void
    {
        $record = $this->getRecord();
        $metadata = $record->metadata ?? [];
        
        $metadata['storage_driver'] = $driverName;
        
        // Merge any additional metadata
        if (!empty($additionalMetadata)) {
            $metadata = array_merge($metadata, $additionalMetadata);
        }
        
        $record->update(['metadata' => $metadata]);
    }
    
    /**
     * Create or find a storage store for the current import
     */
    public function createOrFindStorageStore(string $storeName): StorageDriverContract
    {
        $driver = $this->getStorageDriver();
        return $driver->createOrFindStore($storeName);
    }
    
    /**
     * Get the storage path from the current storage driver
     */
    public function getStoragePath(): string
    {
        $driver = $this->getStorageDriver();
        return $driver->getStorePath();
    }
    
    /**
     * Check if a table exists in the storage driver
     */
    public function storageTableExists(string $tableName): bool
    {
        $driver = $this->getStorageDriver();
        return $driver->tableExists($tableName);
    }
    
    /**
     * Get all tables from the storage driver
     */
    public function getStorageTables(): array
    {
        $driver = $this->getStorageDriver();
        return $driver->getTables();
    }
    
    /**
     * Insert data into a storage table
     */
    public function insertIntoStorage(string $tableName, array $data): void
    {
        $driver = $this->getStorageDriver();
        $driver->insert($tableName, $data);
    }
    
    /**
     * Insert batch data into a storage table
     */
    public function insertBatchIntoStorage(string $tableName, array $rows): void
    {
        $driver = $this->getStorageDriver();
        $driver->insertBatch($tableName, $rows);
    }
    
    /**
     * Select data from a storage table
     */
    public function selectFromStorage(string $tableName, array $conditions = []): array
    {
        $driver = $this->getStorageDriver();
        return $driver->select($tableName, $conditions);
    }
    
    /**
     * Count records in a storage table
     */
    public function countStorageRecords(string $tableName, array $conditions = []): int
    {
        $driver = $this->getStorageDriver();
        return $driver->count($tableName, $conditions);
    }
    
    /**
     * Check if records exist in a storage table
     */
    public function storageRecordsExist(string $tableName, array $conditions): bool
    {
        $driver = $this->getStorageDriver();
        return $driver->exists($tableName, $conditions);
    }
    
    /**
     * Get columns from a storage table
     */
    public function getStorageColumns(string $tableName): array
    {
        $driver = $this->getStorageDriver();
        return $driver->getColumns($tableName);
    }
    
    /**
     * Get the storage size
     */
    public function getStorageSize(): int
    {
        $driver = $this->getStorageDriver();
        return $driver->getSize();
    }
}