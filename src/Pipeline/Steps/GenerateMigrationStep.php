<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generate Laravel Migration from schema analysis
 */
class GenerateMigrationStep
{
    public function execute(PipelineContext $context): void
    {
        $schema = $context->get('schema_analysis');
        if (!$schema) {
            throw new \RuntimeException('No schema analysis available for migration generation');
        }
        
        $requiresMigration = $context->get('requires_migration', true);
        if (!$requiresMigration) {
            $context->set('migration_generation_result', [
                'created' => false,
                'reason' => 'Table already exists',
                'table_name' => $schema['table_name']
            ]);
            return;
        }
        
        $migrationContent = $this->generateMigrationContent($schema);
        $migrationPath = $this->generateMigrationPath($schema['table_name']);
        
        // Ensure migrations directory exists
        $this->ensureDirectoryExists(dirname($migrationPath));
        
        // Write migration file
        File::put($migrationPath, $migrationContent);
        
        $context->set('migration_generation_result', [
            'created' => true,
            'migration_path' => $migrationPath,
            'migration_name' => $this->getMigrationName($schema['table_name']),
            'table_name' => $schema['table_name'],
            'fields_count' => count($schema['fields']),
            'indexes_count' => count($schema['indexes'])
        ]);
    }
    
    protected function generateMigrationContent(array $schema): string
    {
        $tableName = $schema['table_name'];
        $className = $this->getMigrationClassName($tableName);
        
        return "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for {$tableName} table
 * 
 * Generated automatically from CSV import
 * Date: " . date('Y-m-d H:i:s') . "
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            
{$this->generateFieldDefinitions($schema['fields'])}
            
            \$table->timestamps();
            
{$this->generateIndexDefinitions($schema['indexes'])}
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
";
    }
    
    protected function generateFieldDefinitions(array $fields): string
    {
        $definitions = [];
        
        foreach ($fields as $field) {
            $definition = $this->generateSingleFieldDefinition($field);
            $definitions[] = "            {$definition}";
        }
        
        return implode("\n", $definitions);
    }
    
    protected function generateSingleFieldDefinition(array $field): string
    {
        $name = $field['name'];
        $type = $field['type'];
        $nullable = $field['nullable'];
        $length = $field['length'] ?? null;
        $unique = $field['unique'] ?? false;
        
        // Start with base type
        $definition = match($type) {
            'string' => $length ? "\$table->string('{$name}', {$length})" : "\$table->text('{$name}')",
            'integer' => "\$table->integer('{$name}')",
            'decimal' => "\$table->decimal('{$name}', 8, 2)",
            'boolean' => "\$table->boolean('{$name}')",
            'timestamp' => "\$table->timestamp('{$name}')",
            'json' => "\$table->json('{$name}')",
            'text' => "\$table->text('{$name}')",
            default => "\$table->string('{$name}')"
        };
        
        // Add modifiers
        if ($nullable) {
            $definition .= "->nullable()";
        }
        
        if ($unique) {
            $definition .= "->unique()";
        }
        
        // Add comment with detected information
        $definition .= "->comment('Auto-generated from CSV import')";
        
        $definition .= ";";
        
        return $definition;
    }
    
    protected function generateIndexDefinitions(array $indexes): string
    {
        if (empty($indexes)) {
            return '';
        }
        
        $definitions = [];
        
        foreach ($indexes as $index) {
            $field = $index['field'];
            $type = $index['type'];
            $reason = $index['reason'];
            
            $definition = match($type) {
                'unique' => "\$table->unique('{$field}');",
                'index' => "\$table->index('{$field}');",
                default => "\$table->index('{$field}');"
            };
            
            $definitions[] = "            // {$reason}";
            $definitions[] = "            {$definition}";
        }
        
        return "\n" . implode("\n", $definitions);
    }
    
    protected function getMigrationClassName(string $tableName): string
    {
        return 'Create' . Str::studly($tableName) . 'Table';
    }
    
    protected function getMigrationName(string $tableName): string
    {
        return 'create_' . $tableName . '_table';
    }
    
    protected function generateMigrationPath(string $tableName): string
    {
        $timestamp = date('Y_m_d_His');
        $migrationName = $this->getMigrationName($tableName);
        
        return database_path("migrations/{$timestamp}_{$migrationName}.php");
    }
    
    protected function ensureDirectoryExists(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }
}