<?php

namespace Crumbls\Importer\Support;

use PDO;

class LiveWordPressAnalyzer
{
    protected DatabaseConnectionManager $connectionManager;
    protected PostTypeAnalyzer $postTypeAnalyzer;
    protected string $connectionName;
    protected array $wordpressInfo = [];
    
    public function __construct(DatabaseConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
        $this->postTypeAnalyzer = new PostTypeAnalyzer();
    }
    
    public function connect(string $connectionName, array $dbConfig): self
    {
        // Test connection first
        $testResult = $this->connectionManager->testConnection($dbConfig);
        
        if (!$testResult['success']) {
            throw new \RuntimeException(
                "Failed to connect to WordPress database: " . $testResult['error']
            );
        }
        
        // Establish connection
        $this->connectionManager->connect($connectionName, $dbConfig);
        $this->connectionName = $connectionName;
        
        // Get WordPress info
        $this->wordpressInfo = $this->connectionManager->getWordPressInfo($connectionName);
        
        return $this;
    }
    
    public function analyzeComplete(): array
    {
        $pdo = $this->connectionManager->getConnection($this->connectionName);
        
        // Extract all WordPress data for analysis
        $wordpressData = $this->extractWordPressData($pdo);
        
        // Run post type analysis
        $this->postTypeAnalyzer->analyze($wordpressData);
        
        return [
            'connection_info' => $this->wordpressInfo,
            'post_type_analysis' => $this->postTypeAnalyzer->getDetailedReport(),
            'database_schema' => $this->analyzeDatabaseSchema($pdo),
            'performance_insights' => $this->getPerformanceInsights($pdo),
            'migration_recommendations' => $this->generateMigrationRecommendations()
        ];
    }
    
    public function analyzeSample(int $sampleSize = 1000): array
    {
        $pdo = $this->connectionManager->getConnection($this->connectionName);
        
        // Extract sample data for quick analysis
        $wordpressData = $this->extractSampleData($pdo, $sampleSize);
        
        // Run post type analysis on sample
        $this->postTypeAnalyzer->analyze($wordpressData);
        
        return [
            'sample_size' => $sampleSize,
            'connection_info' => $this->wordpressInfo,
            'post_type_analysis' => $this->postTypeAnalyzer->getDetailedReport(),
            'estimated_full_size' => $this->estimateFullDataSize($pdo),
            'migration_recommendations' => $this->generateMigrationRecommendations()
        ];
    }
    
    public function getPostTypeCounts(): array
    {
        $pdo = $this->connectionManager->getConnection($this->connectionName);
        $prefix = $this->getWordPressPrefix();
        
        $stmt = $pdo->prepare("
            SELECT post_type, COUNT(*) as count 
            FROM {$prefix}posts 
            WHERE post_status != 'auto-draft'
            GROUP BY post_type 
            ORDER BY count DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    public function getCustomFieldsPreview(string $postType = null, int $limit = 100): array
    {
        $pdo = $this->connectionManager->getConnection($this->connectionName);
        $prefix = $this->getWordPressPrefix();
        
        $whereClause = '';
        $params = [];
        
        if ($postType) {
            $whereClause = "
                AND pm.post_id IN (
                    SELECT ID FROM {$prefix}posts 
                    WHERE post_type = ? AND post_status != 'auto-draft'
                )
            ";
            $params[] = $postType;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                pm.meta_key,
                COUNT(*) as usage_count,
                COUNT(DISTINCT pm.post_id) as post_count,
                AVG(LENGTH(pm.meta_value)) as avg_value_length,
                MIN(pm.meta_value) as sample_value
            FROM {$prefix}postmeta pm
            WHERE pm.meta_key NOT LIKE '\_%'  -- Exclude WordPress internal fields for preview
            {$whereClause}
            GROUP BY pm.meta_key
            ORDER BY usage_count DESC
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function analyzePostType(string $postType): array
    {
        $pdo = $this->connectionManager->getConnection($this->connectionName);
        $prefix = $this->getWordPressPrefix();
        
        // Get post type specific data
        $posts = $this->getPostsByType($pdo, $postType);
        $postmeta = $this->getMetaForPosts($pdo, array_column($posts, 'ID'));
        
        // Analyze with PostTypeAnalyzer
        $analyzer = new PostTypeAnalyzer();
        $analyzer->analyze([
            'posts' => $posts,
            'postmeta' => $postmeta
        ]);
        
        $schema = $analyzer->getSchema($postType);
        
        return [
            'post_type' => $postType,
            'post_count' => count($posts),
            'field_analysis' => $schema,
            'database_insights' => $this->getPostTypeTableInsights($pdo, $postType),
            'migration_strategy' => $this->generatePostTypeMigrationStrategy($postType, $schema)
        ];
    }
    
    public function getConnectionInfo(): array
    {
        return $this->wordpressInfo;
    }
    
    public function testPerformance(): array
    {
        $pdo = $this->connectionManager->getConnection($this->connectionName);
        $prefix = $this->getWordPressPrefix();
        
        $tests = [];
        
        // Test 1: Simple COUNT query
        $start = microtime(true);
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$prefix}posts");
        $postCount = $stmt->fetchColumn();
        $tests['count_posts'] = [
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'result' => $postCount
        ];
        
        // Test 2: Complex JOIN query
        $start = microtime(true);
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM {$prefix}posts p 
            LEFT JOIN {$prefix}postmeta pm ON p.ID = pm.post_id 
            WHERE p.post_status = 'publish'
        ");
        $joinCount = $stmt->fetchColumn();
        $tests['complex_join'] = [
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'result' => $joinCount
        ];
        
        // Test 3: Large result set (sample 1000 records)
        $start = microtime(true);
        $stmt = $pdo->query("SELECT * FROM {$prefix}posts LIMIT 1000");
        $results = $stmt->fetchAll();
        $tests['large_result_set'] = [
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'result' => count($results)
        ];
        
        return [
            'performance_tests' => $tests,
            'recommendations' => $this->generatePerformanceRecommendations($tests)
        ];
    }
    
    protected function extractWordPressData(PDO $pdo): array
    {
        $prefix = $this->getWordPressPrefix();
        
        // Extract posts
        $stmt = $pdo->query("SELECT * FROM {$prefix}posts WHERE post_status != 'auto-draft'");
        $posts = $stmt->fetchAll();
        
        // Extract postmeta
        $stmt = $pdo->query("SELECT * FROM {$prefix}postmeta");
        $postmeta = $stmt->fetchAll();
        
        // Extract comments
        $stmt = $pdo->query("SELECT * FROM {$prefix}comments");
        $comments = $stmt->fetchAll();
        
        // Extract users
        $stmt = $pdo->query("SELECT * FROM {$prefix}users");
        $users = $stmt->fetchAll();
        
        return [
            'posts' => $posts,
            'postmeta' => $postmeta,
            'comments' => $comments,
            'users' => $users
        ];
    }
    
    protected function extractSampleData(PDO $pdo, int $sampleSize): array
    {
        $prefix = $this->getWordPressPrefix();
        
        // Get sample posts
        $stmt = $pdo->prepare("
            SELECT * FROM {$prefix}posts 
            WHERE post_status != 'auto-draft' 
            ORDER BY post_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$sampleSize]);
        $posts = $stmt->fetchAll();
        
        if (empty($posts)) {
            return ['posts' => [], 'postmeta' => []];
        }
        
        // Get meta for sample posts
        $postIds = array_column($posts, 'ID');
        $placeholders = str_repeat('?,', count($postIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT * FROM {$prefix}postmeta 
            WHERE post_id IN ({$placeholders})
        ");
        $stmt->execute($postIds);
        $postmeta = $stmt->fetchAll();
        
        return [
            'posts' => $posts,
            'postmeta' => $postmeta
        ];
    }
    
    protected function getPostsByType(PDO $pdo, string $postType): array
    {
        $prefix = $this->getWordPressPrefix();
        
        $stmt = $pdo->prepare("
            SELECT * FROM {$prefix}posts 
            WHERE post_type = ? AND post_status != 'auto-draft'
            ORDER BY post_date DESC
        ");
        $stmt->execute([$postType]);
        
        return $stmt->fetchAll();
    }
    
    protected function getMetaForPosts(PDO $pdo, array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }
        
        $prefix = $this->getWordPressPrefix();
        $placeholders = str_repeat('?,', count($postIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT * FROM {$prefix}postmeta 
            WHERE post_id IN ({$placeholders})
        ");
        $stmt->execute($postIds);
        
        return $stmt->fetchAll();
    }
    
    protected function analyzeDatabaseSchema(PDO $pdo): array
    {
        $schemas = [];
        $wpTables = $this->wordpressInfo['wordpress_tables'];
        
        foreach ($wpTables as $table) {
            $tableName = $table['table_name'];
            $schemas[$tableName] = $this->connectionManager->getTableSchema(
                $this->connectionName, 
                $tableName
            );
        }
        
        return $schemas;
    }
    
    protected function getPerformanceInsights(PDO $pdo): array
    {
        $prefix = $this->getWordPressPrefix();
        
        // Analyze table sizes and performance
        $insights = [];
        
        foreach ($this->wordpressInfo['wordpress_tables'] as $table) {
            $tableName = $table['table_name'];
            $insights[$tableName] = [
                'row_count' => $table['table_rows'],
                'data_size_mb' => round($table['data_length'] / 1024 / 1024, 2),
                'index_size_mb' => round($table['index_length'] / 1024 / 1024, 2),
                'total_size_mb' => round(($table['data_length'] + $table['index_length']) / 1024 / 1024, 2)
            ];
        }
        
        return $insights;
    }
    
    protected function estimateFullDataSize(PDO $pdo): array
    {
        $prefix = $this->getWordPressPrefix();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$prefix}posts WHERE post_status != 'auto-draft'");
        $totalPosts = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$prefix}postmeta");
        $totalPostmeta = $stmt->fetchColumn();
        
        return [
            'total_posts' => $totalPosts,
            'total_postmeta' => $totalPostmeta,
            'estimated_migration_time' => $this->estimateMigrationTime($totalPosts, $totalPostmeta)
        ];
    }
    
    protected function estimateMigrationTime(int $posts, int $postmeta): string
    {
        // Rough estimates based on typical performance
        $postsPerSecond = 500;
        $metaPerSecond = 1000;
        
        $postTime = $posts / $postsPerSecond;
        $metaTime = $postmeta / $metaPerSecond;
        
        $totalSeconds = max($postTime, $metaTime);
        
        if ($totalSeconds < 60) {
            return round($totalSeconds) . ' seconds';
        } elseif ($totalSeconds < 3600) {
            return round($totalSeconds / 60) . ' minutes';
        } else {
            return round($totalSeconds / 3600, 1) . ' hours';
        }
    }
    
    protected function getPostTypeTableInsights(PDO $pdo, string $postType): array
    {
        $prefix = $this->getWordPressPrefix();
        
        // Get post count for this type
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM {$prefix}posts 
            WHERE post_type = ? AND post_status != 'auto-draft'
        ");
        $stmt->execute([$postType]);
        $postCount = $stmt->fetchColumn();
        
        // Get meta count for this post type
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM {$prefix}postmeta pm
            JOIN {$prefix}posts p ON pm.post_id = p.ID
            WHERE p.post_type = ? AND p.post_status != 'auto-draft'
        ");
        $stmt->execute([$postType]);
        $metaCount = $stmt->fetchColumn();
        
        return [
            'post_count' => $postCount,
            'meta_count' => $metaCount,
            'avg_meta_per_post' => $postCount > 0 ? round($metaCount / $postCount, 2) : 0
        ];
    }
    
    protected function generateMigrationRecommendations(): array
    {
        $recommendations = [];
        $analysis = $this->postTypeAnalyzer->getDetailedReport();
        
        // Size-based recommendations
        $totalPosts = $this->postTypeAnalyzer->getStatistics()['total_posts'];
        
        if ($totalPosts > 10000) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'high',
                'message' => 'Large dataset detected - consider incremental migration approach'
            ];
        }
        
        if ($totalPosts > 100000) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'critical',
                'message' => 'Very large dataset - implement batch processing with progress tracking'
            ];
        }
        
        // Plugin-specific recommendations
        $plugins = $analysis['statistics']['plugin_detection'];
        
        if ($plugins['woocommerce']) {
            $recommendations[] = [
                'type' => 'compatibility',
                'priority' => 'medium',
                'message' => 'WooCommerce detected - ensure product relationships are preserved'
            ];
        }
        
        if ($plugins['acf']) {
            $recommendations[] = [
                'type' => 'compatibility',
                'priority' => 'medium',
                'message' => 'ACF fields detected - verify field group configurations'
            ];
        }
        
        return $recommendations;
    }
    
    protected function generatePostTypeMigrationStrategy(string $postType, array $schema): array
    {
        $strategy = [
            'approach' => 'standard',
            'batch_size' => 100,
            'priority' => 'medium',
            'special_handling' => []
        ];
        
        // Adjust based on post count and complexity
        if (isset($schema['post_count'])) {
            if ($schema['post_count'] > 10000) {
                $strategy['batch_size'] = 50;
                $strategy['priority'] = 'high';
            } elseif ($schema['post_count'] > 1000) {
                $strategy['batch_size'] = 75;
            }
        }
        
        // Adjust based on field complexity
        if (isset($schema['custom_fields']) && count($schema['custom_fields']) > 10) {
            $strategy['special_handling'][] = 'Complex custom fields - validate data integrity';
        }
        
        return $strategy;
    }
    
    protected function generatePerformanceRecommendations(array $tests): array
    {
        $recommendations = [];
        
        if ($tests['count_posts']['duration_ms'] > 1000) {
            $recommendations[] = 'Consider adding indexes to improve query performance';
        }
        
        if ($tests['complex_join']['duration_ms'] > 5000) {
            $recommendations[] = 'Complex queries are slow - implement connection pooling';
        }
        
        if ($tests['large_result_set']['duration_ms'] > 2000) {
            $recommendations[] = 'Large result sets are slow - use chunked processing';
        }
        
        return $recommendations;
    }
    
    protected function getWordPressPrefix(): string
    {
        return $this->wordpressInfo['wordpress_info']['prefix'] ?? 'wp_';
    }
}