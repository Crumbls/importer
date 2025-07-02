<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Crumbls\Importer\Storage\StorageReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generate Model Factory from schema analysis and real data patterns
 */
class GenerateFactoryStep
{
    public function execute(PipelineContext $context): void
    {
        $schema = $context->get('schema_analysis');
        if (!$schema) {
            throw new \RuntimeException('No schema analysis available for factory generation');
        }
        
        $factoryContent = $this->generateFactoryContent($schema, $context);
        $factoryPath = $this->getFactoryPath($schema['model_name']);
        
        // Ensure Factories directory exists
        $this->ensureDirectoryExists(dirname($factoryPath));
        
        // Write factory file
        File::put($factoryPath, $factoryContent);
        
        $context->set('factory_generation_result', [
            'created' => true,
            'factory_name' => $schema['model_name'] . 'Factory',
            'factory_path' => $factoryPath,
            'model_name' => $schema['model_name'],
            'fields_count' => count($schema['fields'])
        ]);
    }
    
    protected function generateFactoryContent(array $schema, PipelineContext $context): string
    {
        $modelName = $schema['model_name'];
        $factoryName = $modelName . 'Factory';
        
        // Analyze real data patterns for realistic fakes
        $dataPatterns = $this->analyzeDataPatterns($context);
        
        return "<?php

namespace Database\Factories;

use App\Models\\{$modelName};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * {$factoryName}
 * 
 * Generated automatically from CSV import
 * Uses real data patterns for realistic fake data
 * 
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\\{$modelName}>
 */
class {$factoryName} extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected \$model = {$modelName}::class;
    
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
{$this->generateFieldDefinitions($schema['fields'], $dataPatterns)}
        ];
    }
    
{$this->generateFactoryStates($schema['fields'], $dataPatterns)}
{$this->generateFactoryTraits($schema['fields'])}
}
";
    }
    
    protected function analyzeDataPatterns(PipelineContext $context): array
    {
        $storage = $context->get('temporary_storage');
        if (!$storage) {
            return [];
        }
        
        $reader = new StorageReader($storage);
        $patterns = [];
        
        // Sample data for pattern analysis
        $sampleSize = 100;
        $samples = [];
        
        $reader->chunk(50, function($rows) use (&$samples, &$sampleSize) {
            foreach ($rows as $row) {
                if ($sampleSize-- <= 0) return false;
                $samples[] = $row;
            }
        });
        
        if (empty($samples)) {
            return [];
        }
        
        // Analyze patterns for each field
        foreach ($samples[0] as $field => $value) {
            $fieldSamples = array_column($samples, $field);
            $fieldSamples = array_filter($fieldSamples, fn($v) => !empty($v));
            
            if (empty($fieldSamples)) {
                continue;
            }
            
            $patterns[$field] = [
                'samples' => array_slice($fieldSamples, 0, 10),
                'unique_values' => array_unique($fieldSamples),
                'common_values' => $this->getMostCommonValues($fieldSamples),
                'pattern_type' => $this->detectPatternType($fieldSamples),
                'length_range' => [
                    'min' => min(array_map('strlen', $fieldSamples)),
                    'max' => max(array_map('strlen', $fieldSamples)),
                    'avg' => round(array_sum(array_map('strlen', $fieldSamples)) / count($fieldSamples))
                ]
            ];
        }
        
        return $patterns;
    }
    
    protected function getMostCommonValues(array $values): array
    {
        $counts = array_count_values($values);
        arsort($counts);
        return array_slice(array_keys($counts), 0, 5);
    }
    
    protected function detectPatternType(array $values): string
    {
        $sample = array_slice($values, 0, 10);
        
        // Check for enum-like values
        $uniqueCount = count(array_unique($values));
        if ($uniqueCount <= 10 && $uniqueCount < count($values) * 0.5) {
            return 'enum';
        }
        
        // Check for name patterns
        if ($this->isNamePattern($sample)) {
            return 'name';
        }
        
        // Check for email patterns
        if ($this->isEmailPattern($sample)) {
            return 'email';
        }
        
        // Check for phone patterns
        if ($this->isPhonePattern($sample)) {
            return 'phone';
        }
        
        // Check for address patterns
        if ($this->isAddressPattern($sample)) {
            return 'address';
        }
        
        // Check for company patterns
        if ($this->isCompanyPattern($sample)) {
            return 'company';
        }
        
        return 'generic';
    }
    
    protected function generateFieldDefinitions(array $fields, array $dataPatterns): string
    {
        $definitions = [];
        
        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            $nullable = $field['nullable'];
            
            $pattern = $dataPatterns[$fieldName] ?? null;
            $definition = $this->generateSingleFieldDefinition($fieldName, $fieldType, $nullable, $pattern);
            
            $definitions[] = "            '{$fieldName}' => {$definition},";
        }
        
        return implode("\n", $definitions);
    }
    
    protected function generateSingleFieldDefinition(string $fieldName, string $fieldType, bool $nullable, ?array $pattern): string
    {
        // Handle based on pattern analysis first
        if ($pattern) {
            $fakerMethod = $this->generateFromPattern($fieldName, $pattern);
            if ($fakerMethod) {
                return $nullable ? "fake()->optional()->$fakerMethod" : "fake()->$fakerMethod";
            }
        }
        
        // Handle based on field name patterns
        $fakerMethod = $this->generateFromFieldName($fieldName);
        if ($fakerMethod) {
            return $nullable ? "fake()->optional()->$fakerMethod" : "fake()->$fakerMethod";
        }
        
        // Handle based on field type
        $fakerMethod = match($fieldType) {
            'integer' => 'numberBetween(1, 1000)',
            'decimal' => 'randomFloat(2, 0, 9999)',
            'boolean' => 'boolean()',
            'timestamp' => 'dateTimeBetween("-1 year", "now")',
            'json' => '[]',
            'text' => 'paragraph()',
            default => 'sentence()'
        };
        
        return $nullable ? "fake()->optional()->$fakerMethod" : "fake()->$fakerMethod";
    }
    
    protected function generateFromPattern(string $fieldName, array $pattern): ?string
    {
        switch ($pattern['pattern_type']) {
            case 'enum':
                $values = array_map(fn($v) => "'{$v}'", $pattern['common_values']);
                return 'randomElement([' . implode(', ', $values) . '])';
                
            case 'name':
                return str_contains($fieldName, 'first') ? 'firstName()' : 
                       (str_contains($fieldName, 'last') ? 'lastName()' : 'name()');
                
            case 'email':
                return 'unique()->safeEmail()';
                
            case 'phone':
                return 'phoneNumber()';
                
            case 'address':
                return 'address()';
                
            case 'company':
                return 'company()';
                
            default:
                return null;
        }
    }
    
    protected function generateFromFieldName(string $fieldName): ?string
    {
        $lower = strtolower($fieldName);
        
        return match(true) {
            str_contains($lower, 'email') => 'unique()->safeEmail()',
            str_contains($lower, 'phone') => 'phoneNumber()',
            str_contains($lower, 'name') => 'name()',
            str_contains($lower, 'first_name') => 'firstName()',
            str_contains($lower, 'last_name') => 'lastName()',
            str_contains($lower, 'address') => 'address()',
            str_contains($lower, 'city') => 'city()',
            str_contains($lower, 'state') => 'state()',
            str_contains($lower, 'country') => 'country()',
            str_contains($lower, 'zip') || str_contains($lower, 'postal') => 'postcode()',
            str_contains($lower, 'company') => 'company()',
            str_contains($lower, 'title') => 'jobTitle()',
            str_contains($lower, 'description') => 'paragraph()',
            str_contains($lower, 'url') || str_contains($lower, 'website') => 'url()',
            str_contains($lower, 'price') || str_contains($lower, 'amount') => 'randomFloat(2, 10, 1000)',
            str_contains($lower, 'quantity') || str_contains($lower, 'count') => 'numberBetween(1, 100)',
            str_contains($lower, 'age') => 'numberBetween(18, 80)',
            str_contains($lower, 'date') => 'date()',
            str_contains($lower, 'time') => 'time()',
            str_contains($lower, 'status') => 'randomElement(["active", "inactive", "pending"])',
            str_contains($lower, 'type') => 'randomElement(["type1", "type2", "type3"])',
            str_contains($lower, 'category') => 'word()',
            str_contains($lower, 'sku') || str_contains($lower, 'code') => 'unique()->bothify("??##??##")',
            default => null
        };
    }
    
    protected function generateFactoryStates(array $fields, array $dataPatterns): string
    {
        $states = [];
        
        // Generate common states based on field analysis
        foreach ($fields as $field) {
            $fieldName = $field['name'];
            
            if ($fieldName === 'status') {
                $pattern = $dataPatterns[$fieldName] ?? null;
                $statusValues = $pattern['common_values'] ?? ['active', 'inactive'];
                
                foreach ($statusValues as $status) {
                    $methodName = Str::camel($status);
                    $states[] = "    /**
     * Indicate that the model is {$status}.
     */
    public function {$methodName}(): static
    {
        return \$this->state(fn (array \$attributes) => [
            'status' => '{$status}',
        ]);
    }";
                }
            }
        }
        
        return empty($states) ? '' : "\n" . implode("\n\n", $states) . "\n";
    }
    
    protected function generateFactoryTraits(array $fields): string
    {
        $traits = [];
        
        // Check if we should add common traits
        $hasEmail = collect($fields)->pluck('name')->contains('email');
        $hasPassword = collect($fields)->pluck('name')->contains('password');
        
        if ($hasEmail) {
            $traits[] = "    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return \$this->state(fn (array \$attributes) => [
            'email_verified_at' => null,
        ]);
    }";
        }
        
        return empty($traits) ? '' : "\n" . implode("\n\n", $traits) . "\n";
    }
    
    // Pattern detection helpers
    protected function isNamePattern(array $values): bool
    {
        $namePattern = '/^[A-Z][a-z]+ [A-Z][a-z]+$/';
        $matches = 0;
        
        foreach (array_slice($values, 0, 5) as $value) {
            if (preg_match($namePattern, $value)) {
                $matches++;
            }
        }
        
        return $matches >= 3;
    }
    
    protected function isEmailPattern(array $values): bool
    {
        $emailCount = 0;
        
        foreach (array_slice($values, 0, 5) as $value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emailCount++;
            }
        }
        
        return $emailCount >= 3;
    }
    
    protected function isPhonePattern(array $values): bool
    {
        $phonePattern = '/[\(\)\-\s\d\+]{10,}/';
        $matches = 0;
        
        foreach (array_slice($values, 0, 5) as $value) {
            if (preg_match($phonePattern, $value)) {
                $matches++;
            }
        }
        
        return $matches >= 3;
    }
    
    protected function isAddressPattern(array $values): bool
    {
        $addressPattern = '/\d+\s+[\w\s]+/';
        $matches = 0;
        
        foreach (array_slice($values, 0, 5) as $value) {
            if (preg_match($addressPattern, $value)) {
                $matches++;
            }
        }
        
        return $matches >= 3;
    }
    
    protected function isCompanyPattern(array $values): bool
    {
        $companyIndicators = ['inc', 'llc', 'corp', 'ltd', 'company', 'co'];
        $matches = 0;
        
        foreach (array_slice($values, 0, 5) as $value) {
            $lower = strtolower($value);
            foreach ($companyIndicators as $indicator) {
                if (str_contains($lower, $indicator)) {
                    $matches++;
                    break;
                }
            }
        }
        
        return $matches >= 2;
    }
    
    protected function getFactoryPath(string $modelName): string
    {
        return database_path("factories/{$modelName}Factory.php");
    }
    
    protected function ensureDirectoryExists(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }
}