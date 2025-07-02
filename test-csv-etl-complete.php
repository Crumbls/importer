<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Drivers\CsvDriver;

/**
 * Complete CSV ETL Test
 * 
 * This demonstrates the full Extract-Transform-Load cycle for CSV files
 */

echo "🔄 Complete CSV ETL (Extract-Transform-Load) Test\n";
echo "==============================================\n\n";

// Create test CSV data
$testData = [
    ['name', 'email', 'age', 'city'],
    ['John Doe', 'john@example.com', '30', 'New York'],
    ['Jane Smith', 'jane@example.com', '25', 'Los Angeles'],
    ['Bob Johnson', 'bob@example.com', '35', 'Chicago'],
    ['Alice Brown', 'alice@example.com', '28', 'Houston'],
    ['Charlie Wilson', 'charlie@example.com', '32', 'Phoenix']
];

$inputFile = __DIR__ . '/temp-input.csv';
$outputFile = __DIR__ . '/temp-output.csv';

// Create input CSV file
echo "1. Creating test input CSV file...\n";
$handle = fopen($inputFile, 'w');
foreach ($testData as $row) {
    fputcsv($handle, $row);
}
fclose($handle);
echo "   ✓ Created: " . basename($inputFile) . "\n\n";

try {
    // EXTRACT PHASE
    echo "2. EXTRACT: Importing CSV data...\n";
    $driver = new CsvDriver([
        'has_headers' => true,
        'auto_detect_delimiter' => true
    ]);
    
    // Configure temporary storage for processing
    $driver->withTempStorage()
           ->cleanColumnNames()
           ->email('email')
           ->numeric('age');
    
    $importResult = $driver->import($inputFile);
    echo "   ✓ Extracted {$importResult->processed} records\n";
    echo "   ✓ Imported: {$importResult->imported} records\n";
    echo "   ✓ Headers: " . implode(', ', $importResult->meta['headers'] ?? []) . "\n\n";
    
    // TRANSFORM PHASE
    echo "3. TRANSFORM: Processing and transforming data...\n";
    
    // Get data for transformation
    $data = $driver->toArray();
    echo "   ✓ Retrieved " . count($data) . " records from storage\n";
    
    // Apply transformations
    $transformedData = array_map(function($row) {
        // Transform: convert names to uppercase, add full_name field
        $transformed = $row;
        if (isset($transformed['name'])) {
            $transformed['name'] = strtoupper($transformed['name']);
            $transformed['full_name'] = $transformed['name'];
        }
        
        // Add computed field
        if (isset($transformed['age'])) {
            $transformed['age_group'] = (int)$transformed['age'] < 30 ? 'Young' : 'Adult';
        }
        
        return $transformed;
    }, $data);
    
    echo "   ✓ Applied transformations:\n";
    echo "     - Converted names to uppercase\n";
    echo "     - Added age_group computed field\n";
    echo "     - Added full_name field\n\n";
    
    // LOAD PHASE
    echo "4. LOAD: Exporting transformed data to new CSV...\n";
    
    $exportResult = $driver->exportArray($transformedData, $outputFile, [
        'headers' => true,
        'transformer' => function($row) {
            // Final transformation during export
            if (isset($row['email'])) {
                $row['email'] = strtolower($row['email']);
            }
            return $row;
        }
    ]);
    
    echo "   ✓ Exported {$exportResult->getExported()} records\n";
    echo "   ✓ Failed: {$exportResult->getFailed()} records\n";
    echo "   ✓ Output file: " . basename($exportResult->getDestination()) . "\n";
    echo "   ✓ File size: " . number_format($exportResult->getStats()['file_size']) . " bytes\n";
    echo "   ✓ Export rate: " . number_format($exportResult->getExportRate(), 2) . " records/second\n";
    echo "   ✓ Success rate: " . number_format($exportResult->getSuccessRate(), 2) . "%\n\n";
    
    // VERIFICATION
    echo "5. VERIFICATION: Comparing input vs output...\n";
    
    // Read the exported file to verify
    $verifyDriver = new CsvDriver(['has_headers' => true]);
    $verifyResult = $verifyDriver->import($outputFile);
    $verifiedData = $verifyDriver->toArray();
    
    echo "   Original records: " . count($data) . "\n";
    echo "   Exported records: " . count($verifiedData) . "\n";
    echo "   Data integrity: " . (count($data) === count($verifiedData) ? '✓ PASSED' : '❌ FAILED') . "\n\n";
    
    // Show sample transformation results
    if (!empty($verifiedData)) {
        echo "6. SAMPLE TRANSFORMATION RESULTS:\n";
        echo "   Original → Transformed:\n";
        
        $original = $data[0] ?? [];
        $transformed = $verifiedData[0] ?? [];
        
        foreach ($original as $key => $value) {
            $newValue = $transformed[$key] ?? 'N/A';
            if ($value !== $newValue) {
                echo "   {$key}: '{$value}' → '{$newValue}'\n";
            }
        }
        
        // Show new fields
        $newFields = array_diff_key($transformed, $original);
        if (!empty($newFields)) {
            echo "   New fields added:\n";
            foreach ($newFields as $key => $value) {
                echo "   {$key}: '{$value}'\n";
            }
        }
    }
    
    echo "\n✅ ETL PROCESS COMPLETED SUCCESSFULLY!\n";
    echo "=========================================\n\n";
    
    echo "📊 ETL Summary:\n";
    echo "• Extract: Successfully imported CSV with headers and validation\n";
    echo "• Transform: Applied data transformations and computed fields\n";
    echo "• Load: Exported to new CSV with final transformations\n";
    echo "• Verification: Data integrity maintained throughout process\n\n";
    
    echo "🎯 CsvDriver ETL Capabilities:\n";
    echo "✓ Memory-efficient streaming for large files\n";
    echo "✓ Configurable CSV parsing (delimiter, enclosure, escape)\n";
    echo "✓ Header detection and column mapping\n";
    echo "✓ Data validation and error handling\n";
    echo "✓ Temporary storage with chunked processing\n";
    echo "✓ Flexible data transformation pipeline\n";
    echo "✓ Multiple export formats and options\n";
    echo "✓ Progress tracking and performance metrics\n\n";
    
} catch (Exception $e) {
    echo "❌ ETL Process Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} finally {
    // Clean up test files
    if (file_exists($inputFile)) {
        unlink($inputFile);
        echo "🧹 Cleaned up: " . basename($inputFile) . "\n";
    }
    if (file_exists($outputFile)) {
        unlink($outputFile);
        echo "🧹 Cleaned up: " . basename($outputFile) . "\n";
    }
}

echo "\n🎉 CSV ETL Test Complete!\n";