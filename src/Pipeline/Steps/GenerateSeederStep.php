<?php

declare(strict_types=1);

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generate Database Seeder from schema analysis and imported data
 */
class GenerateSeederStep
{
    public function execute(PipelineContext $context): void
    {
        $schema = $context->get('schema_analysis');
        if (!$schema) {
            throw new \RuntimeException('No schema analysis available for seeder generation');
        }
        
        $seederContent = $this->generateSeederContent($schema, $context);
        $seederPath = $this->getSeederPath($schema['model_name']);
        
        // Ensure Seeders directory exists
        $this->ensureDirectoryExists(dirname($seederPath));
        
        // Write seeder file
        File::put($seederPath, $seederContent);
        
        $context->set('seeder_generation_result', [
            'created' => true,
            'seeder_name' => $schema['model_name'] . 'Seeder',
            'seeder_path' => $seederPath,
            'model_name' => $schema['model_name'],
            'table_name' => $schema['table_name'],
            'estimated_records' => $this->getEstimatedRecordCount($context)
        ]);
    }
    
    protected function generateSeederContent(array $schema, PipelineContext $context): string
    {
        $modelName = $schema['model_name'];
        $seederName = $modelName . 'Seeder';
        $tableName = $schema['table_name'];
        
        $recordCount = $this->getEstimatedRecordCount($context);
        $useRealData = $this->shouldUseRealData($context);
        
        return "<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\\{$modelName};

/**
 * {$seederName}
 * 
 * Generated automatically from CSV import
 * Contains realistic data based on imported patterns
 * Estimated records: {$recordCount}
 */
class {$seederName} extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data
        DB::table('{$tableName}')->truncate();
        
{$this->generateSeedingLogic($schema, $context, $useRealData)}
    }
    
{$this->generateHelperMethods($schema, $context)}
}
";
    }
    
    protected function generateSeedingLogic(array $schema, PipelineContext $context, bool $useRealData): string
    {
        $modelName = $schema['model_name'];
        $recordCount = $this->getEstimatedRecordCount($context);
        
        if ($useRealData && $this->hasImportedData($context)) {
            return $this->generateRealDataSeeding($schema, $context);
        } else {
            return $this->generateFactorySeeding($modelName, $recordCount);
        }
    }
    
    protected function generateRealDataSeeding(array $schema, PipelineContext $context): string
    {
        $modelName = $schema['model_name'];
        $tableName = $schema['table_name'];
        
        return "        // Seed with real imported data
        \$this->command->info('Seeding {$tableName} with imported data...');
        
        \$importedData = \$this->getImportedData();
        
        if (empty(\$importedData)) {
            \$this->command->warn('No imported data found, using factory instead...');
            {$modelName}::factory()->count(50)->create();
            return;
        }
        
        \$chunks = array_chunk(\$importedData, 100);
        \$totalRecords = count(\$importedData);
        
        \$this->command->info(\"Inserting {\$totalRecords} records in chunks...\");
        
        foreach (\$chunks as \$index => \$chunk) {
            DB::table('{$tableName}')->insert(\$chunk);
            \$this->command->info('Inserted chunk ' . (\$index + 1) . '/' . count(\$chunks));
        }
        
        \$this->command->info(\"Successfully seeded {\$totalRecords} {$tableName} records!\");";
    }
    
    protected function generateFactorySeeding(string $modelName, int $recordCount): string
    {
        $batchSize = min(100, $recordCount);
        $totalBatches = ceil($recordCount / $batchSize);
        
        return "        // Seed with factory-generated data
        \$this->command->info('Seeding {$modelName} with factory data...');
        
        \$recordCount = {$recordCount};
        \$batchSize = {$batchSize};
        \$batches = ceil(\$recordCount / \$batchSize);
        
        for (\$i = 0; \$i < \$batches; \$i++) {
            \$currentBatchSize = min(\$batchSize, \$recordCount - (\$i * \$batchSize));
            
            {$modelName}::factory()
                ->count(\$currentBatchSize)
                ->create();
                
            \$this->command->info('Created batch ' . (\$i + 1) . '/' . \$batches . ' (' . \$currentBatchSize . ' records)');
        }
        
        \$this->command->info(\"Successfully created {\$recordCount} {$modelName} records!\");";
    }
    
    protected function generateHelperMethods(array $schema, PipelineContext $context): string
    {
        $methods = [];
        
        // Add method to get imported data if available
        if ($this->hasImportedData($context)) {
            $methods[] = $this->generateGetImportedDataMethod($schema, $context);
        }
        
        // Add method to validate seeded data
        $methods[] = $this->generateValidationMethod($schema);
        
        // Add cleanup method
        $methods[] = $this->generateCleanupMethod($schema);
        
        return implode("\n\n", $methods);
    }
    
    protected function generateGetImportedDataMethod(array $schema, PipelineContext $context): string
    {
        $fillableFields = $schema['fillable'];
        $tableName = $schema['table_name'];
        
        $fillableList = "'" . implode("', '", $fillableFields) . "'";
        
        return "    /**
     * Get imported data for seeding
     * 
     * @return array
     */
    protected function getImportedData(): array
    {
        // In a real implementation, this would read from the imported storage
        // For now, return empty array to use factory instead
        
        // Example implementation:
        // \$storage = app('importer.storage');
        // \$reader = new StorageReader(\$storage);
        // return \$reader->all();
        
        return [];
    }";
    }
    
    protected function generateValidationMethod(array $schema): string
    {
        $modelName = $schema['model_name'];
        $tableName = $schema['table_name'];
        
        return "    /**
     * Validate seeded data
     */
    protected function validateSeededData(): void
    {
        \$count = DB::table('{$tableName}')->count();
        \$this->command->info(\"Validation: {$tableName} has {\$count} records\");
        
        // Check for required fields
        \$nullRequiredFields = DB::table('{$tableName}')
            ->whereNull('name') // Adjust based on required fields
            ->count();
            
        if (\$nullRequiredFields > 0) {
            \$this->command->warn(\"Warning: {\$nullRequiredFields} records have null required fields\");
        }
        
        // Check for duplicates if unique fields exist
        \$duplicates = DB::table('{$tableName}')
            ->select('email') // Adjust based on unique fields
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->count();
            
        if (\$duplicates > 0) {
            \$this->command->warn(\"Warning: {\$duplicates} duplicate records found\");
        }
    }";
    }
    
    protected function generateCleanupMethod(array $schema): string
    {
        $tableName = $schema['table_name'];
        
        return "    /**
     * Clean up seeded data (useful for testing)
     */
    public function cleanup(): void
    {
        DB::table('{$tableName}')->truncate();
        \$this->command->info('Cleaned up {$tableName} table');
    }";
    }
    
    protected function getEstimatedRecordCount(PipelineContext $context): int
    {
        // Try to get actual count from metadata
        $schema = $context->get('schema_analysis');
        if ($schema && isset($schema['metadata']['total_records'])) {
            return min($schema['metadata']['total_records'], 1000); // Cap at 1000 for seeding
        }
        
        // Default reasonable number
        return 50;
    }
    
    protected function shouldUseRealData(PipelineContext $context): bool
    {
        $config = $context->get('extended_config');
        if (!$config) {
            return false;
        }
        
        // Check if configuration allows real data seeding
        return $config->get('factory_options.use_existing_data_patterns', false);
    }
    
    protected function hasImportedData(PipelineContext $context): bool
    {
        $storage = $context->get('temporary_storage');
        return $storage !== null;
    }
    
    protected function getSeederPath(string $modelName): string
    {
        return database_path("seeders/{$modelName}Seeder.php");
    }
    
    protected function ensureDirectoryExists(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }
}