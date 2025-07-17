<?php

namespace Crumbls\Importer\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;

class ModelGenerator
{
    protected string $modelStub;
    protected string $migrationStub;
    protected string $factoryStub;
    
    public function __construct()
    {
        $this->loadStubs();
    }
    
    protected function loadStubs(): void
    {
        $this->modelStub = $this->getModelStub();
        $this->migrationStub = $this->getMigrationStub();
        $this->factoryStub = $this->getFactoryStub();
    }
    
    public function createModel(array $config): array
    {
        $results = [
            'model' => null,
            'migration' => null,
            'factory' => null,
            'seeder' => null,
            'errors' => [],
        ];
        
        try {
            // Create model file
            $results['model'] = $this->generateModelFile($config);
            
            // Create migration if requested
            if ($config['create_migration'] ?? false) {
                $results['migration'] = $this->generateMigration($config);
            }
            
            // Create factory if requested
            if ($config['create_factory'] ?? false) {
                $results['factory'] = $this->generateFactory($config);
            }
            
            // Create seeder if requested
            if ($config['create_seeder'] ?? false) {
                $results['seeder'] = $this->generateSeeder($config);
            }
            
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }
    
    protected function generateModelFile(array $config): array
    {
        $modelName = $config['name'];
        $namespace = $config['namespace'] ?? 'App\\Models';
        $tableName = $config['table'] ?? Str::snake(Str::plural($modelName));
        $fillable = $config['fillable'] ?? [];
        
        $modelPath = $this->getModelPath($namespace, $modelName);
        
        if (File::exists($modelPath)) {
            throw new \Exception("Model {$modelName} already exists at {$modelPath}");
        }
        
        $content = str_replace([
            '{{namespace}}',
            '{{className}}',
            '{{tableName}}',
            '{{fillable}}',
        ], [
            $namespace,
            $modelName,
            $tableName,
            $this->formatFillableArray($fillable),
        ], $this->modelStub);
        
        $this->ensureDirectoryExists(dirname($modelPath));
        File::put($modelPath, $content);
        
        return [
            'path' => $modelPath,
            'class' => $namespace . '\\' . $modelName,
            'created' => true,
        ];
    }
    
    protected function generateMigration(array $config): array
    {
        $tableName = $config['table'];
        $migrationName = 'create_' . $tableName . '_table';
        
        // Use Artisan to create migration
        Artisan::call('make:migration', [
            'name' => $migrationName,
            '--create' => $tableName,
        ]);
        
        $migrationPath = $this->findLatestMigration($migrationName);
        
        return [
            'path' => $migrationPath,
            'name' => $migrationName,
            'created' => true,
        ];
    }
    
    protected function generateFactory(array $config): array
    {
        $modelName = $config['name'];
        
        Artisan::call('make:factory', [
            'name' => $modelName . 'Factory',
            '--model' => $modelName,
        ]);
        
        $factoryPath = database_path('factories/' . $modelName . 'Factory.php');
        
        return [
            'path' => $factoryPath,
            'name' => $modelName . 'Factory',
            'created' => File::exists($factoryPath),
        ];
    }
    
    protected function generateSeeder(array $config): array
    {
        $modelName = $config['name'];
        $seederName = $modelName . 'Seeder';
        
        Artisan::call('make:seeder', [
            'name' => $seederName,
        ]);
        
        $seederPath = database_path('seeders/' . $seederName . '.php');
        
        return [
            'path' => $seederPath,
            'name' => $seederName,
            'created' => File::exists($seederPath),
        ];
    }
    
    protected function getModelPath(string $namespace, string $modelName): string
    {
        $namespacePath = str_replace(['App\\', '\\'], ['', '/'], $namespace);
        return app_path($namespacePath . '/' . $modelName . '.php');
    }
    
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }
    
    protected function formatFillableArray(array $fillable): string
    {
        if (empty($fillable)) {
            return '[]';
        }
        
        $formatted = array_map(fn($field) => "'{$field}'", $fillable);
        return "[\n        " . implode(",\n        ", $formatted) . ",\n    ]";
    }
    
    protected function findLatestMigration(string $migrationName): ?string
    {
        $migrationPath = database_path('migrations');
        $files = File::files($migrationPath);
        
        foreach ($files as $file) {
            if (str_contains($file->getFilename(), $migrationName)) {
                return $file->getPathname();
            }
        }
        
        return null;
    }
    
    protected function getModelStub(): string
    {
        return '<?php

namespace {{namespace}};

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {{className}} extends Model
{
    use HasFactory;

    protected $table = \'{{tableName}}\';

    protected $fillable = {{fillable}};

    protected $casts = [
        \'created_at\' => \'datetime\',
        \'updated_at\' => \'datetime\',
    ];
}
';
    }
    
    protected function getMigrationStub(): string
    {
        return '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(\'{{tableName}}\', function (Blueprint $table) {
            $table->id();
            {{columns}}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(\'{{tableName}}\');
    }
};
';
    }
    
    protected function getFactoryStub(): string
    {
        return '<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class {{className}}Factory extends Factory
{
    public function definition(): array
    {
        return [
            {{factoryFields}}
        ];
    }
}
';
    }
}