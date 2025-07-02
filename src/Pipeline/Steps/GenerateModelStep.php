<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generate Eloquent Model from schema analysis
 */
class GenerateModelStep
{
    public function execute(PipelineContext $context): void
    {
        $schema = $context->get('schema_analysis');
        if (!$schema) {
            throw new \RuntimeException('No schema analysis available for model generation');
        }
        
        $requiresModel = $context->get('requires_model', true);
        if (!$requiresModel) {
            $context->set('model_generation_result', [
                'created' => false,
                'reason' => 'Model already exists',
                'model_path' => "app/Models/{$schema['model_name']}.php"
            ]);
            return;
        }
        
        $modelContent = $this->generateModelContent($schema);
        $modelPath = $this->getModelPath($schema['model_name']);
        
        // Ensure Models directory exists
        $this->ensureDirectoryExists(dirname($modelPath));
        
        // Write model file
        File::put($modelPath, $modelContent);
        
        $context->set('model_generation_result', [
            'created' => true,
            'model_name' => $schema['model_name'],
            'model_path' => $modelPath,
            'table_name' => $schema['table_name'],
            'fillable_count' => count($schema['fillable']),
            'casts_count' => count($schema['casts']),
            'relationships_count' => count($schema['relationships'])
        ]);
    }
    
    protected function generateModelContent(array $schema): string
    {
        $modelName = $schema['model_name'];
        $tableName = $schema['table_name'];
        $fillable = $schema['fillable'];
        $casts = $schema['casts'];
        $relationships = $schema['relationships'];
        
        $template = "<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * {$modelName} Model
 * 
 * Generated automatically from CSV import
 * Table: {$tableName}
 * 
 * @property int \$id
{$this->generatePropertyDocumentation($schema['fields'])}
 */
class {$modelName} extends Model
{
    use HasFactory;
    
    /**
     * The table associated with the model.
     */
    protected \$table = '{$tableName}';
    
    /**
     * The attributes that are mass assignable.
     */
    protected \$fillable = [
{$this->formatArrayForCode($fillable, 8)}
    ];
    
{$this->generateCastsProperty($casts)}
{$this->generateValidationRules($schema['validation_rules'])}
{$this->generateRelationships($relationships)}
{$this->generateScopes($schema)}
{$this->generateAccessors($schema)}
}
";
        
        return $template;
    }
    
    protected function generatePropertyDocumentation(array $fields): string
    {
        $docs = [];
        
        foreach ($fields as $field) {
            $phpType = $this->mapToPhpType($field['type'], $field['nullable']);
            $docs[] = " * @property {$phpType} \${$field['name']}";
        }
        
        $docs[] = " * @property \Carbon\Carbon \$created_at";
        $docs[] = " * @property \Carbon\Carbon \$updated_at";
        
        return implode("\n", $docs);
    }
    
    protected function mapToPhpType(string $type, bool $nullable): string
    {
        $phpType = match($type) {
            'integer' => 'int',
            'decimal' => 'float',
            'boolean' => 'bool',
            'timestamp' => '\Carbon\Carbon',
            'json' => 'array',
            default => 'string'
        };
        
        return $nullable ? "{$phpType}|null" : $phpType;
    }
    
    protected function formatArrayForCode(array $items, int $indent = 4): string
    {
        if (empty($items)) {
            return '';
        }
        
        $spaces = str_repeat(' ', $indent);
        $formatted = [];
        
        foreach ($items as $item) {
            $formatted[] = $spaces . "'{$item}',";
        }
        
        return implode("\n", $formatted);
    }
    
    protected function generateCastsProperty(array $casts): string
    {
        if (empty($casts)) {
            return '';
        }
        
        $castsFormatted = [];
        foreach ($casts as $field => $cast) {
            $castsFormatted[] = "        '{$field}' => '{$cast}',";
        }
        
        return "    /**
     * The attributes that should be cast.
     */
    protected \$casts = [
" . implode("\n", $castsFormatted) . "
    ];
    
";
    }
    
    protected function generateValidationRules(array $validationRules): string
    {
        if (empty($validationRules)) {
            return '';
        }
        
        $rulesFormatted = [];
        foreach ($validationRules as $field => $rules) {
            $rulesString = "'" . implode('|', $rules) . "'";
            $rulesFormatted[] = "            '{$field}' => {$rulesString},";
        }
        
        return "    /**
     * Validation rules for this model
     */
    public static function validationRules(): array
    {
        return [
" . implode("\n", $rulesFormatted) . "
        ];
    }
    
";
    }
    
    protected function generateRelationships(array $relationships): string
    {
        if (empty($relationships)) {
            return '';
        }
        
        $methods = [];
        
        foreach ($relationships as $relationship) {
            $methodName = $relationship['method_name'];
            $relatedModel = $relationship['related_model'];
            $foreignKey = $relationship['foreign_key'];
            $type = $relationship['type'];
            
            $methods[] = "    /**
     * Get the {$relatedModel} that owns this {strtolower($relatedModel)}.
     */
    public function {$methodName}()
    {
        return \$this->{$type}({$relatedModel}::class, '{$foreignKey}');
    }";
        }
        
        return implode("\n\n", $methods) . "\n\n";
    }
    
    protected function generateScopes(array $schema): string
    {
        $scopes = [];
        
        // Generate common scopes based on field analysis
        foreach ($schema['fields'] as $field) {
            if ($field['name'] === 'status' || $field['name'] === 'active') {
                $scopes[] = "    /**
     * Scope a query to only include active records.
     */
    public function scopeActive(\$query)
    {
        return \$query->where('{$field['name']}', 'active');
    }";
            }
            
            if ($field['type'] === 'timestamp' && str_contains($field['name'], 'date')) {
                $methodName = Str::camel('recent_' . $field['name']);
                $scopes[] = "    /**
     * Scope a query to only include recent records.
     */
    public function scope" . Str::studly($methodName) . "(\$query, \$days = 30)
    {
        return \$query->where('{$field['name']}', '>=', now()->subDays(\$days));
    }";
            }
        }
        
        return empty($scopes) ? '' : implode("\n\n", $scopes) . "\n\n";
    }
    
    protected function generateAccessors(array $schema): string
    {
        $accessors = [];
        
        // Generate accessors for common patterns
        foreach ($schema['fields'] as $field) {
            if (str_contains($field['name'], 'name') && $field['type'] === 'string') {
                $accessorName = Str::studly($field['name']);
                $accessors[] = "    /**
     * Get the formatted {$field['name']}.
     */
    public function get{$accessorName}Attribute(\$value)
    {
        return ucwords(strtolower(\$value));
    }";
            }
            
            if ($field['name'] === 'email') {
                $accessors[] = "    /**
     * Get the masked email for privacy.
     */
    public function getMaskedEmailAttribute()
    {
        \$email = \$this->email;
        \$parts = explode('@', \$email);
        
        if (count(\$parts) !== 2) {
            return \$email;
        }
        
        \$username = \$parts[0];
        \$domain = \$parts[1];
        
        \$maskedUsername = substr(\$username, 0, 2) . str_repeat('*', max(1, strlen(\$username) - 2));
        
        return \$maskedUsername . '@' . \$domain;
    }";
            }
        }
        
        return empty($accessors) ? '' : implode("\n\n", $accessors) . "\n\n";
    }
    
    protected function getModelPath(string $modelName): string
    {
        return app_path("Models/{$modelName}.php");
    }
    
    protected function ensureDirectoryExists(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }
}