<?php

namespace Crumbls\Importer\Support;

use PDO;
use PDOException;

class DatabaseConnectionManager
{
    protected array $connections = [];
    protected array $defaultConfig = [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // For memory efficiency with large datasets
        ]
    ];
    
    public function connect(string $connectionName, array $config): PDO
    {
        $config = array_merge($this->defaultConfig, $config);
        
        // Validate required config
        $this->validateConfig($config);
        
        try {
            $dsn = $this->buildDsn($config);
            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );
            
            // Store connection for reuse
            $this->connections[$connectionName] = [
                'pdo' => $pdo,
                'config' => $config,
                'connected_at' => time(),
                'last_used' => time()
            ];
            
            return $pdo;
            
        } catch (PDOException $e) {
            throw new \RuntimeException(
                "Failed to connect to database '{$connectionName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    public function getConnection(string $connectionName): ?PDO
    {
        if (isset($this->connections[$connectionName])) {
            $connection = $this->connections[$connectionName];
            
            // Update last used timestamp
            $this->connections[$connectionName]['last_used'] = time();
            
            return $connection['pdo'];
        }
        
        return null;
    }
    
    public function testConnection(array $config): array
    {
        $testName = 'test_' . uniqid();
        $result = [
            'success' => false,
            'error' => null,
            'connection_time' => null,
            'database_info' => []
        ];
        
        $startTime = microtime(true);
        
        try {
            $pdo = $this->connect($testName, $config);
            $connectionTime = microtime(true) - $startTime;
            
            // Test basic connectivity and get database info
            $stmt = $pdo->query('SELECT VERSION() as version, DATABASE() as database_name');
            $info = $stmt->fetch();
            
            // Test WordPress table detection
            $wpTables = $this->detectWordPressTables($pdo, $config['database']);
            
            $result = [
                'success' => true,
                'error' => null,
                'connection_time' => round($connectionTime * 1000, 2), // milliseconds
                'database_info' => [
                    'mysql_version' => $info['version'],
                    'database_name' => $info['database_name'],
                    'wordpress_tables_found' => count($wpTables),
                    'wordpress_prefix' => $this->detectWordPressPrefix($wpTables),
                    'tables_found' => $wpTables
                ]
            ];
            
            // Clean up test connection
            $this->disconnect($testName);
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    public function disconnect(string $connectionName): bool
    {
        if (isset($this->connections[$connectionName])) {
            unset($this->connections[$connectionName]);
            return true;
        }
        return false;
    }
    
    public function disconnectAll(): void
    {
        $this->connections = [];
    }
    
    public function getActiveConnections(): array
    {
        $active = [];
        foreach ($this->connections as $name => $connection) {
            $active[$name] = [
                'connected_at' => $connection['connected_at'],
                'last_used' => $connection['last_used'],
                'database' => $connection['config']['database'],
                'host' => $connection['config']['host']
            ];
        }
        return $active;
    }
    
    protected function validateConfig(array $config): void
    {
        $required = ['host', 'database', 'username', 'password'];
        
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new \InvalidArgumentException("Missing required config key: {$key}");
            }
        }
        
        // Validate host format
        if (!filter_var($config['host'], FILTER_VALIDATE_IP) && 
            !filter_var('http://' . $config['host'], FILTER_VALIDATE_URL)) {
            // Allow localhost and valid hostnames
            if (!in_array($config['host'], ['localhost', '127.0.0.1']) && 
                !preg_match('/^[a-zA-Z0-9.-]+$/', $config['host'])) {
                throw new \InvalidArgumentException("Invalid host format: {$config['host']}");
            }
        }
        
        // Validate port
        if (isset($config['port']) && (!is_int($config['port']) || $config['port'] < 1 || $config['port'] > 65535)) {
            throw new \InvalidArgumentException("Invalid port: {$config['port']}");
        }
    }
    
    protected function buildDsn(array $config): string
    {
        $dsn = "{$config['driver']}:host={$config['host']};dbname={$config['database']}";
        
        if (isset($config['port'])) {
            $dsn .= ";port={$config['port']}";
        }
        
        if (isset($config['charset'])) {
            $dsn .= ";charset={$config['charset']}";
        }
        
        return $dsn;
    }
    
    protected function detectWordPressTables(PDO $pdo, string $database): array
    {
        $stmt = $pdo->prepare("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = ? 
            AND table_name LIKE '%posts' 
            OR table_name LIKE '%postmeta'
            OR table_name LIKE '%users'
            OR table_name LIKE '%options'
            OR table_name LIKE '%comments'
            ORDER BY table_name
        ");
        
        $stmt->execute([$database]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    protected function detectWordPressPrefix(array $tables): string
    {
        // Look for common WordPress table patterns
        $commonTables = ['posts', 'postmeta', 'users', 'options'];
        
        foreach ($tables as $table) {
            foreach ($commonTables as $commonTable) {
                if (str_ends_with($table, $commonTable)) {
                    return substr($table, 0, -strlen($commonTable));
                }
            }
        }
        
        return 'wp_'; // Default fallback
    }
    
    // Helper methods for WordPress-specific operations
    
    public function getWordPressInfo(string $connectionName): array
    {
        $pdo = $this->getConnection($connectionName);
        if (!$pdo) {
            throw new \RuntimeException("Connection '{$connectionName}' not found");
        }
        
        $config = $this->connections[$connectionName]['config'];
        $database = $config['database'];
        
        // Get all tables
        $stmt = $pdo->prepare("
            SELECT table_name, table_rows, data_length, index_length
            FROM information_schema.tables 
            WHERE table_schema = ?
            ORDER BY table_name
        ");
        $stmt->execute([$database]);
        $allTables = $stmt->fetchAll();
        
        // Detect WordPress tables
        $wpTables = [];
        $prefix = '';
        
        foreach ($allTables as $table) {
            $tableName = $table['table_name'];
            if (preg_match('/^(.+?)(posts|postmeta|users|options|comments)$/', $tableName, $matches)) {
                $prefix = $matches[1];
                $wpTables[] = $table;
            }
        }
        
        // Get WordPress version and other info from options table
        $wpInfo = [];
        if ($prefix) {
            try {
                $stmt = $pdo->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'db_version' LIMIT 1");
                $stmt->execute();
                $dbVersion = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'blogname' LIMIT 1");
                $stmt->execute();
                $siteName = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'siteurl' LIMIT 1");
                $stmt->execute();
                $siteUrl = $stmt->fetchColumn();
                
                $wpInfo = [
                    'site_name' => $siteName,
                    'site_url' => $siteUrl,
                    'db_version' => $dbVersion,
                    'prefix' => $prefix
                ];
            } catch (\Exception $e) {
                // Ignore errors, table might not be accessible
            }
        }
        
        return [
            'wordpress_info' => $wpInfo,
            'wordpress_tables' => $wpTables,
            'all_tables' => $allTables,
            'table_count' => count($allTables),
            'wordpress_table_count' => count($wpTables)
        ];
    }
    
    public function getTableSchema(string $connectionName, string $tableName): array
    {
        $pdo = $this->getConnection($connectionName);
        if (!$pdo) {
            throw new \RuntimeException("Connection '{$connectionName}' not found");
        }
        
        $config = $this->connections[$connectionName]['config'];
        $database = $config['database'];
        
        // Get column information
        $stmt = $pdo->prepare("
            SELECT 
                column_name,
                data_type,
                is_nullable,
                column_default,
                extra,
                column_type,
                column_comment
            FROM information_schema.columns 
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position
        ");
        $stmt->execute([$database, $tableName]);
        $columns = $stmt->fetchAll();
        
        // Get indexes
        $stmt = $pdo->prepare("SHOW INDEX FROM `{$tableName}`");
        $stmt->execute();
        $indexes = $stmt->fetchAll();
        
        // Get table stats
        $stmt = $pdo->prepare("
            SELECT table_rows, data_length, index_length, auto_increment
            FROM information_schema.tables 
            WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([$database, $tableName]);
        $tableStats = $stmt->fetch();
        
        return [
            'table_name' => $tableName,
            'columns' => $columns,
            'indexes' => $indexes,
            'statistics' => $tableStats
        ];
    }
    
    public function performanceOptimizations(PDO $pdo): void
    {
        // Set MySQL session variables for better performance with large datasets
        $optimizations = [
            'SET SESSION sql_mode = ""',  // Relax SQL mode for compatibility
            'SET SESSION foreign_key_checks = 0',  // Faster bulk operations
            'SET SESSION unique_checks = 0',  // Faster bulk operations
            'SET SESSION autocommit = 0',  // Manual transaction control
        ];
        
        foreach ($optimizations as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Exception $e) {
                // Log but don't fail - some optimizations might not be available
                error_log("Performance optimization failed: {$sql} - " . $e->getMessage());
            }
        }
    }
    
    public function resetOptimizations(PDO $pdo): void
    {
        $resets = [
            'SET SESSION foreign_key_checks = 1',
            'SET SESSION unique_checks = 1',
            'SET SESSION autocommit = 1',
            'COMMIT',  // Commit any pending transactions
        ];
        
        foreach ($resets as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Exception $e) {
                error_log("Reset optimization failed: {$sql} - " . $e->getMessage());
            }
        }
    }
}