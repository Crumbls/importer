<?php

namespace Crumbls\Importer\Services;

use Illuminate\Support\Str;

class FactoryBuilder
{
    public function generateFactoryCode(array $modelData): string
    {
        $modelClass = $modelData['model_class'];
        $modelName = class_basename($modelClass);
        $factoryName = $modelName . 'Factory';
        
        $factoryFields = $this->generateFactoryFields($modelData['columns'], $modelData['fillable']);
        
        return $this->getFactoryTemplate($factoryName, $modelClass, $factoryFields);
    }
    
    protected function generateFactoryFields(array $columns, array $fillable): string
    {
        $fields = [];
        
        foreach ($columns as $column) {
            $columnName = $column['name'];
            
            // Skip non-fillable fields and special fields
            if (!in_array($columnName, $fillable) || 
                in_array($columnName, ['id', 'created_at', 'updated_at'])) {
                continue;
            }
            
            $fakerMethod = $this->getFakerMethodForColumn($column);
            if ($fakerMethod) {
                $fields[] = "            '{$columnName}' => {$fakerMethod},";
            }
        }
        
        return implode("\n", $fields);
    }
    
    protected function getFakerMethodForColumn(array $column): ?string
    {
        $name = $column['name'];
        $type = $column['type'];
        
        // Handle specific field names first
        $fieldMappings = [
            'title' => 'fake()->sentence()',
            'name' => 'fake()->words(3, true)',
            'content' => 'fake()->paragraphs(3, true)',
            'excerpt' => 'fake()->paragraph()',
            'description' => 'fake()->paragraph()',
            'slug' => 'fake()->slug()',
            'email' => 'fake()->safeEmail()',
            'phone' => 'fake()->phoneNumber()',
            'address' => 'fake()->address()',
            'city' => 'fake()->city()',
            'state' => 'fake()->state()',
            'country' => 'fake()->country()',
            'zip' => 'fake()->postcode()',
            'url' => 'fake()->url()',
            'image' => 'fake()->imageUrl()',
            'price' => 'fake()->randomFloat(2, 10, 1000)',
            'quantity' => 'fake()->numberBetween(1, 100)',
            'weight' => 'fake()->randomFloat(2, 0.1, 10)',
            'status' => "fake()->randomElement(['active', 'inactive', 'pending'])",
            'published_at' => 'fake()->optional()->dateTimeBetween("-1 year", "now")',
            'first_name' => 'fake()->firstName()',
            'last_name' => 'fake()->lastName()',
        ];
        
        if (isset($fieldMappings[$name])) {
            return $fieldMappings[$name];
        }
        
        // Handle by data type
        switch ($type) {
            case 'string':
                if (str_contains($name, 'email')) {
                    return 'fake()->safeEmail()';
                } elseif (str_contains($name, 'phone')) {
                    return 'fake()->phoneNumber()';
                } elseif (str_contains($name, 'url') || str_contains($name, 'link')) {
                    return 'fake()->url()';
                } elseif (str_contains($name, 'color')) {
                    return 'fake()->hexColor()';
                } elseif ($column['length'] ?? 0 <= 50) {
                    return 'fake()->words(3, true)';
                } else {
                    return 'fake()->sentence()';
                }
                
            case 'text':
            case 'longText':
                return 'fake()->paragraphs(2, true)';
                
            case 'integer':
                if (str_contains($name, '_id') || str_contains($name, 'parent')) {
                    return 'null'; // Will be set by relationships
                } elseif (str_contains($name, 'count') || str_contains($name, 'number')) {
                    return 'fake()->numberBetween(0, 100)';
                } else {
                    return 'fake()->numberBetween(1, 1000)';
                }
                
            case 'decimal':
                if (str_contains($name, 'price') || str_contains($name, 'cost')) {
                    return 'fake()->randomFloat(2, 10, 1000)';
                } elseif (str_contains($name, 'rate') || str_contains($name, 'percentage')) {
                    return 'fake()->randomFloat(2, 0, 100)';
                } else {
                    return 'fake()->randomFloat(2, 0, 999)';
                }
                
            case 'boolean':
                return 'fake()->boolean()';
                
            case 'datetime':
            case 'timestamp':
                if (str_contains($name, 'published')) {
                    return 'fake()->optional(0.8)->dateTimeBetween("-1 year", "now")';
                } else {
                    return 'fake()->dateTimeBetween("-1 year", "now")';
                }
                
            case 'json':
                return 'fake()->randomElements(["tag1", "tag2", "tag3"], 2)';
                
            case 'enum':
                // You'd need to parse the enum values from the column definition
                return "fake()->randomElement(['option1', 'option2', 'option3'])";
                
            default:
                return 'fake()->word()';
        }
    }
    
    protected function getFactoryTemplate(string $factoryName, string $modelClass, string $factoryFields): string
    {
        return "<?php

namespace Database\\Factories;

use {$modelClass};
use Illuminate\\Database\\Eloquent\\Factories\\Factory;

/**
 * @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\{$modelClass}>
 */
class {$factoryName} extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\\Illuminate\\Database\\Eloquent\\Model>
     */
    protected \$model = {$modelClass}::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
{$factoryFields}
        ];
    }

    /**
     * Indicate that the model should be published.
     */
    public function published(): static
    {
        return \$this->state(fn (array \$attributes) => [
            'status' => 'published',
            'published_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    /**
     * Indicate that the model should be draft.
     */
    public function draft(): static
    {
        return \$this->state(fn (array \$attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }
}
";
    }
    
    public function generateSeederCode(string $modelClass, int $count = 50): string
    {
        $modelName = class_basename($modelClass);
        $seederName = $modelName . 'Seeder';
        
        return "<?php

namespace Database\\Seeders;

use {$modelClass};
use Illuminate\\Database\\Seeder;

class {$seederName} extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        {$modelName}::factory()
            ->count({$count})
            ->create();

        // Create some published items
        {$modelName}::factory()
            ->published()
            ->count(" . intval($count * 0.7) . ")
            ->create();

        // Create some draft items
        {$modelName}::factory()
            ->draft()
            ->count(" . intval($count * 0.3) . ")
            ->create();
    }
}
";
    }
}