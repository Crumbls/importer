<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Pipeline\ExtendedPipelineConfiguration;

/**
 * Extended ETL Pipeline Examples
 * 
 * Shows how to go from CSV to complete Laravel application setup
 */

echo "🚀 Extended ETL Pipeline Examples\n";
echo "==================================\n\n";

// Example CSV files for demonstration
$examples = [
    'customers.csv' => ['name', 'email', 'phone', 'registration_date', 'status'],
    'products.csv' => ['sku', 'name', 'price', 'category', 'in_stock', 'description'],
    'orders.csv' => ['order_id', 'customer_id', 'product_id', 'quantity', 'total', 'order_date']
];

echo "📋 Available Examples:\n";
foreach ($examples as $filename => $headers) {
    echo "   • {$filename}: " . implode(', ', $headers) . "\n";
}
echo "\n" . str_repeat("=", 60) . "\n\n";

// =============================================================================
// EXAMPLE 1: Quick Model Generation
// =============================================================================

echo "🎯 EXAMPLE 1: Quick Model Generation\n";
echo "====================================\n\n";

echo "Goal: Generate just an Eloquent model from customer data\n\n";

echo "```php\n";
echo "\$driver = new CsvDriver(['has_headers' => true]);\n";
echo "\$driver->withTempStorage()\n";
echo "       ->email('email')\n";
echo "       ->required('name')\n";
echo "       ->generateModel();  // 👈 This is new!\n\n";
echo "\$result = \$driver->import('customers.csv');\n";
echo "```\n\n";

echo "What this generates:\n";
echo "✅ app/Models/Customer.php\n";
echo "   - Proper field types based on data analysis\n";
echo "   - \$fillable array with all importable fields\n";
echo "   - \$casts for dates, numbers, booleans\n";
echo "   - Validation rules method\n";
echo "   - Accessor methods for common patterns\n";
echo "   - Relationship methods (if detected)\n\n";

// =============================================================================
// EXAMPLE 2: Model + Migration Generation
// =============================================================================

echo "🏗️ EXAMPLE 2: Model + Migration Generation\n";
echo "==========================================\n\n";

echo "Goal: Generate model AND database migration from product data\n\n";

echo "```php\n";
echo "\$driver = new CsvDriver(['has_headers' => true]);\n";
echo "\$driver->withTempStorage()\n";
echo "       ->numeric('price')\n";
echo "       ->required('sku')\n";
echo "       ->generateMigration()  // 👈 Includes model + migration\n";
echo "       ->withTableName('products');  // 👈 Custom table name\n\n";
echo "\$result = \$driver->import('products.csv');\n";
echo "```\n\n";

echo "What this generates:\n";
echo "✅ app/Models/Product.php (same as Example 1)\n";
echo "✅ database/migrations/xxxx_create_products_table.php\n";
echo "   - Optimized field types (\$table->decimal('price', 8, 2))\n";
echo "   - Nullable/required constraints based on data\n";
echo "   - Indexes on fields that look unique or searchable\n";
echo "   - Comments explaining auto-generation\n\n";

// =============================================================================
// EXAMPLE 3: Complete Laravel Stack
// =============================================================================

echo "🎊 EXAMPLE 3: Complete Laravel Stack\n";
echo "====================================\n\n";

echo "Goal: Generate EVERYTHING - Model, Migration, Factory, Seeder\n\n";

echo "```php\n";
echo "\$driver = new CsvDriver(['has_headers' => false]);\n";
echo "\$driver->columns(['order_id', 'customer_id', 'product_id', 'quantity', 'total', 'order_date'])\n";
echo "       ->withTempStorage()\n";
echo "       ->numeric('quantity')\n";
echo "       ->numeric('total')\n";
echo "       ->required('order_id')\n";
echo "       ->generateLaravelStack('orders', 'Order');  // 👈 Full stack!\n\n";
echo "\$result = \$driver->import('orders.csv');\n";
echo "```\n\n";

echo "What this generates:\n";
echo "✅ app/Models/Order.php\n";
echo "✅ database/migrations/xxxx_create_orders_table.php\n";
echo "✅ database/factories/OrderFactory.php\n";
echo "   - Realistic fake data based on your actual data patterns\n";
echo "   - Uses analyzed data to generate appropriate fakes\n";
echo "✅ database/seeders/OrderSeeder.php\n";
echo "   - Seeds using the factory with realistic data\n\n";

// =============================================================================
// EXAMPLE 4: Admin Panel Ready (with Filament)
// =============================================================================

echo "👑 EXAMPLE 4: Admin Panel Ready (with Filament)\n";
echo "===============================================\n\n";

echo "Goal: Generate complete admin panel from customer data\n\n";

echo "```php\n";
echo "\$driver = new CsvDriver(['has_headers' => true]);\n";
echo "\$driver->withTempStorage()\n";
echo "       ->email('email')\n";
echo "       ->required('name')\n";
echo "       ->generateAdminPanel('customers');  // 👈 Admin panel ready!\n\n";
echo "\$result = \$driver->import('customers.csv');\n\n";
echo "// Migration is automatically run!\n";
echo "// Data is automatically seeded!\n";
echo "```\n\n";

echo "What this generates:\n";
echo "✅ app/Models/Customer.php\n";
echo "✅ database/migrations/xxxx_create_customers_table.php\n";
echo "✅ database/factories/CustomerFactory.php\n";
echo "✅ database/seeders/CustomerSeeder.php\n";
echo "✅ app/Filament/Resources/CustomerResource.php\n";
echo "   - Complete CRUD interface\n";
echo "   - Table with sortable columns\n";
echo "   - Form with proper field types\n";
echo "   - Filters based on data analysis\n";
echo "   - Bulk actions (delete, export)\n";
echo "🚀 Runs migration automatically\n";
echo "🚀 Seeds data automatically\n";
echo "🎉 Ready to use at /admin/customers\n\n";

// =============================================================================
// EXAMPLE 5: Advanced Configuration
// =============================================================================

echo "⚙️ EXAMPLE 5: Advanced Configuration\n";
echo "====================================\n\n";

echo "Goal: Fine-tune exactly what gets generated\n\n";

echo "```php\n";
echo "\$config = ExtendedPipelineConfiguration::make()\n";
echo "    ->withModelGeneration([\n";
echo "        'use_soft_deletes' => true,\n";
echo "        'generate_scopes' => false\n";
echo "    ])\n";
echo "    ->withMigrationGeneration([\n";
echo "        'add_foreign_keys' => true,\n";
echo "        'optimize_for_search' => true\n";
echo "    ])\n";
echo "    ->withFilamentGeneration([\n";
echo "        'add_bulk_actions' => true,\n";
echo "        'add_export_action' => true\n";
echo "    ])\n";
echo "    ->dryRun()  // Generate to temp folder first\n";
echo "    ->skipIfExists();  // Don't overwrite existing files\n\n";
echo "\$driver = new CsvDriver(['has_headers' => true]);\n";
echo "\$driver->withLaravelGeneration(\$config)\n";
echo "       ->import('data.csv');\n";
echo "```\n\n";

echo "Benefits:\n";
echo "✅ Complete control over what gets generated\n";
echo "✅ Safety features (dry run, skip existing)\n";
echo "✅ Customizable for any project structure\n";
echo "✅ Can be configured via config files\n\n";

// =============================================================================
// EXAMPLE 6: Multiple Files/Tables
// =============================================================================

echo "📊 EXAMPLE 6: Multiple Files/Tables\n";
echo "===================================\n\n";

echo "Goal: Process multiple related CSV files\n\n";

echo "```php\n";
echo "// Process customers first\n";
echo "\$customerDriver = new CsvDriver(['has_headers' => true]);\n";
echo "\$customerDriver->generateLaravelStack('customers', 'Customer');\n";
echo "\$customerResult = \$customerDriver->import('customers.csv');\n\n";
echo "// Process orders (with foreign key to customers)\n";
echo "\$orderDriver = new CsvDriver(['has_headers' => true]);\n";
echo "\$orderDriver->generateLaravelStack('orders', 'Order');\n";
echo "\$orderResult = \$orderDriver->import('orders.csv');\n\n";
echo "// The system detects customer_id and creates relationships!\n";
echo "```\n\n";

echo "Smart relationship detection:\n";
echo "✅ Detects foreign key patterns (customer_id → Customer)\n";
echo "✅ Generates belongsTo/hasMany relationships\n";
echo "✅ Creates proper foreign key constraints\n";
echo "✅ Adds relationship methods to models\n\n";

// =============================================================================
// Benefits Summary
// =============================================================================

echo "🎉 EXTENDED ETL BENEFITS\n";
echo "========================\n\n";

echo "🚀 **From CSV to Running Application in Minutes**\n";
echo "   • Import data → Generate complete Laravel setup → Ready to use\n\n";

echo "🧠 **Intelligent Analysis**\n";
echo "   • Analyzes your actual data to generate proper types\n";
echo "   • Detects relationships between tables\n";
echo "   • Suggests indexes for performance\n\n";

echo "🛡️ **Production Ready**\n";
echo "   • Proper validation rules based on data patterns\n";
echo "   • Optimized database schema\n";
echo "   • Factory/Seeder for testing\n\n";

echo "⚡ **Developer Experience**\n";
echo "   • Fluent API for easy configuration\n";
echo "   • Configurable steps (enable/disable what you need)\n";
echo "   • Safety features (dry run, backups)\n\n";

echo "🎨 **Filament Integration**\n";
echo "   • Complete admin interface generated automatically\n";
echo "   • Proper form fields based on data types\n";
echo "   • Filters, search, bulk actions\n\n";

echo "⚙️ **Flexible & Reusable**\n";
echo "   • Works with any CSV structure\n";
echo "   • Configurable for different project needs\n";
echo "   • Can be extended for other data formats\n\n";

echo "This transforms the traditional ETL process:\n";
echo "❌ Extract → Transform → Load → Manual Laravel Setup (hours/days)\n";
echo "✅ Extract → Transform → Load → Auto Laravel Setup (minutes)\n\n";

echo "🎯 **Perfect for:**\n";
echo "   • Data migrations to new Laravel apps\n";
echo "   • Rapid prototyping with real data\n";
echo "   • Converting legacy systems\n";
echo "   • Creating admin panels from spreadsheets\n";
echo "   • MVP development with existing data\n\n";

echo "✨ The CSV to Laravel pipeline is now COMPLETE!\n";