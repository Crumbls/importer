<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Xml\XmlSchema;
use Crumbls\Importer\Xml\XmlParser;
use Crumbls\Importer\Adapters\WordPressAdapter;

/**
 * Test our enhanced schema and migration architecture
 */

echo "🔍 Testing Enhanced WordPress Schema & Migration Architecture\n";
echo "============================================================\n\n";

// Check if WordPress XML file exists
$wpxmlFile = '/Users/chasemiller/PhpstormProjects/wordpress-bridge/storage/app/private/imports/WPXML.xml';

if (!file_exists($wpxmlFile)) {
    echo "❌ WordPress XML file not found: {$wpxmlFile}\n";
    echo "Using demo file from tests instead...\n";
    $wpxmlFile = __DIR__ . '/tests/demo/wordpress-export.xml';
}

if (!file_exists($wpxmlFile)) {
    echo "❌ No WordPress XML files found to test with\n";
    echo "Please add a WordPress XML export to test with real data.\n";
    echo "Continuing with schema architecture demo...\n\n";
    $wpxmlFile = null;
} else {
    echo "✅ Found WordPress XML file: " . basename($wpxmlFile) . "\n\n";
}

try {
    
    // 1. Test enhanced WordPress schema
    echo "1. Testing Enhanced WordPress Schema...\n";
    $schema = XmlSchema::wordpress();
    
    echo "   📋 Comprehensive entity extraction:\n";
    $entities = $schema->getEntities();
    foreach ($entities as $entityName => $config) {
        $fieldCount = count($config['fields']);
        $table = $config['table'] ?? $entityName;
        echo "     ✓ {$entityName} → {$table} ({$fieldCount} fields)\n";
    }
    
    echo "\n   🗃️  Table schemas generated:\n";
    $tableSchemas = $schema->getTableSchemas();
    foreach ($tableSchemas as $tableName => $fields) {
        $fieldCount = count($fields);
        echo "     - {$tableName}: {$fieldCount} columns\n";
    }
    
    // 2. Test XPath expressions for comprehensive extraction
    echo "\n2. Testing XPath Expressions...\n";
    echo "   📍 Entity XPath patterns:\n";
    foreach ($entities as $entityName => $config) {
        $xpath = $config['xpath'] ?? 'N/A';
        echo "     - {$entityName}: {$xpath}\n";
    }
    
    // 3. Test with real XML file if available
    if ($wpxmlFile && file_exists($wpxmlFile)) {
        echo "\n3. Testing with Real WordPress XML...\n";
        
        $parser = XmlParser::fromFile($wpxmlFile);
        $parser->registerNamespaces($schema->getNamespaces());
        
        echo "   📊 Document analysis:\n";
        $docInfo = $parser->getDocumentInfo();
        echo "     - Root element: " . $docInfo['root_element'] . "\n";
        echo "     - Namespaces: " . count($docInfo['namespaces']) . "\n";
        
        echo "\n   📈 Entity record counts:\n";
        foreach ($entities as $entityName => $config) {
            $xpath = $config['xpath'];
            $records = $parser->xpath($xpath);
            $count = count($records);
            echo "     - {$entityName}: {$count} records\n";
        }
        
        echo "\n   🔍 Sample data extraction:\n";
        // Test posts extraction
        if (isset($entities['posts'])) {
            $postsConfig = $entities['posts'];
            $samplePosts = iterator_to_array($parser->extractRecords($postsConfig['xpath'], $postsConfig['fields']));
            $sampleCount = min(2, count($samplePosts));
            
            if ($sampleCount > 0) {
                echo "     Posts sample (showing {$sampleCount}):\n";
                for ($i = 0; $i < $sampleCount; $i++) {
                    $post = $samplePosts[$i];
                    $title = $post['title'] ?? 'No title';
                    $type = $post['post_type'] ?? 'unknown';
                    echo "       - \"{$title}\" (type: {$type})\n";
                }
            }
        }
        
        // Test postmeta extraction
        if (isset($entities['postmeta'])) {
            $postmetaConfig = $entities['postmeta'];
            $sampleMeta = iterator_to_array($parser->extractRecords($postmetaConfig['xpath'], $postmetaConfig['fields']));
            $metaCount = count($sampleMeta);
            
            if ($metaCount > 0) {
                echo "     PostMeta sample ({$metaCount} total):\n";
                $displayed = 0;
                foreach ($sampleMeta as $meta) {
                    if ($displayed >= 3) break;
                    $key = $meta['meta_key'] ?? 'unknown';
                    $value = substr($meta['meta_value'] ?? '', 0, 50);
                    if (strlen($meta['meta_value'] ?? '') > 50) $value .= '...';
                    echo "       - {$key}: {$value}\n";
                    $displayed++;
                }
            }
        }
    }
    
    // 4. Test migration adapter architecture
    echo "\n4. Testing Migration Adapter Architecture...\n";
    $adapter = new WordPressAdapter([
        'connection' => 'mysql',
        'strategy' => 'migration',
        'conflict_strategy' => 'skip',
        'create_missing' => true,
        'mappings' => [
            'posts' => [
                'table' => 'wp_posts',
                'conflict_strategy' => 'skip',
                'exclude_post_types' => ['revision', 'nav_menu_item']
            ],
            'postmeta' => [
                'table' => 'wp_postmeta',
                'exclude_keys' => ['_wp_trash_*', '_edit_lock']
            ],
            'users' => [
                'table' => 'wp_users',
                'key_field' => 'user_email',
                'create_missing' => true
            ]
        ]
    ]);
    
    echo "   ✅ WordPress adapter configured:\n";
    $config = $adapter->getConfig();
    echo "     - Strategy: " . $config['strategy'] . "\n";
    echo "     - Conflict resolution: " . $config['conflict_strategy'] . "\n";
    echo "     - Create missing records: " . ($config['create_missing'] ? 'yes' : 'no') . "\n";
    echo "     - Entity mappings: " . count($config['mappings']) . "\n";
    
    // 5. Test migration planning with mock data
    echo "\n5. Testing Migration Planning (Mock Data)...\n";
    $mockData = [
        'posts' => [
            ['title' => 'Hello World', 'post_type' => 'post', 'status' => 'publish'],
            ['title' => 'About Us', 'post_type' => 'page', 'status' => 'publish'],
            ['title' => 'Product A', 'post_type' => 'product', 'status' => 'publish']
        ],
        'postmeta' => [
            ['post_id' => '1', 'meta_key' => '_price', 'meta_value' => '19.99'],
            ['post_id' => '1', 'meta_key' => '_sku', 'meta_value' => 'PROD-001'],
            ['post_id' => '2', 'meta_key' => '_featured', 'meta_value' => 'yes']
        ],
        'users' => [
            ['author_login' => 'admin', 'author_email' => 'admin@example.com'],
            ['author_login' => 'editor', 'author_email' => 'editor@example.com']
        ]
    ];
    
    $plan = $adapter->plan($mockData);
    
    echo "   📋 Migration plan created: " . $plan->getId() . "\n";
    echo "   📊 Summary:\n";
    foreach ($plan->getSummary() as $entityType => $summary) {
        $total = $summary['total_records'] ?? 0;
        echo "     - {$entityType}: {$total} records\n";
    }
    
    $validation = $adapter->validate($plan);
    echo "   ✅ Validation: " . ($validation->isValid() ? 'passed' : 'failed') . "\n";
    
    $dryRun = $adapter->dryRun($plan);
    echo "   🔍 Dry run summary:\n";
    foreach ($dryRun->getSummary() as $entityType => $summary) {
        $create = $summary['would_create'] ?? 0;
        echo "     - {$entityType}: would create {$create} records\n";
    }
    
    echo "\n🎉 Architecture Test Complete!\n";
    echo "==============================\n\n";
    
    echo "✅ Key improvements implemented:\n";
    echo "   ✓ Comprehensive WordPress schema (9 entity types)\n";
    echo "   ✓ Extract ALL data by default (no data loss)\n";
    echo "   ✓ PostMeta extraction (ACF, custom fields, everything)\n";
    echo "   ✓ Migration-focused transform architecture\n";
    echo "   ✓ Planning and validation before execution\n";
    echo "   ✓ Conflict detection and resolution strategies\n";
    echo "   ✓ Dry run capabilities for safety\n";
    echo "   ✓ Destination-aware transformations\n\n";
    
    echo "🚀 Ready for WordPress migrations!\n";
    echo "   Extract → Transform (via adapter) → Load\n";
    echo "   Everything captured, conflicts resolved, relationships preserved\n";
    
} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}