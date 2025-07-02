<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Support\DatabaseConnectionManager;
use Crumbls\Importer\Support\LiveWordPressAnalyzer;

/**
 * Test Live WordPress Database Analysis
 */

echo "🔍 Live WordPress Database Analysis Test\n";
echo "========================================\n\n";

// Sample database configurations for testing
$sampleConfigs = [
    'local_wordpress' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'wordpress',
        'username' => 'root',
        'password' => '',
        'description' => 'Local WordPress installation'
    ],
    'staging_wordpress' => [
        'host' => 'staging.example.com',
        'port' => 3306,
        'database' => 'staging_wp',
        'username' => 'wp_user',
        'password' => 'secure_password',
        'description' => 'Staging WordPress site'
    ]
];

// Check for command line arguments
$configName = $argv[1] ?? null;
$customConfig = null;

if ($argc >= 6) {
    // Custom config from command line: host database username password [port]
    $customConfig = [
        'host' => $argv[1],
        'database' => $argv[2],
        'username' => $argv[3],
        'password' => $argv[4],
        'port' => isset($argv[5]) ? (int)$argv[5] : 3306,
        'description' => 'Custom database from command line'
    ];
    $configName = 'custom';
}

if (!$customConfig && !$configName) {
    echo "📝 Usage Instructions:\n";
    echo "======================\n\n";
    
    echo "Option 1 - Use predefined config:\n";
    echo "php test-live-wordpress-analysis.php [config_name]\n\n";
    
    echo "Available configs:\n";
    foreach ($sampleConfigs as $name => $config) {
        echo "  • {$name}: {$config['description']}\n";
        echo "    Host: {$config['host']}, Database: {$config['database']}\n";
    }
    echo "\n";
    
    echo "Option 2 - Use custom database:\n";
    echo "php test-live-wordpress-analysis.php host database username password [port]\n\n";
    
    echo "Examples:\n";
    echo "php test-live-wordpress-analysis.php local_wordpress\n";
    echo "php test-live-wordpress-analysis.php localhost wp_database root mypassword 3306\n\n";
    
    echo "💡 Testing with Mock Connection (No Real Database):\n";
    echo "===================================================\n";
    
    // Test the connection manager and analyzer without a real database
    testWithoutDatabase();
    exit(0);
}

// Use the selected configuration
$config = $customConfig ?? $sampleConfigs[$configName] ?? null;

if (!$config) {
    echo "❌ Error: Unknown configuration '{$configName}'\n";
    echo "Available configurations: " . implode(', ', array_keys($sampleConfigs)) . "\n";
    exit(1);
}

echo "🔌 Testing WordPress Database Connection\n";
echo "Database: {$config['database']} on {$config['host']}\n";
echo "Description: {$config['description']}\n\n";

try {
    // Initialize components
    $connectionManager = new DatabaseConnectionManager();
    $analyzer = new LiveWordPressAnalyzer($connectionManager);
    
    echo "1. Testing Database Connection...\n";
    
    // Test connection first
    $testResult = $connectionManager->testConnection($config);
    
    if (!$testResult['success']) {
        echo "❌ Connection failed: {$testResult['error']}\n\n";
        
        echo "💡 Troubleshooting Tips:\n";
        echo "• Check if MySQL/MariaDB is running\n";
        echo "• Verify database credentials\n";
        echo "• Ensure database exists\n";
        echo "• Check network connectivity (for remote databases)\n";
        echo "• Verify port is correct (default: 3306)\n";
        exit(1);
    }
    
    echo "✅ Connection successful!\n";
    echo "   Connection time: {$testResult['connection_time']}ms\n";
    echo "   MySQL version: {$testResult['database_info']['mysql_version']}\n";
    echo "   Database: {$testResult['database_info']['database_name']}\n";
    echo "   WordPress tables found: {$testResult['database_info']['wordpress_tables_found']}\n";
    echo "   WordPress prefix: '{$testResult['database_info']['wordpress_prefix']}'\n\n";
    
    if ($testResult['database_info']['wordpress_tables_found'] === 0) {
        echo "⚠️  Warning: No WordPress tables detected!\n";
        echo "This might not be a WordPress database or tables use a different prefix.\n\n";
        
        echo "Tables found:\n";
        foreach ($testResult['database_info']['tables_found'] as $table) {
            echo "  • {$table}\n";
        }
        exit(1);
    }
    
    echo "2. Connecting to WordPress Database...\n";
    
    // Connect to the WordPress database
    $analyzer->connect('main', $config);
    
    echo "✅ Connected to WordPress database!\n\n";
    
    // Get basic WordPress info
    $wpInfo = $analyzer->getConnectionInfo();
    if (!empty($wpInfo['wordpress_info'])) {
        $info = $wpInfo['wordpress_info'];
        echo "🏠 WordPress Site Information:\n";
        echo "   Site Name: " . ($info['site_name'] ?? 'Unknown') . "\n";
        echo "   Site URL: " . ($info['site_url'] ?? 'Unknown') . "\n";
        echo "   Database Version: " . ($info['db_version'] ?? 'Unknown') . "\n";
        echo "   Table Prefix: '{$info['prefix']}'\n\n";
    }
    
    echo "3. Getting Post Type Overview...\n";
    
    // Get post type counts
    $postTypeCounts = $analyzer->getPostTypeCounts();
    echo "📊 Post Types Found:\n";
    foreach ($postTypeCounts as $postType => $count) {
        echo "   • {$postType}: " . number_format($count) . " posts\n";
    }
    echo "\n";
    
    echo "4. Previewing Custom Fields...\n";
    
    // Get custom fields preview
    $customFields = $analyzer->getCustomFieldsPreview(null, 20);
    if (!empty($customFields)) {
        echo "🎨 Top Custom Fields (non-internal):\n";
        foreach ($customFields as $field) {
            $avgLength = round($field['avg_value_length'], 1);
            $sample = substr($field['sample_value'], 0, 30) . (strlen($field['sample_value']) > 30 ? '...' : '');
            echo "   • {$field['meta_key']}: {$field['usage_count']} uses, avg {$avgLength} chars\n";
            echo "     Sample: \"{$sample}\"\n";
        }
        echo "\n";
    } else {
        echo "   No custom fields found (non-internal)\n\n";
    }
    
    echo "5. Running Performance Test...\n";
    
    // Test database performance
    $perfTest = $analyzer->testPerformance();
    echo "⚡ Performance Results:\n";
    foreach ($perfTest['performance_tests'] as $testName => $result) {
        echo "   • {$testName}: {$result['duration_ms']}ms\n";
    }
    
    if (!empty($perfTest['recommendations'])) {
        echo "\n💡 Performance Recommendations:\n";
        foreach ($perfTest['recommendations'] as $rec) {
            echo "   • {$rec}\n";
        }
    }
    echo "\n";
    
    // Analyze most common post type in detail
    if (!empty($postTypeCounts)) {
        $topPostType = array_key_first($postTypeCounts);
        $topCount = $postTypeCounts[$topPostType];
        
        echo "6. Analyzing Top Post Type: '{$topPostType}' ({$topCount} posts)\n";
        
        $postTypeAnalysis = $analyzer->analyzePostType($topPostType);
        
        echo "📌 {$topPostType} Analysis:\n";
        echo "   Posts: " . number_format($postTypeAnalysis['post_count']) . "\n";
        
        $schema = $postTypeAnalysis['field_analysis'];
        if (!empty($schema['custom_fields'])) {
            echo "   Custom Fields: " . count($schema['custom_fields']) . "\n";
            echo "   Database Insights:\n";
            $dbInsights = $postTypeAnalysis['database_insights'];
            echo "     • Meta records: " . number_format($dbInsights['meta_count']) . "\n";
            echo "     • Avg meta per post: {$dbInsights['avg_meta_per_post']}\n";
            
            echo "\n   🔥 Top Custom Fields:\n";
            $topFields = array_slice($schema['custom_fields'], 0, 5, true);
            foreach ($topFields as $fieldName => $field) {
                $coverage = $field['coverage_percentage'];
                $type = $field['type'];
                echo "     • {$fieldName} ({$type}) - {$coverage}% coverage\n";
            }
        } else {
            echo "   No custom fields detected\n";
        }
        echo "\n";
        
        // Migration strategy
        $strategy = $postTypeAnalysis['migration_strategy'];
        echo "   🏗️  Migration Strategy:\n";
        echo "     • Approach: {$strategy['approach']}\n";
        echo "     • Batch size: {$strategy['batch_size']}\n";
        echo "     • Priority: {$strategy['priority']}\n";
        
        if (!empty($strategy['special_handling'])) {
            echo "     • Special handling:\n";
            foreach ($strategy['special_handling'] as $handling) {
                echo "       - {$handling}\n";
            }
        }
        echo "\n";
    }
    
    // Quick sample analysis for fast overview
    echo "7. Running Quick Sample Analysis (1000 recent posts)...\n";
    
    $sampleAnalysis = $analyzer->analyzeSample(1000);
    $fullEstimate = $sampleAnalysis['estimated_full_size'];
    
    echo "📊 Sample Analysis Results:\n";
    echo "   Sample size: " . number_format($sampleAnalysis['sample_size']) . " posts\n";
    echo "   Estimated total: " . number_format($fullEstimate['total_posts']) . " posts\n";
    echo "   Estimated meta records: " . number_format($fullEstimate['total_postmeta']) . "\n";
    echo "   Estimated migration time: {$fullEstimate['estimated_migration_time']}\n\n";
    
    // Migration recommendations
    $recommendations = $sampleAnalysis['migration_recommendations'];
    if (!empty($recommendations)) {
        echo "💡 Migration Recommendations:\n";
        foreach ($recommendations as $rec) {
            $priority = strtoupper($rec['priority']);
            echo "   [{$priority}] {$rec['message']}\n";
        }
        echo "\n";
    }
    
    echo "✅ Live WordPress Analysis Complete!\n";
    echo "====================================\n\n";
    
    echo "🎯 Ready for WordPress-to-WordPress Migration:\n";
    echo "• Source database analyzed and compatible\n";
    echo "• Post types and custom fields mapped\n";
    echo "• Performance characteristics understood\n";
    echo "• Migration strategy recommendations provided\n\n";
    
    echo "Next steps:\n";
    echo "1. Set up target WordPress database\n";
    echo "2. Use this analysis to plan migration batches\n";
    echo "3. Implement custom field mappings\n";
    echo "4. Execute migration with monitoring\n";
    
} catch (Exception $e) {
    echo "❌ Error during analysis: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

function testWithoutDatabase(): void
{
    echo "Testing components without real database connection...\n\n";
    
    try {
        // Test DatabaseConnectionManager
        $connectionManager = new DatabaseConnectionManager();
        echo "✅ DatabaseConnectionManager initialized\n";
        
        // Test LiveWordPressAnalyzer
        $analyzer = new LiveWordPressAnalyzer($connectionManager);
        echo "✅ LiveWordPressAnalyzer initialized\n";
        
        // Test connection validation
        try {
            $connectionManager->testConnection([
                'host' => 'invalid_host',
                'database' => 'test',
                'username' => 'test',
                'password' => 'test'
            ]);
        } catch (Exception $e) {
            echo "✅ Connection validation working (correctly rejected invalid config)\n";
        }
        
        echo "\n🎉 All components initialized successfully!\n";
        echo "Ready to test with real WordPress database when available.\n\n";
        
        echo "💡 To test with a real database, provide connection details:\n";
        echo "php test-live-wordpress-analysis.php localhost your_db_name username password\n";
        
    } catch (Exception $e) {
        echo "❌ Component test failed: " . $e->getMessage() . "\n";
    }
}