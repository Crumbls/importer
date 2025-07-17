<?php

namespace Crumbls\Importer\Services;

use Illuminate\Support\Str;

class MigrationBuilder
{
    public function generateMigrationCode(array $modelData): string
    {
        $tableName = $modelData['table_name'];
        $className = 'Create' . Str::studly($tableName) . 'Table';
        
        $upMethod = $this->generateUpMethod($tableName, $modelData['columns'], $modelData['indexes']);
        $downMethod = $this->generateDownMethod($tableName);
        
        return $this->getMigrationTemplate($className, $upMethod, $downMethod);
    }
    
    protected function generateUpMethod(string $tableName, array $columns, array $indexes): string
    {
        $columnDefinitions = $this->generateColumnDefinitions($columns);
        $indexDefinitions = $this->generateIndexDefinitions($indexes);
        
        return sprintf(
            "    public function up(): void\n    {\n        Schema::create('%s', function (Blueprint \$table) {\n%s%s        });\n    }",
            $tableName,
            $columnDefinitions,
            $indexDefinitions
        );
    }
    
    protected function generateDownMethod(string $tableName): string
    {
        return sprintf(
            "    public function down(): void\n    {\n        Schema::dropIfExists('%s');\n    }",
            $tableName
        );
    }
    
    protected function generateColumnDefinitions(array $columns): string
    {
        $definitions = [];
        
        foreach ($columns as $column) {
            $definition = $this->generateColumnDefinition($column);
            if ($definition) {
                $definitions[] = "            {$definition};";
            }
        }
        
        // Always add timestamps at the end if not already present
        if (!$this->hasTimestamps($columns)) {
            $definitions[] = "            \$table->timestamps();";
        }
        
        return implode("\n", $definitions) . "\n";
    }
    
    protected function generateColumnDefinition(array $column): string
    {
        $name = $column['name'];
        $type = $column['type'];
        
        // Handle special column types
        if ($type === 'id') {
            return "\$table->id('{$name}')";
        }
        
        if ($name === 'created_at' || $name === 'updated_at') {
            return ''; // Skip individual timestamp columns
        }
        
        // Start building the column definition
        $definition = "\$table->{$type}('{$name}'";
        
        // Add length parameter for applicable types
        if (in_array($type, ['string', 'decimal']) && isset($column['length'])) {
            $definition .= ", {$column['length']}";
        }
        
        $definition .= ')';
        
        // Add column modifiers
        if ($column['nullable'] ?? false) {
            $definition .= '->nullable()';
        }
        
        if ($column['unique'] ?? false) {
            $definition .= '->unique()';
        }
        
        if (isset($column['default']) && $column['default'] !== null) {
            if (is_string($column['default'])) {
                $definition .= "->default('{$column['default']}')";
            } else {
                $definition .= "->default({$column['default']})";
            }
        }
        
        if ($column['auto_increment'] ?? false) {
            $definition .= '->autoIncrement()';
        }
        
        return $definition;
    }
    
    protected function generateIndexDefinitions(array $indexes): string
    {
        if (empty($indexes)) {
            return '';
        }
        
        $definitions = [];
        
        foreach ($indexes as $index) {
            $definition = $this->generateIndexDefinition($index);
            if ($definition) {
                $definitions[] = "            {$definition};";
            }
        }
        
        return empty($definitions) ? '' : "\n" . implode("\n", $definitions) . "\n";
    }
    
    protected function generateIndexDefinition(array $index): string
    {
        $columns = $index['columns'];
        $isUnique = $index['unique'] ?? false;
        $indexName = $index['name'] ?? null;
        
        if (empty($columns)) {
            return '';
        }
        
        // Format columns for the index
        if (count($columns) === 1) {
            $columnList = "'{$columns[0]}'";
        } else {
            $columnList = "['" . implode("', '", $columns) . "']";
        }
        
        // Generate the index definition
        if ($isUnique) {
            $definition = "\$table->unique({$columnList}";
        } else {
            $definition = "\$table->index({$columnList}";
        }
        
        // Add custom index name if provided
        if ($indexName) {
            $definition .= ", '{$indexName}'";
        }
        
        $definition .= ')';
        
        return $definition;
    }
    
    protected function hasTimestamps(array $columns): bool
    {
        $timestampColumns = ['created_at', 'updated_at'];
        $columnNames = array_column($columns, 'name');
        
        return !empty(array_intersect($timestampColumns, $columnNames));
    }
    
    protected function getMigrationTemplate(string $className, string $upMethod, string $downMethod): string
    {
        return "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
{$upMethod}

{$downMethod}
};
";
    }
    
    public function generatePivotMigration(string $table1, string $table2, array $additionalColumns = []): string
    {
        $tableName = $this->generatePivotTableName($table1, $table2);
        $className = 'Create' . Str::studly($tableName) . 'Table';
        
        $columns = [
            [
                'name' => 'id',
                'type' => 'id',
                'nullable' => false,
                'primary' => true,
                'auto_increment' => true,
            ],
            [
                'name' => Str::singular($table1) . '_id',
                'type' => 'foreignId',
                'nullable' => false,
            ],
            [
                'name' => Str::singular($table2) . '_id',
                'type' => 'foreignId',
                'nullable' => false,
            ],
        ];
        
        // Add any additional columns
        $columns = array_merge($columns, $additionalColumns);
        
        $upMethod = $this->generateUpMethod($tableName, $columns, []);
        $downMethod = $this->generateDownMethod($tableName);
        
        return $this->getMigrationTemplate($className, $upMethod, $downMethod);
    }
    
    protected function generatePivotTableName(string $table1, string $table2): string
    {
        $tables = [Str::singular($table1), Str::singular($table2)];
        sort($tables);
        return implode('_', $tables);
    }
}