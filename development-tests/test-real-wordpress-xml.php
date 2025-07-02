<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Support\WordPressXmlParser;
use Crumbls\Importer\Support\PostTypeAnalyzer;

/**
 * Test Real WordPress XML Analysis
 */

echo "🔍 Real WordPress XML Analysis\n";
echo "==============================\n\n";

// Check for command line argument or use default path
$xmlPath = $argv[1] ?? null;

if (!$xmlPath) {
    echo "📝 Usage Instructions:\n";
    echo "======================\n\n";
    
    echo "To test with your WordPress XML file, run:\n";
    echo "php test-real-wordpress-xml.php /path/to/your/wordpress-export.xml\n\n";
    
    echo "How to get a WordPress XML export:\n";
    echo "1. Go to your WordPress admin: /wp-admin/\n";
    echo "2. Navigate to Tools → Export\n";
    echo "3. Select 'All content' or choose specific content types\n";
    echo "4. Click 'Download Export File'\n";
    echo "5. Run this script with the downloaded XML file path\n\n";
    
    echo "Example XML locations in this project:\n";
    echo "- /Users/chasemiller/PhpstormProjects/wordpress-bridge/storage/app/private/imports/\n";
    echo "- ./wordpress-export.xml (in current directory)\n\n";
    
    // Try to find XML files in likely locations
    $possiblePaths = [
        __DIR__ . '/wordpress-export.xml',
        __DIR__ . '/sample.xml',
        __DIR__ . '/export.xml',
        '/Users/chasemiller/PhpstormProjects/wordpress-bridge/storage/app/private/imports/',
    ];
    
    $foundFiles = [];
    foreach ($possiblePaths as $path) {
        if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'xml') {
            $foundFiles[] = $path;
        } elseif (is_dir($path)) {
            $xmlFiles = glob($path . '*.xml');
            $foundFiles = array_merge($foundFiles, $xmlFiles);
        }
    }
    
    if (!empty($foundFiles)) {
        echo "🔍 Found potential XML files:\n";
        foreach ($foundFiles as $i => $file) {
            $size = file_exists($file) ? ' (' . number_format(filesize($file)) . ' bytes)' : '';
            echo "   " . ($i + 1) . ". {$file}{$size}\n";
        }
        echo "\nTry running with one of these files:\n";
        echo "php test-real-wordpress-xml.php \"" . $foundFiles[0] . "\"\n\n";
    }
    
    echo "Creating sample XML file for demonstration...\n";
    
    // Create a minimal sample XML file for testing
    $sampleXml = createSampleWordPressXml();
    file_put_contents(__DIR__ . '/sample-wordpress-export.xml', $sampleXml);
    
    echo "✅ Created sample-wordpress-export.xml\n";
    echo "Running analysis on sample file...\n\n";
    
    $xmlPath = __DIR__ . '/sample-wordpress-export.xml';
}

if (!file_exists($xmlPath)) {
    echo "❌ Error: XML file not found at: {$xmlPath}\n";
    echo "Please check the file path and try again.\n";
    exit(1);
}

try {
    echo "📂 Parsing WordPress XML: " . basename($xmlPath) . "\n";
    echo "   File size: " . number_format(filesize($xmlPath)) . " bytes\n\n";
    
    // Parse the XML file
    $parser = new WordPressXmlParser();
    $wordpressData = $parser->parseFile($xmlPath);
    
    // Display parsing results
    $parsingReport = $parser->getParsingReport();
    echo "📊 XML Parsing Results:\n";
    echo "-----------------------\n";
    foreach ($parsingReport['summary'] as $key => $value) {
        $label = ucwords(str_replace('_', ' ', $key));
        echo "   {$label}: {$value}\n";
    }
    echo "\n";
    
    if (!empty($parsingReport['post_types_found'])) {
        echo "🏷️  Post Types Found: " . implode(', ', $parsingReport['post_types_found']) . "\n";
    }
    
    if (!empty($parsingReport['most_common_meta_keys'])) {
        echo "🔑 Top Meta Keys: " . implode(', ', array_slice($parsingReport['most_common_meta_keys'], 0, 5)) . "\n";
    }
    
    if ($parsingReport['date_range']['earliest']) {
        echo "📅 Content Date Range: {$parsingReport['date_range']['earliest']} to {$parsingReport['date_range']['latest']}\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
    
    // Run Post Type Analysis
    echo "🔍 Running Post Type Analysis...\n\n";
    
    $analyzer = new PostTypeAnalyzer();
    $analyzer->analyze($wordpressData);
    
    // Display comprehensive results
    displayAnalysisResults($analyzer, $parsingReport);
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

function displayAnalysisResults(PostTypeAnalyzer $analyzer, array $parsingReport): void
{
    $stats = $analyzer->getStatistics();
    $report = $analyzer->getDetailedReport();
    
    // Overall Summary
    echo "📈 Analysis Summary:\n";
    echo "====================\n";
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
    
    // Content Analysis
    if (isset($parsingReport['content_analysis'])) {
        $content = $parsingReport['content_analysis'];
        echo "📋 Content Features:\n";
        echo "===================\n";
        echo "   Featured Images: " . ($content['has_featured_images'] ? '✅ Yes' : '⚪ No') . "\n";
        echo "   Image Galleries: " . ($content['has_galleries'] ? '✅ Yes' : '⚪ No') . "\n";
        echo "   Shortcodes: " . ($content['has_shortcodes'] ? '✅ Yes' : '⚪ No') . "\n";
        echo "   Custom Fields: " . ($content['has_custom_fields'] ? '✅ Yes' : '⚪ No') . "\n";
        echo "   Average Content Length: " . number_format($content['average_content_length']) . " characters\n\n";
    }
    
    // Detailed Post Type Analysis
    echo "🏷️  Detailed Post Type Analysis:\n";
    echo "=================================\n\n";
    
    foreach ($analyzer->getPostTypes() as $postType) {
        $schema = $analyzer->getSchema($postType);
        $posts = $analyzer->getPostsForType($postType);
        
        echo "📌 {$postType} ({$schema['post_count']} posts)\n";
        echo "   " . str_repeat('-', strlen($postType) + 20) . "\n";
        
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
                echo "   🎨 Custom Fields:\n";
                $customFields = array_slice($schema['custom_fields'], 0, 8, true);
                foreach ($customFields as $fieldName => $field) {
                    $coverage = $field['coverage_percentage'];
                    $type = $field['type'];
                    $icon = $field['is_common'] ? '🟢' : ($field['is_occasional'] ? '🟡' : '🔴');
                    
                    echo "      {$icon} {$fieldName} ({$type}) - {$coverage}%\n";
                    
                    // Show interesting insights
                    if (!empty($field['analysis']['pattern_hints'])) {
                        $hints = array_slice($field['analysis']['pattern_hints'], 0, 2);
                        foreach ($hints as $hint) {
                            echo "         💡 {$hint}\n";
                        }
                    }
                }
                echo "\n";
            }
            
            // Show important internal fields
            if (!empty($schema['internal_fields'])) {
                echo "   🔧 Key WordPress Internal Fields:\n";
                $internalFields = array_slice($schema['internal_fields'], 0, 5, true);
                foreach ($internalFields as $fieldName => $field) {
                    $coverage = $field['coverage_percentage'];
                    $type = $field['type'];
                    $icon = $field['is_common'] ? '🟢' : ($field['is_occasional'] ? '🟡' : '🔴');
                    
                    echo "      {$icon} {$fieldName} ({$type}) - {$coverage}%\n";
                }
                echo "\n";
            }
            
            echo "\n   🏗️  Suggested Laravel Migration:\n";
            echo "      Table: {$schema['migration_schema']['table_name']}\n";
            
            if (!empty($schema['migration_schema']['common_fields'])) {
                echo "      Required columns:\n";
                foreach (array_slice($schema['migration_schema']['common_fields'], 0, 5) as $field) {
                    $type = $field['type'];
                    $length = isset($field['length']) ? "({$field['length']})" : '';
                    echo "         \$table->{$type}('{$field['name']}'){$length};\n";
                }
            }
            
            if (!empty($schema['migration_schema']['optional_fields'])) {
                echo "      Optional columns:\n";
                foreach (array_slice($schema['migration_schema']['optional_fields'], 0, 3) as $field) {
                    $type = $field['type'];
                    $length = isset($field['length']) ? "({$field['length']})" : '';
                    echo "         \$table->{$type}('{$field['name']}'){$length}->nullable();\n";
                }
            }
            
            echo "\n   🎯 Laravel Model Suggestions:\n";
            $fillable = array_slice($schema['model_attributes']['fillable'], 0, 6);
            echo "      protected \$fillable = ['" . implode("', '", $fillable) . "'];\n";
            
            if (!empty($schema['model_attributes']['casts'])) {
                echo "      protected \$casts = " . json_encode($schema['model_attributes']['casts'], JSON_PRETTY_PRINT) . ";\n";
            }
            
        } else {
            echo "   ℹ️  No custom fields detected for this post type\n";
        }
        
        echo "\n";
    }
    
    // Field Analysis Summary
    $fieldAnalysis = $report['field_analysis'];
    echo "📊 Overall Field Analysis:\n";
    echo "==========================\n";
    
    if (!empty($fieldAnalysis['field_types_distribution'])) {
        echo "Data Type Distribution:\n";
        foreach ($fieldAnalysis['field_types_distribution'] as $type => $count) {
            echo "   {$type}: {$count} fields\n";
        }
    }
    
    if (!empty($fieldAnalysis['plugin_field_counts'])) {
        echo "\nPlugin Field Counts:\n";
        foreach ($fieldAnalysis['plugin_field_counts'] as $plugin => $count) {
            echo "   " . ucfirst($plugin) . ": {$count} fields\n";
        }
    }
    
    // Recommendations
    if (!empty($report['recommendations'])) {
        echo "\n💡 Migration Recommendations:\n";
        echo "=============================\n";
        foreach ($report['recommendations'] as $i => $recommendation) {
            echo ($i + 1) . ". {$recommendation}\n";
        }
    }
    
    echo "\n✅ Analysis Complete!\n";
    echo "======================\n\n";
    
    echo "🎯 Next Steps:\n";
    echo "1. Review the discovered post types and their field schemas\n";
    echo "2. Create Laravel migrations using the suggested column definitions\n";
    echo "3. Build models with the recommended fillable fields and casts\n";
    echo "4. Implement custom migration logic for each post type\n";
    echo "5. Test the migration with a subset of data first\n\n";
    
    echo "💾 Data Export Options:\n";
    echo "You can now use this analysis to:\n";
    echo "• Generate Laravel migrations automatically\n";
    echo "• Create Eloquent models with proper relationships\n";
    echo "• Build a custom migration strategy for each post type\n";
    echo "• Identify which fields are most important to migrate first\n";
}

function createSampleWordPressXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
	xmlns:wp="http://wordpress.org/export/1.2/"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">

<channel>
	<title>Sample WordPress Site</title>
	<link>https://example.com</link>
	<description>A sample WordPress export for testing</description>
	
	<item>
		<title>Welcome to WordPress!</title>
		<link>https://example.com/sample-post/</link>
		<wp:post_id>1</wp:post_id>
		<wp:post_date>2024-01-15 10:30:00</wp:post_date>
		<wp:post_date_gmt>2024-01-15 10:30:00</wp:post_date_gmt>
		<wp:post_modified>2024-01-15 10:30:00</wp:post_modified>
		<wp:post_modified_gmt>2024-01-15 10:30:00</wp:post_modified_gmt>
		<wp:status>publish</wp:status>
		<wp:post_type>post</wp:post_type>
		<wp:post_name>sample-post</wp:post_name>
		<wp:post_author>admin</wp:post_author>
		<wp:post_parent>0</wp:post_parent>
		<wp:menu_order>0</wp:menu_order>
		<wp:comment_status>open</wp:comment_status>
		<wp:ping_status>open</wp:ping_status>
		<wp:post_password></wp:post_password>
		<content:encoded><![CDATA[Welcome to WordPress! This is your first post.]]></content:encoded>
		<excerpt:encoded><![CDATA[]]></excerpt:encoded>
		<wp:postmeta>
			<wp:meta_key>_thumbnail_id</wp:meta_key>
			<wp:meta_value>123</wp:meta_value>
		</wp:postmeta>
		<wp:postmeta>
			<wp:meta_key>seo_title</wp:meta_key>
			<wp:meta_value>Welcome Post - SEO Optimized</wp:meta_value>
		</wp:postmeta>
		<wp:postmeta>
			<wp:meta_key>custom_field_example</wp:meta_key>
			<wp:meta_value>This is a custom field value</wp:meta_value>
		</wp:postmeta>
	</item>
	
	<item>
		<title>Awesome Product</title>
		<link>https://example.com/products/awesome-product/</link>
		<wp:post_id>2</wp:post_id>
		<wp:post_date>2024-01-16 14:20:00</wp:post_date>
		<wp:post_date_gmt>2024-01-16 14:20:00</wp:post_date_gmt>
		<wp:post_modified>2024-01-16 14:20:00</wp:post_modified>
		<wp:post_modified_gmt>2024-01-16 14:20:00</wp:post_modified_gmt>
		<wp:status>publish</wp:status>
		<wp:post_type>product</wp:post_type>
		<wp:post_name>awesome-product</wp:post_name>
		<wp:post_author>admin</wp:post_author>
		<wp:post_parent>0</wp:post_parent>
		<wp:menu_order>0</wp:menu_order>
		<wp:comment_status>open</wp:comment_status>
		<wp:ping_status>closed</wp:ping_status>
		<wp:post_password></wp:post_password>
		<content:encoded><![CDATA[This is an awesome product description with lots of details.]]></content:encoded>
		<excerpt:encoded><![CDATA[Short product summary]]></excerpt:encoded>
		<wp:postmeta>
			<wp:meta_key>_price</wp:meta_key>
			<wp:meta_value>29.99</wp:meta_value>
		</wp:postmeta>
		<wp:postmeta>
			<wp:meta_key>_regular_price</wp:meta_key>
			<wp:meta_value>29.99</wp:meta_value>
		</wp:postmeta>
		<wp:postmeta>
			<wp:meta_key>_stock</wp:meta_key>
			<wp:meta_value>100</wp:meta_value>
		</wp:postmeta>
		<wp:postmeta>
			<wp:meta_key>product_color</wp:meta_key>
			<wp:meta_value>Blue</wp:meta_value>
		</wp:postmeta>
		<wp:postmeta>
			<wp:meta_key>product_material</wp:meta_key>
			<wp:meta_value>Cotton</wp:meta_value>
		</wp:postmeta>
	</item>
	
</channel>
</rss>';
}