<?php

declare(strict_types=1);

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Crumbls\Importer\Storage\StorageReader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Seed the database with imported data or factory data
 */
class SeedDataStep
{
    public function execute(PipelineContext $context): void
    {
        $migrationResult = $context->get('migration_execution_result');
        $seederResult = $context->get('seeder_generation_result');
        
        // Check if migration was successful
        if (!$migrationResult || !$migrationResult['success']) {
            $context->set('seeding_result', [
                'executed' => false,
                'skipped' => true,
                'reason' => 'Migration was not successful'
            ]);
            return;
        }
        
        $tableName = $migrationResult['table_name'];
        $schema = $context->get('schema_analysis');
        
        // Check if table already has data
        $existingRecords = DB::table($tableName)->count();
        if ($existingRecords > 0) {
            $context->set('seeding_result', [
                'executed' => false,
                'skipped' => true,
                'reason' => "Table '{$tableName}' already contains {$existingRecords} records",
                'existing_records' => $existingRecords
            ]);
            return;
        }
        
        try {
            // Try to seed with real imported data first
            $seededCount = $this->seedWithImportedData($context, $tableName, $schema);
            
            if ($seededCount === 0) {
                // Fall back to seeder if available
                $seededCount = $this->seedWithSeeder($context, $seederResult);
            }
            
            if ($seededCount === 0) {
                // Last resort: use factory directly
                $seededCount = $this->seedWithFactory($context, $schema);
            }
            
            $context->set('seeding_result', [
                'executed' => true,
                'success' => true,
                'table_name' => $tableName,
                'records_seeded' => $seededCount,
                'method' => $this->getSeededMethod($context)
            ]);
            
        } catch (\Exception $e) {
            $context->set('seeding_result', [
                'executed' => true,
                'success' => false,
                'error' => $e->getMessage(),
                'table_name' => $tableName
            ]);
        }
    }
    
    protected function seedWithImportedData(PipelineContext $context, string $tableName, array $schema): int
    {
        $storage = $context->get('temporary_storage');
        if (!$storage) {
            return 0;
        }
        
        try {
            $reader = new StorageReader($storage);
            $totalRecords = $reader->count();
            
            if ($totalRecords === 0) {
                return 0;
            }
            
            $seededCount = 0;
            $fillableFields = $schema['fillable'] ?? [];
            
            // Seed in chunks to avoid memory issues
            $reader->chunk(100, function(array $rows) use ($tableName, $fillableFields, &$seededCount) {
                $insertData = [];
                
                foreach ($rows as $row) {
                    // Filter to only fillable fields and add timestamps
                    $cleanRow = [];
                    
                    foreach ($fillableFields as $field) {
                        $cleanRow[$field] = $row[$field] ?? null;
                    }
                    
                    // Add timestamps
                    $cleanRow['created_at'] = now();
                    $cleanRow['updated_at'] = now();
                    
                    $insertData[] = $cleanRow;
                }
                
                if (!empty($insertData)) {
                    DB::table($tableName)->insert($insertData);
                    $seededCount += count($insertData);
                }
            });
            
            $context->set('seeding_method', 'imported_data');
            return $seededCount;
            
        } catch (\Exception $e) {
            // If importing real data fails, return 0 to try other methods
            return 0;
        }
    }
    
    protected function seedWithSeeder(PipelineContext $context, ?array $seederResult): int
    {
        if (!$seederResult || !$seederResult['created']) {
            return 0;
        }
        
        $seederClass = 'Database\\Seeders\\' . $seederResult['seeder_name'];
        
        try {
            // Run the specific seeder
            $exitCode = Artisan::call('db:seed', [
                '--class' => $seederClass,
                '--force' => true
            ]);
            
            if ($exitCode === 0) {
                $context->set('seeding_method', 'seeder_class');
                return $seederResult['estimated_records'] ?? 50;
            }
            
            return 0;
            
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    protected function seedWithFactory(PipelineContext $context, array $schema): int
    {
        $modelName = $schema['model_name'];
        $modelClass = "App\\Models\\{$modelName}";
        
        if (!class_exists($modelClass)) {
            return 0;
        }
        
        try {
            $recordCount = $this->getFactorySeedCount($context);
            
            // Use the model factory to create records
            $modelClass::factory()->count($recordCount)->create();
            
            $context->set('seeding_method', 'factory_direct');
            return $recordCount;
            
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    protected function getFactorySeedCount(PipelineContext $context): int
    {
        // Try to get count from schema metadata
        $schema = $context->get('schema_analysis');
        if ($schema && isset($schema['metadata']['total_records'])) {
            return min($schema['metadata']['total_records'], 100); // Cap at 100 for safety
        }
        
        // Default to reasonable number
        return 25;
    }
    
    protected function getSeededMethod(PipelineContext $context): string
    {
        return $context->get('seeding_method', 'unknown');
    }
}