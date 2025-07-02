<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Support\SqlDumpParser;
use Crumbls\Importer\Support\PostTypeAnalyzer;

/**
 * Test SQL Dump Analysis with PostTypeAnalyzer
 */

echo "🔍 SQL Dump Analysis Test\n";
echo "=========================\n\n";

// Check for command line argument or create sample
$sqlPath = $argv[1] ?? null;

if (!$sqlPath) {
    echo "📝 Usage Instructions:\n";
    echo "======================\n\n";
    
    echo "To test with your WordPress SQL dump, run:\n";
    echo "php test-sql-dump-analysis.php /path/to/your/wordpress-dump.sql\n\n";
    
    echo "How to get a WordPress SQL dump:\n";
    echo "1. Via phpMyAdmin: Export → SQL format\n";
    echo "2. Via command line: mysqldump -u username -p database_name > dump.sql\n";
    echo "3. Via WordPress plugins: UpdraftPlus, All-in-One WP Migration\n\n";
    
    echo "Creating sample SQL dump for demonstration...\n";
    
    $sampleSql = createSampleWordPressSql();
    $sqlPath = __DIR__ . '/sample-wordpress-dump.sql';
    file_put_contents($sqlPath, $sampleSql);
    
    echo "✅ Created sample-wordpress-dump.sql\n";
    echo "Running analysis on sample file...\n\n";
}

if (!file_exists($sqlPath)) {
    echo "❌ Error: SQL file not found at: {$sqlPath}\n";
    echo "Please check the file path and try again.\n";
    exit(1);
}

try {
    echo "📂 Parsing SQL Dump: " . basename($sqlPath) . "\n";
    echo "   File size: " . number_format(filesize($sqlPath)) . " bytes\n\n";
    
    // Parse the SQL dump
    $sqlParser = new SqlDumpParser();
    $sqlData = $sqlParser->parseFile($sqlPath);
    
    // Display SQL parsing results
    $sqlReport = $sqlParser->getParsingReport();
    echo "📊 SQL Parsing Results:\n";
    echo "-----------------------\n";
    echo "   Tables Found: {$sqlReport['summary']['tables_parsed']}\n";
    echo "   WordPress Tables: {$sqlReport['summary']['wordpress_tables']}\n";
    echo "   Total Rows: " . number_format($sqlReport['summary']['total_rows']) . "\n";
    echo "   WordPress Prefix: '{$sqlReport['summary']['wordpress_prefix']}'\n\n";
    
    if (!empty($sqlReport['wordpress_data_found'])) {
        echo "🏷️  WordPress Data Extracted: " . implode(', ', $sqlReport['wordpress_data_found']) . "\n";
    }
    
    // Show table schemas
    echo "\n📋 Table Schemas Found:\n";
    foreach ($sqlReport['table_schemas'] as $tableName => $schema) {
        $wpIndicator = $schema['is_wordpress_table'] ? '🟢' : '⚪';
        $columnCount = count($schema['columns']);
        echo "   {$wpIndicator} {$tableName} ({$columnCount} columns)\n";
        
        if ($schema['is_wordpress_table'] && !empty($schema['primary_key'])) {
            echo "      🔑 Primary Key: " . implode(', ', $schema['primary_key']) . "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
    
    // Run Post Type Analysis on SQL data
    echo "🔍 Running Post Type Analysis on SQL Data...\n\n";
    
    $analyzer = new PostTypeAnalyzer();
    $analyzer->analyze($sqlData);
    
    // Display comprehensive results
    displaySqlAnalysisResults($analyzer, $sqlReport);
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

function displaySqlAnalysisResults(PostTypeAnalyzer $analyzer, array $sqlReport): void
{
    $stats = $analyzer->getStatistics();
    $report = $analyzer->getDetailedReport();
    
    // Overall Summary
    echo "📈 Post Type Analysis from SQL:\n";
    echo "===============================\n";
    echo "   Post Types Discovered: {$stats['total_post_types']}\n";
    echo "   Total Posts Analyzed: {$stats['total_posts']}\n";
    echo "   Average Custom Fields per Type: {$stats['average_fields_per_type']}\n\n";
    
    // Plugin Detection
    echo "🔌 WordPress Plugin/Feature Detection:\n";
    echo "=====================================\n";
    foreach ($stats['plugin_detection'] as $plugin => $detected) {
        $status = $detected ? '✅ DETECTED' : '⚪ Not Found';
        $name = match($plugin) {
            'acf' => 'Advanced Custom Fields',
            'woocommerce' => 'WooCommerce',
            'yoast' => 'Yoast SEO',
            'elementor' => 'Elementor Page Builder',
            'custom_fields' => 'Custom Fields',
            default => ucfirst($plugin)
        };
        echo "   {$name}: {$status}\n";
    }
    echo "\n";
    
    // SQL-specific insights
    echo "🔍 SQL-Specific Insights:\n";
    echo "=========================\n";
    
    if (!empty($sqlReport['statistics']['table_relationships'])) {
        echo "🔗 Table Relationships Detected:\n";
        foreach (array_slice($sqlReport['statistics']['table_relationships'], 0, 5) as $rel) {
            $ref = $rel['likely_references'] ? " → {$rel['likely_references']}" : " (orphaned)";
            echo "   {$rel['table']}.{$rel['column']}{$ref}\n";
        }
        echo "\n";
    }
    
    if (!empty($sqlReport['statistics']['largest_table'])) {
        $largest = $sqlReport['statistics']['largest_table'];
        echo "📊 Largest Table: {$largest['name']} (" . number_format($largest['rows']) . " rows)\n\n";
    }
    
    // Detailed Post Type Analysis
    echo "🏷️  Post Type Analysis with SQL Context:\n";
    echo "=======================================\n\n";
    
    foreach ($analyzer->getPostTypes() as $postType) {
        $schema = $analyzer->getSchema($postType);
        $posts = $analyzer->getPostsForType($postType);
        
        echo "📌 {$postType} ({$schema['post_count']} records)\n";
        echo "   " . str_repeat('-', strlen($postType) + 25) . "\n";
        
        // SQL table info
        $tableName = $sqlReport['summary']['wordpress_prefix'] . 'posts';
        if (isset($sqlReport['table_schemas'][$tableName])) {
            $tableSchema = $sqlReport['table_schemas'][$tableName];
            echo "   🗄️  SQL Table: {$tableName}\n";
            echo "      📋 Columns: " . count($tableSchema['columns']) . "\n";
            if (!empty($tableSchema['indexes'])) {
                echo "      📇 Indexes: " . count($tableSchema['indexes']) . "\n";
            }
            echo "\n";
        }
        
        if (!empty($schema['fields'])) {
            // Field breakdown
            $common = count($schema['field_categories']['common'] ?? []);
            $occasional = count($schema['field_categories']['occasional'] ?? []);
            $rare = count($schema['field_categories']['rare'] ?? []);
            
            echo "   📊 Field Distribution:\n";
            echo "      • Total meta fields: " . count($schema['fields']) . "\n";
            echo "      • Custom fields: " . count($schema['custom_fields']) . "\n";
            echo "      • Internal WordPress fields: " . count($schema['internal_fields']) . "\n";
            echo "      • Common fields (80%+ coverage): {$common}\n";
            echo "      • Occasional fields (20-80% coverage): {$occasional}\n";
            echo "      • Rare fields (<20% coverage): {$rare}\n\n";
            
            // Show custom fields first
            if (!empty($schema['custom_fields'])) {
                echo "   🎨 Custom Fields from SQL:\n";
                $customFields = array_slice($schema['custom_fields'], 0, 6, true);
                foreach ($customFields as $fieldName => $field) {
                    $coverage = $field['coverage_percentage'];
                    $type = $field['type'];
                    $icon = $field['is_common'] ? '🟢' : ($field['is_occasional'] ? '🟡' : '🔴');
                    $sampleCount = count($field['sample_values']);
                    
                    echo "      {$icon} {$fieldName} ({$type}) - {$coverage}% | {$sampleCount} samples\n";
                    
                    // Show sample values
                    if (!empty($field['sample_values'])) {
                        $samples = array_slice($field['sample_values'], 0, 3);
                        $sampleStr = implode(', ', array_map(fn($s) => '"' . substr($s, 0, 30) . '"', $samples));
                        echo "         📄 Examples: {$sampleStr}\n";
                    }
                    
                    // Show insights
                    if (!empty($field['analysis']['pattern_hints'])) {
                        $hints = array_slice($field['analysis']['pattern_hints'], 0, 2);
                        foreach ($hints as $hint) {
                            echo "         💡 {$hint}\n";
                        }
                    }
                }
                echo "\n";
            }
            
            // Migration suggestions based on SQL schema
            echo "   🏗️  SQL-Informed Migration Strategy:\n";
            echo "      Table: {$schema['migration_schema']['table_name']}\n";
            
            if (!empty($schema['migration_schema']['common_fields'])) {
                echo "      Required custom columns:\n";
                foreach (array_slice($schema['migration_schema']['common_fields'], 0, 4) as $field) {
                    $type = $field['type'];
                    $length = isset($field['length']) ? "({$field['length']})" : '';
                    echo "         \$table->{$type}('{$field['name']}'){$length};\n";
                }
            }
            
            if (!empty($schema['migration_schema']['optional_fields'])) {
                echo "      Optional custom columns:\n";
                foreach (array_slice($schema['migration_schema']['optional_fields'], 0, 3) as $field) {
                    $type = $field['type'];
                    $length = isset($field['length']) ? "({$field['length']})" : '';
                    echo "         \$table->{$type}('{$field['name']}'){$length}->nullable();\n";
                }
            }
            
        } else {
            echo "   ℹ️  No custom fields detected for this post type\n";
        }
        
        echo "\n";
    }
    
    // SQL vs XML comparison insights
    echo "💡 SQL Dump Advantages:\n";
    echo "=======================\n";
    echo "• Complete database schema with column types and constraints\n";
    echo "• Table relationships and foreign key insights\n";
    echo "• Index information for performance optimization\n";
    echo "• Data integrity constraints and defaults\n";
    echo "• Full dataset (not just exported content)\n";
    echo "• Database-level metadata (collation, engine, etc.)\n\n";
    
    echo "🎯 Next Steps with SQL Data:\n";
    echo "============================\n";
    echo "1. Use table schemas to generate precise Laravel migrations\n";
    echo "2. Leverage foreign key relationships for model relationships\n";
    echo "3. Optimize migrations based on existing indexes\n";
    echo "4. Preserve data constraints in new schema\n";
    echo "5. Test with larger SQL dumps for performance validation\n\n";
    
    echo "✅ SQL Dump Analysis Complete!\n";
    echo "===============================\n";
}

function createSampleWordPressSql(): string
{
    return "-- WordPress SQL Dump Sample
-- Generated for testing purposes

SET FOREIGN_KEY_CHECKS=0;

--
-- Table structure for table `wp_posts`
--

DROP TABLE IF EXISTS `wp_posts`;
CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT '0',
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`),
  KEY `post_author` (`post_author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_posts`
--

INSERT INTO `wp_posts` VALUES 
(1,1,'2024-01-15 10:00:00','2024-01-15 10:00:00','Welcome to WordPress! This is your first post. Edit or delete it, then start writing!','Hello World!','','publish','hello-world','2024-01-15 10:00:00','2024-01-15 10:00:00',0,'https://example.com/?p=1',0,'post','',0),
(2,1,'2024-01-16 11:30:00','2024-01-16 11:30:00','This is an awesome t-shirt made from premium cotton. Available in multiple colors and sizes.','Premium Cotton T-Shirt','Comfortable and stylish','publish','premium-cotton-tshirt','2024-01-16 11:30:00','2024-01-16 11:30:00',0,'https://example.com/?post_type=product&p=2',0,'product','',0),
(3,1,'2024-01-17 14:15:00','2024-01-17 14:15:00','Join us for an amazing tech conference with industry leaders and innovative presentations.','Tech Conference 2024','Annual technology conference','publish','tech-conference-2024','2024-01-17 14:15:00','2024-01-17 14:15:00',0,'https://example.com/?post_type=event&p=3',0,'event','',0),
(4,1,'2024-01-18 09:45:00','2024-01-18 09:45:00','John is our CEO with over 15 years of experience in technology and business development.','John Doe - CEO','Experienced technology leader','publish','john-doe-ceo','2024-01-18 09:45:00','2024-01-18 09:45:00',0,'https://example.com/?post_type=team_member&p=4',0,'team_member','',0);

--
-- Table structure for table `wp_postmeta`
--

DROP TABLE IF EXISTS `wp_postmeta`;
CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_postmeta`
--

INSERT INTO `wp_postmeta` VALUES 
(1,1,'_edit_last','1'),
(2,1,'_thumbnail_id','10'),
(3,1,'seo_title','Hello World - SEO Optimized Title'),
(4,1,'seo_description','This is the meta description for SEO purposes'),
(5,2,'_edit_last','1'),
(6,2,'_price','29.99'),
(7,2,'_regular_price','29.99'),
(8,2,'_sale_price',''),
(9,2,'_stock_status','instock'),
(10,2,'_stock','100'),
(11,2,'_weight','0.5'),
(12,2,'product_color','Blue'),
(13,2,'product_size','Medium'),
(14,2,'product_material','Cotton'),
(15,2,'product_brand','EcoWear'),
(16,3,'_edit_last','1'),
(17,3,'event_date','2024-08-15'),
(18,3,'event_time','18:00'),
(19,3,'event_location','Convention Center'),
(20,3,'event_capacity','500'),
(21,3,'event_price','199.00'),
(22,3,'event_organizer','TechCorp Events'),
(23,3,'event_speakers','[\"John Smith\", \"Jane Doe\", \"Mike Johnson\"]'),
(24,4,'_edit_last','1'),
(25,4,'position','Chief Executive Officer'),
(26,4,'department','Executive'),
(27,4,'bio','John has over 15 years of experience in technology and business development. He founded the company in 2010 and has led its growth to become a market leader.'),
(28,4,'email','john@company.com'),
(29,4,'phone','+1-555-0123'),
(30,4,'linkedin_url','https://linkedin.com/in/johndoe'),
(31,4,'years_experience','15'),
(32,4,'skills','[\"Leadership\", \"Strategy\", \"Technology\", \"Business Development\"]'),
(33,4,'photo_url','https://example.com/wp-content/uploads/john-doe.jpg');

--
-- Table structure for table `wp_users`
--

DROP TABLE IF EXISTS `wp_users`;
CREATE TABLE `wp_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT '0',
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_users`
--

INSERT INTO `wp_users` VALUES 
(1,'admin','hashed_password_here','admin','admin@example.com','','2024-01-01 00:00:00','',0,'Administrator');

--
-- Table structure for table `wp_terms`
--

DROP TABLE IF EXISTS `wp_terms`;
CREATE TABLE `wp_terms` (
  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '',
  `slug` varchar(200) NOT NULL DEFAULT '',
  `term_group` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`term_id`),
  KEY `slug` (`slug`(191)),
  KEY `name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_terms`
--

INSERT INTO `wp_terms` VALUES 
(1,'Technology','technology',0),
(2,'Business','business',0),
(3,'Products','products',0);

SET FOREIGN_KEY_CHECKS=1;
";
}