<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Support\PostTypeAnalyzer;
use Crumbls\Importer\Support\WordPressXmlParser;
use Crumbls\Importer\Support\SqlDumpParser;
use Crumbls\Importer\Support\CsvTsvParser;
use Crumbls\Importer\Support\DatabaseConnectionManager;
use Crumbls\Importer\Support\LiveWordPressAnalyzer;

/**
 * Test All Data Source Formats with Post Type Analysis
 */

echo "🔍 Testing All WordPress Data Source Formats\n";
echo "=============================================\n\n";

// Check for command line arguments
$testType = $argv[1] ?? null;
$filePath = $argv[2] ?? null;

if (!$testType) {
    echo "📝 Usage Instructions:\n";
    echo "======================\n\n";
    
    echo "Test specific data source:\n";
    echo "php test-post-type-analyzer.php [type] [file/connection]\n\n";
    
    echo "Available test types:\n";
    echo "• xml [file.xml]     - Test WordPress XML export\n";
    echo "• sql [file.sql]     - Test SQL dump file\n";
    echo "• csv [file.csv]     - Test CSV/TSV data file\n";
    echo "• live [host] [db] [user] [pass] [port] - Test live database\n";
    echo "• sample             - Test with built-in sample data\n";
    echo "• all                - Test all formats with sample files\n\n";
    
    echo "Examples:\n";
    echo "php test-post-type-analyzer.php xml wordpress-export.xml\n";
    echo "php test-post-type-analyzer.php sql database-dump.sql\n";
    echo "php test-post-type-analyzer.php csv products.csv\n";
    echo "php test-post-type-analyzer.php live localhost wp_db root password 3306\n";
    echo "php test-post-type-analyzer.php sample\n";
    echo "php test-post-type-analyzer.php all\n\n";
    
    echo "💡 Testing with Built-in Sample Data:\n";
    echo "====================================\n";
    testSampleData();
    exit(0);
}

switch ($testType) {
    case 'xml':
        if (!$filePath) {
            echo "❌ Error: XML file path required\n";
            echo "Usage: php test-post-type-analyzer.php xml /path/to/file.xml\n";
            exit(1);
        }
        testXmlFile($filePath);
        break;
        
    case 'sql':
        if (!$filePath) {
            echo "❌ Error: SQL file path required\n";
            echo "Usage: php test-post-type-analyzer.php sql /path/to/dump.sql\n";
            exit(1);
        }
        testSqlFile($filePath);
        break;
        
    case 'csv':
        if (!$filePath) {
            echo "❌ Error: CSV file path required\n";
            echo "Usage: php test-post-type-analyzer.php csv /path/to/data.csv\n";
            exit(1);
        }
        testCsvFile($filePath);
        break;
        
    case 'live':
        $host = $argv[2] ?? null;
        $database = $argv[3] ?? null;
        $username = $argv[4] ?? null;
        $password = $argv[5] ?? null;
        $port = $argv[6] ?? 3306;
        
        if (!$host || !$database || !$username || !$password) {
            echo "❌ Error: Live database connection details required\n";
            echo "Usage: php test-post-type-analyzer.php live host database username password [port]\n";
            exit(1);
        }
        
        testLiveDatabase($host, $database, $username, $password, $port);
        break;
        
    case 'sample':
        testSampleData();
        break;
        
    case 'all':
        testAllFormats();
        break;
        
    default:
        echo "❌ Error: Unknown test type '{$testType}'\n";
        echo "Available types: xml, sql, csv, live, sample, all\n";
        exit(1);
}

function testXmlFile(string $filePath): void
{
    echo "🔍 Testing WordPress XML Export: " . basename($filePath) . "\n";
    echo str_repeat('=', 60) . "\n\n";
    
    if (!file_exists($filePath)) {
        echo "❌ Error: XML file not found at: {$filePath}\n";
        exit(1);
    }
    
    try {
        $parser = new WordPressXmlParser();
        $xmlData = $parser->parseFile($filePath);
        
        echo "📊 XML Parsing Results:\n";
        echo "   Posts parsed: " . count($xmlData['posts']) . "\n";
        echo "   Postmeta entries: " . count($xmlData['postmeta']) . "\n";
        echo "   Comments: " . count($xmlData['comments']) . "\n";
        echo "   Users: " . count($xmlData['users']) . "\n\n";
        
        analyzeData($xmlData, 'WordPress XML Export');
        
    } catch (Exception $e) {
        echo "❌ XML parsing failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function testSqlFile(string $filePath): void
{
    echo "🔍 Testing SQL Dump File: " . basename($filePath) . "\n";
    echo str_repeat('=', 60) . "\n\n";
    
    if (!file_exists($filePath)) {
        echo "❌ Error: SQL file not found at: {$filePath}\n";
        exit(1);
    }
    
    try {
        $parser = new SqlDumpParser();
        $sqlData = $parser->parseFile($filePath);
        
        echo "📊 SQL Parsing Results:\n";
        echo "   Posts parsed: " . count($sqlData['posts']) . "\n";
        echo "   Postmeta entries: " . count($sqlData['postmeta']) . "\n\n";
        
        $tables = $parser->getTableSchemas();
        echo "   Database tables found: " . count($tables) . "\n";
        foreach (array_slice(array_keys($tables), 0, 5) as $table) {
            echo "     • {$table}\n";
        }
        echo "\n";
        
        analyzeData($sqlData, 'SQL Database Dump');
        
    } catch (Exception $e) {
        echo "❌ SQL parsing failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function testCsvFile(string $filePath): void
{
    echo "🔍 Testing CSV/TSV File: " . basename($filePath) . "\n";
    echo str_repeat('=', 60) . "\n\n";
    
    if (!file_exists($filePath)) {
        echo "❌ Error: CSV file not found at: {$filePath}\n";
        exit(1);
    }
    
    try {
        $parser = new CsvTsvParser([
            'auto_detect_delimiter' => true,
            'auto_detect_headers' => true,
            'auto_detect_types' => true
        ]);
        
        $csvData = $parser->parseFile($filePath);
        $report = $parser->getParsingReport();
        
        echo "📊 CSV Parsing Results:\n";
        echo "   Rows parsed: " . $report['summary']['rows_parsed'] . "\n";
        echo "   Columns detected: " . $report['summary']['columns_detected'] . "\n";
        echo "   Delimiter detected: '{$report['summary']['delimiter_detected']}'\n";
        echo "   Headers detected: " . ($report['summary']['headers_detected'] ? 'Yes' : 'No') . "\n\n";
        
        if (!empty($report['wordpress_mapping'])) {
            echo "🎯 WordPress Field Mapping:\n";
            foreach ($report['wordpress_mapping'] as $csvField => $wpField) {
                echo "   {$csvField} → {$wpField}\n";
            }
            echo "\n";
        }
        
        analyzeData($csvData, 'CSV/TSV Data File');
        
    } catch (Exception $e) {
        echo "❌ CSV parsing failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function testLiveDatabase(string $host, string $database, string $username, string $password, int $port): void
{
    echo "🔍 Testing Live WordPress Database\n";
    echo "   Host: {$host}\n";
    echo "   Database: {$database}\n";
    echo str_repeat('=', 60) . "\n\n";
    
    try {
        $connectionManager = new DatabaseConnectionManager();
        $analyzer = new LiveWordPressAnalyzer($connectionManager);
        
        $config = [
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'port' => $port
        ];
        
        // Test connection
        echo "📡 Testing database connection...\n";
        $testResult = $connectionManager->testConnection($config);
        
        if (!$testResult['success']) {
            echo "❌ Connection failed: {$testResult['error']}\n";
            exit(1);
        }
        
        echo "✅ Connected successfully!\n";
        echo "   MySQL version: {$testResult['database_info']['mysql_version']}\n";
        echo "   WordPress tables: {$testResult['database_info']['wordpress_tables_found']}\n\n";
        
        // Connect and analyze
        $analyzer->connect('main', $config);
        $analysis = $analyzer->analyzeSample(1000); // Sample for speed
        
        echo "📊 Live Database Analysis:\n";
        echo "   Sample size: {$analysis['sample_size']} posts\n";
        echo "   Estimated total: " . number_format($analysis['estimated_full_size']['total_posts']) . " posts\n\n";
        
        // Convert to compatible format for unified analysis
        $liveData = [
            'posts' => [],
            'postmeta' => []
        ];
        
        // Get sample data in compatible format
        $postTypes = $analyzer->getPostTypeCounts();
        echo "📌 Post Types Found:\n";
        foreach ($postTypes as $type => $count) {
            echo "   • {$type}: " . number_format($count) . " posts\n";
        }
        echo "\n";
        
        displayLiveAnalysisResults($analysis);
        
    } catch (Exception $e) {
        echo "❌ Live database analysis failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function testAllFormats(): void
{
    echo "🔍 Testing All Data Source Formats\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "1. Testing Sample Data Format...\n";
    testSampleData();
    
    echo "\n" . str_repeat('-', 60) . "\n\n";
    
    echo "2. Testing CSV Format with Sample Files...\n";
    
    // Create and test sample CSV files
    createSampleFiles();
    
    $csvFiles = [
        'posts' => __DIR__ . '/sample-posts.csv',
        'products' => __DIR__ . '/sample-products.tsv',
        'events' => __DIR__ . '/sample-events.psv'
    ];
    
    foreach ($csvFiles as $description => $file) {
        if (file_exists($file)) {
            echo "\n📊 Testing {$description} CSV:\n";
            testCsvFile($file);
        }
    }
    
    echo "\n" . str_repeat('-', 60) . "\n\n";
    
    echo "✅ All Format Testing Complete!\n";
    echo "================================\n\n";
    
    echo "🎯 Summary:\n";
    echo "• ✅ Sample Data Format - Working\n";
    echo "• ✅ CSV/TSV Format - Working\n";
    echo "• ⚠️  XML Format - Requires file path\n";
    echo "• ⚠️  SQL Format - Requires file path\n";
    echo "• ⚠️  Live Database - Requires connection details\n\n";
    
    echo "💡 Next Steps:\n";
    echo "1. Test with real XML: php test-post-type-analyzer.php xml your-export.xml\n";
    echo "2. Test with SQL dump: php test-post-type-analyzer.php sql your-dump.sql\n";
    echo "3. Test live database: php test-post-type-analyzer.php live host db user pass\n";
}

function createSampleFiles(): void
{
    // Sample Posts CSV
    $postsCSV = <<<CSV
post_title,post_content,post_type,post_status,post_date,category,seo_title,seo_description
"WordPress Tutorial","Complete guide to WordPress basics","post","publish","2024-01-15 10:00:00","Tutorial","WordPress Guide","Learn WordPress basics"
"Advanced Tips","Power user techniques","post","publish","2024-01-16 14:30:00","Advanced","Pro Tips","Advanced WordPress techniques"
CSV;
    
    // Sample Products TSV
    $productsTSV = <<<TSV
post_title	post_content	post_type	post_status	product_price	product_sku	product_color	product_brand
Premium T-Shirt	Comfortable cotton t-shirt	product	publish	29.99	TSHIRT-001	Blue	EcoWear
Vintage Jeans	Classic denim jeans	product	publish	79.99	JEANS-002	Black	UrbanStyle
TSV;
    
    // Sample Events PSV
    $eventsPSV = <<<CSV
title|content|type|status|event_date|event_location|event_price|event_capacity
Tech Conference|Technology conference event|event|publish|2024-08-15|Convention Center|299.00|500
Music Festival|Summer music event|event|publish|2024-07-20|Central Park|89.00|2000
CSV;
    
    file_put_contents(__DIR__ . '/sample-posts.csv', $postsCSV);
    file_put_contents(__DIR__ . '/sample-products.tsv', $productsTSV);
    file_put_contents(__DIR__ . '/sample-events.psv', $eventsPSV);
}

function testSampleData(): void
{
    echo "🔍 Testing Built-in Sample Data\n";
    echo str_repeat('=', 60) . "\n\n";

// Sample WordPress data with various post types and custom fields
$sampleData = [
    'posts' => [
        // Regular blog posts
        ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Sample Blog Post 1'],
        ['ID' => 2, 'post_type' => 'post', 'post_title' => 'Sample Blog Post 2'],
        ['ID' => 3, 'post_type' => 'post', 'post_title' => 'Sample Blog Post 3'],
        
        // Products (WooCommerce)
        ['ID' => 4, 'post_type' => 'product', 'post_title' => 'Awesome T-Shirt'],
        ['ID' => 5, 'post_type' => 'product', 'post_title' => 'Cool Sneakers'],
        ['ID' => 6, 'post_type' => 'product', 'post_title' => 'Vintage Jeans'],
        
        // Events (Custom Post Type)
        ['ID' => 7, 'post_type' => 'event', 'post_title' => 'Summer Festival'],
        ['ID' => 8, 'post_type' => 'event', 'post_title' => 'Tech Conference'],
        
        // Team Members (Custom Post Type)
        ['ID' => 9, 'post_type' => 'team_member', 'post_title' => 'John Doe'],
        ['ID' => 10, 'post_type' => 'team_member', 'post_title' => 'Jane Smith'],
    ],
    'postmeta' => [
        // Blog post meta (SEO)
        ['post_id' => 1, 'meta_key' => 'seo_title', 'meta_value' => 'Optimized Title 1'],
        ['post_id' => 1, 'meta_key' => 'seo_description', 'meta_value' => 'Great description'],
        ['post_id' => 2, 'meta_key' => 'seo_title', 'meta_value' => 'Optimized Title 2'],
        ['post_id' => 2, 'meta_key' => 'seo_description', 'meta_value' => 'Another description'],
        ['post_id' => 3, 'meta_key' => 'seo_title', 'meta_value' => 'Optimized Title 3'],
        
        // Product meta (WooCommerce)
        ['post_id' => 4, 'meta_key' => '_price', 'meta_value' => '29.99'],
        ['post_id' => 4, 'meta_key' => '_regular_price', 'meta_value' => '29.99'],
        ['post_id' => 4, 'meta_key' => '_stock', 'meta_value' => '50'],
        ['post_id' => 4, 'meta_key' => 'product_color', 'meta_value' => 'Blue'],
        ['post_id' => 4, 'meta_key' => 'product_size', 'meta_value' => 'Medium'],
        
        ['post_id' => 5, 'meta_key' => '_price', 'meta_value' => '89.99'],
        ['post_id' => 5, 'meta_key' => '_regular_price', 'meta_value' => '99.99'],
        ['post_id' => 5, 'meta_key' => '_sale_price', 'meta_value' => '89.99'],
        ['post_id' => 5, 'meta_key' => '_stock', 'meta_value' => '25'],
        ['post_id' => 5, 'meta_key' => 'product_color', 'meta_value' => 'Red'],
        ['post_id' => 5, 'meta_key' => 'product_size', 'meta_value' => '9'],
        ['post_id' => 5, 'meta_key' => 'product_brand', 'meta_value' => 'Nike'],
        
        ['post_id' => 6, 'meta_key' => '_price', 'meta_value' => '79.99'],
        ['post_id' => 6, 'meta_key' => '_regular_price', 'meta_value' => '79.99'],
        ['post_id' => 6, 'meta_key' => '_stock', 'meta_value' => '15'],
        ['post_id' => 6, 'meta_key' => 'product_color', 'meta_value' => 'Black'],
        ['post_id' => 6, 'meta_key' => 'product_material', 'meta_value' => 'Denim'],
        
        // Event meta (ACF-style)
        ['post_id' => 7, 'meta_key' => 'event_date', 'meta_value' => '2024-08-15'],
        ['post_id' => 7, 'meta_key' => 'event_location', 'meta_value' => 'Central Park'],
        ['post_id' => 7, 'meta_key' => 'event_price', 'meta_value' => '45.00'],
        ['post_id' => 7, 'meta_key' => 'event_capacity', 'meta_value' => '500'],
        ['post_id' => 7, 'meta_key' => 'event_organizer', 'meta_value' => 'Event Corp'],
        
        ['post_id' => 8, 'meta_key' => 'event_date', 'meta_value' => '2024-09-20'],
        ['post_id' => 8, 'meta_key' => 'event_location', 'meta_value' => 'Convention Center'],
        ['post_id' => 8, 'meta_key' => 'event_price', 'meta_value' => '299.00'],
        ['post_id' => 8, 'meta_key' => 'event_capacity', 'meta_value' => '1000'],
        ['post_id' => 8, 'meta_key' => 'event_sponsors', 'meta_value' => '["TechCorp", "DevCompany", "CodeStudio"]'],
        
        // Team member meta (ACF-style)
        ['post_id' => 9, 'meta_key' => 'position', 'meta_value' => 'CEO'],
        ['post_id' => 9, 'meta_key' => 'bio', 'meta_value' => 'John has 15 years of experience...'],
        ['post_id' => 9, 'meta_key' => 'photo', 'meta_value' => 'john-doe.jpg'],
        ['post_id' => 9, 'meta_key' => 'email', 'meta_value' => 'john@company.com'],
        ['post_id' => 9, 'meta_key' => 'linkedin', 'meta_value' => 'https://linkedin.com/in/johndoe'],
        ['post_id' => 9, 'meta_key' => 'years_experience', 'meta_value' => '15'],
        
        ['post_id' => 10, 'meta_key' => 'position', 'meta_value' => 'CTO'],
        ['post_id' => 10, 'meta_key' => 'bio', 'meta_value' => 'Jane is a tech expert with deep knowledge...'],
        ['post_id' => 10, 'meta_key' => 'photo', 'meta_value' => 'jane-smith.jpg'],
        ['post_id' => 10, 'meta_key' => 'email', 'meta_value' => 'jane@company.com'],
        ['post_id' => 10, 'meta_key' => 'github', 'meta_value' => 'https://github.com/janesmith'],
        ['post_id' => 10, 'meta_key' => 'years_experience', 'meta_value' => '12'],
    ]
];

try {
    // Create analyzer and run analysis
    $analyzer = new PostTypeAnalyzer();
    $analyzer->analyze($sampleData);
    
    // Display results
    echo "📊 Post Type Analysis Results\n";
    echo "-----------------------------\n\n";
    
    // Summary
    $stats = $analyzer->getStatistics();
    echo "📈 Overall Statistics:\n";
    echo "  - Total Post Types: {$stats['total_post_types']}\n";
    echo "  - Total Posts: {$stats['total_posts']}\n";
    echo "  - Average Fields per Type: {$stats['average_fields_per_type']}\n\n";
    
    // Plugin Detection
    echo "🔌 Plugin Detection:\n";
    foreach ($stats['plugin_detection'] as $plugin => $detected) {
        $status = $detected ? '✅ Detected' : '❌ Not found';
        echo "  - " . ucfirst($plugin) . ": {$status}\n";
    }
    echo "\n";
    
    // Post Type Details
    echo "📋 Post Type Details:\n";
    echo "====================\n\n";
    
    foreach ($analyzer->getPostTypes() as $postType) {
        $schema = $analyzer->getSchema($postType);
        $posts = $analyzer->getPostsForType($postType);
        
        echo "🏷️  {$postType} ({$schema['post_count']} posts)\n";
        echo "   " . str_repeat('-', strlen($postType) + 15) . "\n";
        
        if (!empty($schema['fields'])) {
            echo "   📝 Custom Fields:\n";
            
            foreach ($schema['fields'] as $field) {
                $coverage = $field['coverage_percentage'];
                $type = $field['type'];
                $importance = $field['is_common'] ? 'COMMON' : ($field['is_occasional'] ? 'OCCASIONAL' : 'RARE');
                
                echo "      • {$field['name']} ({$type}) - {$coverage}% coverage [{$importance}]\n";
                
                if (!empty($field['analysis']['pattern_hints'])) {
                    foreach ($field['analysis']['pattern_hints'] as $hint) {
                        echo "        💡 {$hint}\n";
                    }
                }
                
                if (!empty($field['sample_values'])) {
                    $samples = implode(', ', array_slice($field['sample_values'], 0, 3));
                    echo "        📄 Sample: {$samples}\n";
                }
            }
            
            echo "\n   🏗️  Suggested Migration Schema:\n";
            $migrationSchema = $schema['migration_schema'];
            echo "      Table: {$migrationSchema['table_name']}\n";
            
            if (!empty($migrationSchema['common_fields'])) {
                echo "      Required fields:\n";
                foreach ($migrationSchema['common_fields'] as $field) {
                    echo "        - {$field['name']} ({$field['type']})\n";
                }
            }
            
            if (!empty($migrationSchema['optional_fields'])) {
                echo "      Optional fields:\n";
                foreach ($migrationSchema['optional_fields'] as $field) {
                    echo "        - {$field['name']} ({$field['type']}, nullable)\n";
                }
            }
            
            echo "\n   🎯 Model Attributes:\n";
            $attributes = $schema['model_attributes'];
            echo "      Fillable: " . implode(', ', array_slice($attributes['fillable'], 0, 5)) . "\n";
            if (!empty($attributes['casts'])) {
                echo "      Casts: " . json_encode($attributes['casts']) . "\n";
            }
        } else {
            echo "   ℹ️  No custom fields detected\n";
        }
        
        echo "\n";
    }
    
    // Recommendations
    $report = $analyzer->getDetailedReport();
    if (!empty($report['recommendations'])) {
        echo "💡 Recommendations:\n";
        echo "==================\n";
        foreach ($report['recommendations'] as $recommendation) {
            echo "• {$recommendation}\n";
        }
        echo "\n";
    }
    
    // Field Analysis Summary
    echo "🔍 Field Analysis Summary:\n";
    echo "=========================\n";
    $fieldAnalysis = $report['field_analysis'];
    
    echo "Field Types Distribution:\n";
    foreach ($fieldAnalysis['field_types_distribution'] as $type => $count) {
        echo "  - {$type}: {$count} fields\n";
    }
    
    echo "\nField Coverage Distribution:\n";
    foreach ($fieldAnalysis['coverage_distribution'] as $coverage => $count) {
        echo "  - {$coverage}: {$count} fields\n";
    }
    
    if (!empty($fieldAnalysis['plugin_field_counts'])) {
        echo "\nPlugin Field Counts:\n";
        foreach ($fieldAnalysis['plugin_field_counts'] as $plugin => $count) {
            echo "  - {$plugin}: {$count} fields\n";
        }
    }
    
    echo "\n✅ Post Type Analysis Complete!\n";
    echo "================================\n\n";
    
    echo "🎯 Next Steps:\n";
    echo "1. Review the suggested migration schemas\n";
    echo "2. Test with your real WordPress XML file\n";
    echo "3. Generate Laravel models based on the analysis\n";
    echo "4. Implement custom field migration logic\n";
    
} catch (Exception $e) {
    echo "❌ Analysis failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

function analyzeData(array $data, string $source): void
{
    echo "📊 Analyzing {$source}...\n";
    echo str_repeat('-', 40) . "\n\n";
    
    $analyzer = new PostTypeAnalyzer();
    $analyzer->analyze($data);
    
    // Summary
    $stats = $analyzer->getStatistics();
    echo "📈 Analysis Summary:\n";
    echo "   Post Types: {$stats['total_post_types']}\n";
    echo "   Total Posts: {$stats['total_posts']}\n";
    echo "   Avg Fields per Type: {$stats['average_fields_per_type']}\n\n";
    
    // Plugin Detection
    echo "🔌 Plugin Detection:\n";
    foreach ($stats['plugin_detection'] as $plugin => $detected) {
        $status = $detected ? '✅ DETECTED' : '⚪ Not Found';
        $name = match($plugin) {
            'acf' => 'Advanced Custom Fields',
            'woocommerce' => 'WooCommerce',
            'yoast' => 'Yoast SEO',
            'elementor' => 'Elementor',
            'custom_fields' => 'Custom Fields',
            default => ucfirst($plugin)
        };
        echo "   {$name}: {$status}\n";
    }
    echo "\n";
    
    // Post Type Breakdown
    echo "📌 Post Type Analysis:\n";
    foreach ($analyzer->getPostTypes() as $postType) {
        $schema = $analyzer->getSchema($postType);
        $customFields = count($schema['custom_fields'] ?? []);
        echo "   • {$postType}: {$schema['post_count']} posts, {$customFields} custom fields\n";
    }
    echo "\n";
    
    displayDetailedAnalysis($analyzer);
}

function displayDetailedAnalysis(PostTypeAnalyzer $analyzer): void
{
    echo "🔍 Detailed Field Analysis:\n";
    echo str_repeat('-', 40) . "\n";
    
    foreach ($analyzer->getPostTypes() as $postType) {
        $schema = $analyzer->getSchema($postType);
        
        echo "\n📌 {$postType} Analysis:\n";
        
        if (!empty($schema['custom_fields'])) {
            $topFields = array_slice($schema['custom_fields'], 0, 5, true);
            foreach ($topFields as $fieldName => $field) {
                $coverage = $field['coverage_percentage'];
                $type = $field['type'];
                $icon = $field['is_common'] ? '🟢' : ($field['is_occasional'] ? '🟡' : '🔴');
                
                echo "   {$icon} {$fieldName} ({$type}) - {$coverage}% coverage\n";
                
                if (!empty($field['sample_values'])) {
                    $samples = array_slice($field['sample_values'], 0, 2);
                    $sampleStr = implode(', ', array_map(fn($s) => '"' . substr($s, 0, 20) . '"', $samples));
                    echo "      📄 Examples: {$sampleStr}\n";
                }
            }
            
            // Migration suggestion
            $migration = $schema['migration_schema'];
            echo "\n   🏗️  Migration Table: {$migration['table_name']}\n";
            
            if (!empty($migration['common_fields'])) {
                echo "   Required columns:\n";
                foreach (array_slice($migration['common_fields'], 0, 3) as $field) {
                    $type = $field['type'];
                    $length = isset($field['length']) ? "({$field['length']})" : '';
                    echo "      \$table->{$type}('{$field['name']}'){$length};\n";
                }
            }
        }
    }
}

function displayLiveAnalysisResults(array $analysis): void
{
    echo "📊 Live Database Post Type Analysis:\n";
    echo str_repeat('-', 40) . "\n\n";
    
    $postTypeAnalysis = $analysis['post_type_analysis'];
    $stats = $postTypeAnalysis['statistics'];
    
    echo "📈 Live Database Summary:\n";
    echo "   Post Types: {$stats['total_post_types']}\n";
    echo "   Sample Posts: {$stats['total_posts']}\n";
    echo "   Avg Fields per Type: {$stats['average_fields_per_type']}\n\n";
    
    // Plugin Detection  
    echo "🔌 WordPress Plugins Detected:\n";
    foreach ($stats['plugin_detection'] as $plugin => $detected) {
        $status = $detected ? '✅ DETECTED' : '⚪ Not Found';
        $name = match($plugin) {
            'acf' => 'Advanced Custom Fields',
            'woocommerce' => 'WooCommerce', 
            'yoast' => 'Yoast SEO',
            'elementor' => 'Elementor',
            'custom_fields' => 'Custom Fields',
            default => ucfirst($plugin)
        };
        echo "   {$name}: {$status}\n";
    }
    echo "\n";
    
    // Migration Recommendations
    if (!empty($analysis['migration_recommendations'])) {
        echo "💡 Live Database Migration Recommendations:\n";
        foreach ($analysis['migration_recommendations'] as $rec) {
            $priority = strtoupper($rec['priority']);
            echo "   [{$priority}] {$rec['message']}\n";
        }
        echo "\n";
    }
    
    echo "✅ Live WordPress analysis complete!\n";
}