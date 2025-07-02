<?php

namespace Crumbls\Importer\Storage;

use Crumbls\Importer\Contracts\TemporaryStorageContract;
use Crumbls\Importer\Storage\Drivers\InMemoryStorage;
use Crumbls\Importer\Storage\Drivers\SqliteStorage;
use Crumbls\Importer\Storage\Drivers\MultiTableSqliteStorage;

class TemporaryStorageManager
{
    protected array $config;
    protected ?TemporaryStorageContract $storage = null;
    
    public function __construct(array $config = [])
    {
        // Default configuration with Laravel-aware path handling
        $defaultSqlitePath = sys_get_temp_dir() . '/import_storage';
        
        // Only try Laravel storage_path if we're confident we're in Laravel
        try {
            if (function_exists('app') && app()->bound('config')) {
                $defaultSqlitePath = storage_path('temp/import_storage');
            }
        } catch (\Exception $e) {
            // Fall back to system temp directory
        }
        
        $this->config = array_merge([
            'driver' => 'memory',
            'sqlite' => [
                'path' => $defaultSqlitePath,
                'cleanup_after' => 3600
            ]
        ], $config);
    }
    
    public function driver(string $driver = null): TemporaryStorageContract
    {
        $driver = $driver ?: $this->config['driver'];
        
        if ($this->storage && $this->storage->getStorageInfo()['driver'] === $driver) {
            return $this->storage;
        }
        
        $this->storage = match ($driver) {
            'memory' => new InMemoryStorage(),
            'sqlite' => new SqliteStorage($this->config['sqlite'] ?? []),
            'multi_table_sqlite' => new MultiTableSqliteStorage($this->config['sqlite'] ?? []),
            default => throw new \InvalidArgumentException("Unsupported storage driver: {$driver}")
        };
        
        return $this->storage;
    }
    
    public function memory(): TemporaryStorageContract
    {
        return $this->driver('memory');
    }
    
    public function sqlite(array $config = []): TemporaryStorageContract
    {
        $sqliteConfig = array_merge($this->config['sqlite'] ?? [], $config);
        $this->storage = new SqliteStorage($sqliteConfig);
        return $this->storage;
    }
    
    public function getConfig(): array
    {
        return $this->config;
    }
    
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }
}