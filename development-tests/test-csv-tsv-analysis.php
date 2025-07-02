<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Support\CsvTsvParser;
use Crumbls\Importer\Support\PostTypeAnalyzer;

/**
 * Test CSV/TSV Analysis with PostTypeAnalyzer Integration
 */

echo "🔍 CSV/TSV Data Analysis Test\n";
echo "=============================\n\n";

// Check for command line argument or create samples
$csvPath = $argv[1] ?? null;

if (!$csvPath) {
    echo "📝 Usage Instructions:\n";
    echo "======================\n\n";
    
    echo "To test with your CSV/TSV file, run:\n";
    echo "php test-csv-tsv-analysis.php /path/to/your/data.csv\n\n";
    
    echo "Supported formats:\n";
    echo "• CSV (Comma-separated values)\n";
    echo "• TSV (Tab-separated values)\n";
    echo "• PSV (Pipe-separated values)\n";
    echo "• SSV (Semicolon-separated values)\n";
    echo "• Auto-detection of delimiter and headers\n\n";
    
    echo "Creating sample CSV files for demonstration...\n";
    
    // Create sample CSV files
    createSampleCsvFiles();
    
    echo "✅ Created sample CSV files\n";
    echo "Running analysis on sample files...\n\n";
    
    // Test with all sample files
    testMultipleCsvFiles();
    exit(0);
}

if (!file_exists($csvPath)) {
    echo "❌ Error: CSV file not found at: {$csvPath}\n";
    echo "Please check the file path and try again.\n";
    exit(1);
}

try {
    echo "📂 Parsing CSV/TSV File: " . basename($csvPath) . "\n";
    echo "   File size: " . number_format(filesize($csvPath)) . " bytes\n\n";
    
    // Parse the CSV file
    $csvParser = new CsvTsvParser([
        'auto_detect_delimiter' => true,
        'auto_detect_headers' => true,
        'auto_detect_types' => true
    ]);
    
    $csvData = $csvParser->parseFile($csvPath);
    
    // Display CSV parsing results
    $csvReport = $csvParser->getParsingReport();
    echo "📊 CSV Parsing Results:\n";
    echo "-----------------------\n";
    echo "   Rows Parsed: " . number_format($csvReport['summary']['rows_parsed']) . "\n";
    echo "   Columns Detected: {$csvReport['summary']['columns_detected']}\n";
    echo "   Delimiter Detected: '{$csvReport['summary']['delimiter_detected']}'\n";
    echo "   Headers Detected: " . ($csvReport['summary']['headers_detected'] ? 'Yes' : 'No') . "\n\n";
    
    if (!empty($csvReport['headers'])) {
        echo "🏷️  Column Headers:\n";
        foreach ($csvReport['headers'] as $i => $header) {
            echo "   " . ($i + 1) . ". {$header}\n";
        }
        echo "\n";
    }
    
    // Show WordPress field mapping
    if (!empty($csvReport['wordpress_mapping'])) {
        echo "🎯 WordPress Field Mapping:\n";
        foreach ($csvReport['wordpress_mapping'] as $csvField => $wpField) {
            echo "   {$csvField} → {$wpField}\n";
        }
        echo "\n";
    }
    
    echo str_repeat("=", 60) . "\n\n";
    
    // Run Post Type Analysis on CSV data
    echo "🔍 Running Post Type Analysis on CSV Data...\n\n";
    
    $analyzer = new PostTypeAnalyzer();
    $analyzer->analyze($csvData);
    
    // Display comprehensive results
    displayCsvAnalysisResults($analyzer, $csvReport);
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

function createSampleCsvFiles(): void
{
    // Sample 1: Basic WordPress Posts (CSV)
    $postsCSV = <<<CSV
post_title,post_content,post_type,post_status,post_date,author,category,seo_title,seo_description
"Getting Started with WordPress","This is a comprehensive guide to getting started with WordPress. Learn the basics of content management.","post","publish","2024-01-15 10:00:00","admin","Tutorial","WordPress Beginner Guide - Complete Tutorial","Learn WordPress from scratch with our comprehensive beginner guide. Perfect for new users."
"Advanced WordPress Tips","Take your WordPress skills to the next level with these advanced tips and tricks for power users.","post","publish","2024-01-16 14:30:00","admin","Advanced","Pro WordPress Tips for Advanced Users","Master advanced WordPress techniques with expert tips for power users and developers."
"WordPress Security Guide","Essential security practices every WordPress site owner should implement to protect their website.","post","publish","2024-01-17 09:15:00","admin","Security","WordPress Security Best Practices Guide","Secure your WordPress site with proven security practices and expert recommendations."
CSV;
    
    // Sample 2: WooCommerce Products (TSV)
    $productsCSV = <<<TSV
post_title	post_content	post_type	post_status	product_price	product_sku	product_color	product_size	product_material	product_brand	product_category
Premium Cotton T-Shirt	Comfortable and stylish premium cotton t-shirt perfect for everyday wear. Made from 100% organic cotton.	product	publish	29.99	TSHIRT-001	Blue	Medium	Cotton	EcoWear	Clothing
Vintage Denim Jeans	Classic vintage-style denim jeans with a modern fit. Durable and fashionable for any occasion.	product	publish	79.99	JEANS-002	Black	32	Denim	UrbanStyle	Clothing
Wireless Bluetooth Headphones	High-quality wireless headphones with noise cancellation and 20-hour battery life.	product	publish	149.99	HEADPHONES-003	Black		Plastic/Metal	SoundMax	Electronics
Organic Coffee Blend	Premium organic coffee blend sourced from sustainable farms. Rich and aromatic flavor profile.	product	publish	24.99	COFFEE-004	Brown		Coffee Beans	BrewMaster	Food & Beverage
TSV;
    
    // Sample 3: Events (Pipe-separated)
    $eventsCSV = <<<CSV
title|content|type|status|event_date|event_time|event_location|event_capacity|event_price|event_organizer|event_speakers
Tech Conference 2024|Join us for the premier technology conference featuring industry leaders and cutting-edge innovations.|event|publish|2024-08-15|09:00|Convention Center|500|299.00|TechCorp Events|["John Smith", "Jane Doe", "Mike Johnson"]
Summer Music Festival|A weekend of amazing music performances featuring local and international artists.|event|publish|2024-07-20|18:00|Central Park|2000|89.00|Music Promoters Inc|["The Rock Band", "Jazz Ensemble", "Electronic DJ"]
Business Networking Mixer|Connect with fellow entrepreneurs and business professionals in a relaxed networking environment.|event|publish|2024-05-10|19:00|Downtown Hotel|150|45.00|Business Network|["CEO Panel", "Startup Founders"]
CSV;
    
    // Sample 4: Team Members (Semicolon-separated)
    $teamCSV = <<<CSV
post_title;post_content;post_type;post_status;position;department;bio;email;phone;linkedin_url;years_experience;skills;photo_url
John Doe - CEO;John is our visionary leader with over 15 years of experience in technology and business development.;team_member;publish;Chief Executive Officer;Executive;John founded the company in 2010 and has led its growth to become a market leader in innovative solutions.;john@company.com;+1-555-0123;https://linkedin.com/in/johndoe;15;["Leadership", "Strategy", "Technology"];https://example.com/photos/john-doe.jpg
Jane Smith - CTO;Jane leads our technical team with expertise in software architecture and system design.;team_member;publish;Chief Technology Officer;Technology;Jane has a PhD in Computer Science and has been instrumental in developing our core technology platform.;jane@company.com;+1-555-0124;https://linkedin.com/in/janesmith;12;["Software Architecture", "System Design", "Team Leadership"];https://example.com/photos/jane-smith.jpg
Mike Johnson - Marketing Director;Mike drives our marketing strategy and brand development initiatives.;team_member;publish;Marketing Director;Marketing;Mike has over 10 years of experience in digital marketing and has helped grow our brand recognition by 300%.;mike@company.com;+1-555-0125;https://linkedin.com/in/mikejohnson;10;["Digital Marketing", "Brand Strategy", "Content Creation"];https://example.com/photos/mike-johnson.jpg
CSV;
    
    // Write sample files
    file_put_contents(__DIR__ . '/sample-posts.csv', $postsCSV);
    file_put_contents(__DIR__ . '/sample-products.tsv', $productsCSV);
    file_put_contents(__DIR__ . '/sample-events.psv', $eventsCSV);
    file_put_contents(__DIR__ . '/sample-team.csv', $teamCSV);
}

function testMultipleCsvFiles(): void
{
    $sampleFiles = [
        'Posts (CSV)' => __DIR__ . '/sample-posts.csv',
        'Products (TSV)' => __DIR__ . '/sample-products.tsv', 
        'Events (PSV)' => __DIR__ . '/sample-events.psv',
        'Team (CSV)' => __DIR__ . '/sample-team.csv'
    ];
    
    foreach ($sampleFiles as $description => $filePath) {
        echo "🔍 Testing {$description}\n";
        echo str_repeat('-', 40) . "\n";
        
        try {
            $csvParser = new CsvTsvParser();
            $csvData = $csvParser->parseFile($filePath);
            
            $csvReport = $csvParser->getParsingReport();
            echo "   Rows: {$csvReport['summary']['rows_parsed']}\n";
            echo "   Columns: {$csvReport['summary']['columns_detected']}\n";
            echo "   Delimiter: '{$csvReport['summary']['delimiter_detected']}'\n";
            
            // Quick post type analysis
            $analyzer = new PostTypeAnalyzer();
            $analyzer->analyze($csvData);
            
            $stats = $analyzer->getStatistics();
            echo "   Post Types: {$stats['total_post_types']}\n";
            echo "   Total Records: {$stats['total_posts']}\n";
            
            // Show detected post types
            foreach ($analyzer->getPostTypes() as $postType) {
                $schema = $analyzer->getSchema($postType);
                $customFields = count($schema['custom_fields'] ?? []);
                echo "   📌 {$postType}: {$schema['post_count']} posts, {$customFields} custom fields\n";
            }
            
            echo "\n";
            
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo str_repeat("=", 60) . "\n\n";
    
    // Detailed analysis of the first file
    echo "📊 Detailed Analysis: Posts CSV\n";
    echo "===============================\n\n";
    
    $csvParser = new CsvTsvParser();
    $csvData = $csvParser->parseFile($sampleFiles['Posts (CSV)']);
    $csvReport = $csvParser->getParsingReport();
    
    $analyzer = new PostTypeAnalyzer();
    $analyzer->analyze($csvData);
    
    displayCsvAnalysisResults($analyzer, $csvReport);
}

function displayCsvAnalysisResults(PostTypeAnalyzer $analyzer, array $csvReport): void
{
    $stats = $analyzer->getStatistics();
    $report = $analyzer->getDetailedReport();
    
    // Overall Summary
    echo "📈 Post Type Analysis from CSV:\n";
    echo "===============================\n";
    echo "   Post Types Discovered: {$stats['total_post_types']}\n";
    echo "   Total Records Analyzed: {$stats['total_posts']}\n";
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
    
    // CSV-specific insights
    echo "🔍 CSV-Specific Insights:\n";
    echo "=========================\n";
    echo "📊 Column Analysis:\n";
    foreach ($csvReport['column_analysis'] as $column => $analysis) {
        $type = $analysis['type'];
        $confidence = round($analysis['confidence'] * 100, 1);
        echo "   • {$column}: {$type} ({$confidence}% confidence)\n";
        
        if (!empty($analysis['patterns'])) {
            echo "     💡 Patterns: " . implode(', ', $analysis['patterns']) . "\n";
        }
        
        if (!empty($analysis['samples'])) {
            $samples = array_slice($analysis['samples'], 0, 2);
            $sampleStr = implode('", "', $samples);
            echo "     📄 Samples: \"{$sampleStr}\"\n";
        }
    }
    echo "\n";
    
    // Field Mapping
    if (!empty($csvReport['wordpress_mapping'])) {
        echo "🎯 WordPress Field Mapping:\n";
        foreach ($csvReport['wordpress_mapping'] as $csvField => $wpField) {
            echo "   {$csvField} → {$wpField}\n";
        }
        echo "\n";
    }
    
    // Detailed Post Type Analysis
    echo "🏷️  Post Type Analysis from CSV:\n";
    echo "=================================\n\n";
    
    foreach ($analyzer->getPostTypes() as $postType) {
        $schema = $analyzer->getSchema($postType);
        
        echo "📌 {$postType} ({$schema['post_count']} records)\n";
        echo "   " . str_repeat('-', strlen($postType) + 25) . "\n";
        
        if (!empty($schema['fields'])) {
            // Field breakdown
            $common = count($schema['field_categories']['common'] ?? []);
            $occasional = count($schema['field_categories']['occasional'] ?? []);
            $rare = count($schema['field_categories']['rare'] ?? []);
            
            echo "   📊 Field Distribution:\n";
            echo "      • Total fields: " . count($schema['fields']) . "\n";
            echo "      • Custom fields: " . count($schema['custom_fields']) . "\n";
            echo "      • WordPress core fields: " . count($schema['internal_fields']) . "\n";
            echo "      • Common fields (80%+ coverage): {$common}\n";
            echo "      • Occasional fields (20-80% coverage): {$occasional}\n";
            echo "      • Rare fields (<20% coverage): {$rare}\n\n";
            
            // Show custom fields
            if (!empty($schema['custom_fields'])) {
                echo "   🎨 Custom Fields from CSV:\n";
                $customFields = array_slice($schema['custom_fields'], 0, 8, true);
                foreach ($customFields as $fieldName => $field) {
                    $coverage = $field['coverage_percentage'];
                    $type = $field['type'];
                    $icon = $field['is_common'] ? '🟢' : ($field['is_occasional'] ? '🟡' : '🔴');
                    
                    echo "      {$icon} {$fieldName} ({$type}) - {$coverage}% coverage\n";
                    
                    // Show sample values
                    if (!empty($field['sample_values'])) {
                        $samples = array_slice($field['sample_values'], 0, 2);
                        $sampleStr = implode(', ', array_map(fn($s) => '"' . substr($s, 0, 25) . '"', $samples));
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
            
            // Migration suggestions
            echo "   🏗️  CSV-Informed Migration Strategy:\n";
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
    
    // CSV vs other formats comparison
    echo "💡 CSV/TSV Advantages:\n";
    echo "======================\n";
    echo "• Automatic delimiter and header detection\n";
    echo "• Column type inference and pattern recognition\n";
    echo "• WordPress field mapping suggestions\n";
    echo "• Support for any CSV format (comma, tab, pipe, semicolon)\n";
    echo "• Memory-efficient processing of large files\n";
    echo "• Direct integration with spreadsheet exports\n\n";
    
    echo "🎯 Next Steps with CSV Data:\n";
    echo "============================\n";
    echo "1. Review detected field mappings and adjust if needed\n";
    echo "2. Validate data types and handle any inconsistencies\n";
    echo "3. Map CSV columns to WordPress fields or custom fields\n";
    echo "4. Generate Laravel migrations based on field analysis\n";
    echo "5. Test import with sample data before full migration\n\n";
    
    echo "✅ CSV/TSV Analysis Complete!\n";
    echo "==============================\n\n";
    
    echo "🎉 All Extract Formats Now Supported:\n";
    echo "• ✅ WordPress XML exports\n";
    echo "• ✅ SQL database dumps\n";
    echo "• ✅ Live database connections\n";
    echo "• ✅ CSV/TSV data files\n\n";
    
    echo "Ready for comprehensive WordPress migrations from any source!\n";
}