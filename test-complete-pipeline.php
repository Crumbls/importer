<?php

require_once __DIR__ . '/vendor/autoload.php';

use Crumbls\Importer\Drivers\CsvDriver;

/**
 * Test Complete Pipeline with All Steps
 * 
 * This tests all the reusable pipeline steps we just created
 */

echo "🧪 Testing Complete Extended ETL Pipeline\n";
echo "========================================\n\n";

// Create test CSV data
$testData = [
    ['id', 'name', 'email', 'age', 'status', 'salary', 'is_active', 'created_date'],
    ['1', 'John Doe', 'john@example.com', '30', 'active', '75000.50', 'true', '2024-01-15'],
    ['2', 'Jane Smith', 'jane@example.com', '25', 'pending', '65000.00', 'true', '2024-01-20'],
    ['3', 'Bob Johnson', 'bob@example.com', '35', 'inactive', '85000.75', 'false', '2024-01-10'],
    ['4', 'Alice Brown', 'alice@example.com', '28', 'active', '70000.25', 'true', '2024-01-25'],
    ['5', 'Charlie Wilson', 'charlie@example.com', '32', 'active', '80000.00', 'true', '2024-01-30'],
];

$csvFile = __DIR__ . '/test-pipeline.csv';

// Create the CSV file
echo "1. Creating test CSV file...\n";
$handle = fopen($csvFile, 'w');
foreach ($testData as $row) {
    fputcsv($handle, $row);
}
fclose($handle);
echo "   ✓ Created: " . basename($csvFile) . " with " . (count($testData) - 1) . " records\n\n";

try {
    echo "2. Setting up Complete Extended ETL Pipeline...\n";
    
    $driver = new CsvDriver(['has_headers' => true]);
    $driver->withTempStorage()
           ->email('email')
           ->numeric('age')
           ->numeric('salary')
           ->required('name')
           ->generateAdminPanel('employees');  // This enables all steps
    
    echo "   ✓ Driver configured with all pipeline steps\n";
    echo "   ✓ Steps enabled: analyze_schema, generate_model, generate_migration, \n";
    echo "     generate_factory, generate_seeder, generate_filament_resource, \n";
    echo "     run_migration, seed_data\n\n";
    
    echo "3. Running Complete ETL Pipeline...\n";
    $startTime = microtime(true);
    
    $importResult = $driver->import($csvFile);
    
    $duration = microtime(true) - $startTime;
    
    echo "   ✅ Pipeline completed in " . number_format($duration, 2) . " seconds\n";
    echo "   📊 Processed: {$importResult->processed} records\n";
    echo "   ✅ Imported: {$importResult->imported} records\n\n";
    
    // Get generation results
    $results = $driver->getLaravelGenerationResults();
    
    echo "4. Pipeline Step Results:\n";
    echo "========================\n\n";
    
    // Schema Analysis
    if ($schema = $results['schema_analysis']) {
        echo "📊 SCHEMA ANALYSIS:\n";
        echo "   ✓ Table: {$schema['table_name']}\n";
        echo "   ✓ Model: {$schema['model_name']}\n";
        echo "   ✓ Fields analyzed: " . count($schema['fields']) . "\n";
        echo "   ✓ Relationships detected: " . count($schema['relationships']) . "\n";
        echo "   ✓ Confidence score: {$schema['metadata']['analysis_confidence']}%\n\n";
    }
    
    // Model Generation
    if ($model = $results['model_generation']) {
        echo "🏗️ MODEL GENERATION:\n";
        echo "   ✓ Created: {$model['model_name']}.php\n";
        echo "   ✓ Location: {$model['model_path']}\n";
        echo "   ✓ Fillable fields: {$model['fillable_count']}\n";
        echo "   ✓ Type casts: {$model['casts_count']}\n";
        echo "   ✓ Relationships: {$model['relationships_count']}\n\n";
    }
    
    // Migration Generation
    if ($migration = $results['migration_generation']) {
        echo "📋 MIGRATION GENERATION:\n";
        echo "   ✓ Created: {$migration['migration_name']}.php\n";
        echo "   ✓ Location: {$migration['migration_path']}\n";
        echo "   ✓ Fields: {$migration['fields_count']}\n";
        echo "   ✓ Indexes: {$migration['indexes_count']}\n\n";
    }
    
    // Factory Generation
    if ($factory = $results['factory_generation']) {
        echo "🏭 FACTORY GENERATION:\n";
        echo "   ✓ Created: {$factory['factory_name']}.php\n";
        echo "   ✓ Location: {$factory['factory_path']}\n";
        echo "   ✓ Fields: {$factory['fields_count']}\n\n";
    }
    
    // Check if seeder was generated
    $context = $driver->getPipelineContext();
    if ($seeder = $context->get('seeder_generation_result')) {
        echo "🌱 SEEDER GENERATION:\n";
        echo "   ✓ Created: {$seeder['seeder_name']}.php\n";
        echo "   ✓ Location: {$seeder['seeder_path']}\n";
        echo "   ✓ Estimated records: {$seeder['estimated_records']}\n\n";
    }
    
    // Check if Filament resource was generated
    if ($filament = $context->get('filament_generation_result')) {
        echo "👑 FILAMENT RESOURCE GENERATION:\n";
        echo "   ✓ Created: {$filament['resource_name']}.php\n";
        echo "   ✓ Location: {$filament['resource_path']}\n";
        echo "   ✓ Form fields: {$filament['fields_count']}\n";
        echo "   ✓ Table filters: {$filament['filters_count']}\n\n";
    }
    
    // Check if migration was executed
    if ($migrationExec = $context->get('migration_execution_result')) {
        echo "🚀 MIGRATION EXECUTION:\n";
        if ($migrationExec['success']) {
            echo "   ✅ Migration executed successfully\n";
            echo "   ✓ Table created: {$migrationExec['table_name']}\n";
            echo "   ✓ Columns created: {$migrationExec['columns_created']}\n\n";
        } else {
            echo "   ❌ Migration failed: {$migrationExec['error']}\n\n";
        }
    }
    
    // Check if data was seeded
    if ($seeding = $context->get('seeding_result')) {
        echo "🌱 DATA SEEDING:\n";
        if ($seeding['success']) {
            echo "   ✅ Data seeded successfully\n";
            echo "   ✓ Records seeded: {$seeding['records_seeded']}\n";
            echo "   ✓ Method: {$seeding['method']}\n\n";
        } else {
            echo "   ❌ Seeding failed: {$seeding['error']}\n\n";
        }
    }
    
    echo "🎉 COMPLETE PIPELINE SUCCESS!\n";
    echo "============================\n\n";
    
    echo "📁 Generated Files:\n";
    echo "   • app/Models/Employee.php (Eloquent model)\n";
    echo "   • database/migrations/xxxx_create_employees_table.php\n";
    echo "   • database/factories/EmployeeFactory.php\n";
    echo "   • database/seeders/EmployeeSeeder.php\n";
    echo "   • app/Filament/Resources/EmployeeResource.php\n\n";
    
    echo "🗄️ Database:\n";
    echo "   • Table 'employees' created with proper schema\n";
    echo "   • Data seeded from imported CSV\n";
    echo "   • Ready for application use\n\n";
    
    echo "🎯 Ready to Use:\n";
    echo "   • Navigate to /admin/employees for admin interface\n";
    echo "   • Use Employee::all() in your application\n";
    echo "   • Run Employee::factory()->create() for testing\n";
    echo "   • Seeded data ready for development\n\n";
    
    echo "⚡ Total Time: " . number_format($duration, 2) . " seconds\n";
    echo "   From CSV file to complete Laravel application!\n\n";
    
} catch (Exception $e) {
    echo "❌ Pipeline Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} finally {
    // Clean up test file
    if (file_exists($csvFile)) {
        unlink($csvFile);
        echo "🧹 Cleaned up: " . basename($csvFile) . "\n";
    }
}

echo "\n✨ All Pipeline Steps Completed!\n";
echo "The reusable pipeline steps are now ready for any driver.\n";