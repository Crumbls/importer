<?php

namespace Crumbls\Importer\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use Throwable;

class ModelScanner
{
    protected array $scanPaths;
    protected array $modelCache = [];
    
    public function __construct(array $scanPaths = [])
    {
        $this->scanPaths = $scanPaths ?: [
            app_path('Models'),
            app_path(),
        ];
    }
    
    /**
     * Find potential model matches for WordPress post types
     */
    public function findModelMatches(array $postTypes): array
    {
        $models = $this->discoverModels();
        $matches = [];
        
        foreach ($postTypes as $postType => $stats) {
            $matches[$postType] = $this->findBestMatches($postType, $models);
        }
        
        return $matches;
    }
    
    /**
     * Discover all available models in the application
     */
    public function discoverModels(): array
    {
        if (!empty($this->modelCache)) {
            return $this->modelCache;
        }
        
        $models = [];
        
        foreach ($this->scanPaths as $path) {
            if (!File::exists($path)) {
                continue;
            }
            
            $models = array_merge($models, $this->scanDirectory($path));
        }
        
        $this->modelCache = $models;
        return $models;
    }
    
    protected function scanDirectory(string $path, string $namespace = 'App'): array
    {
        $models = [];
        $files = File::allFiles($path);
        
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            
            $relativePath = str_replace($path . '/', '', $file->getPathname());
            $className = $this->getClassNameFromFile($relativePath, $namespace);
            
            if ($this->isEloquentModel($className)) {
                $models[] = [
                    'class' => $className,
                    'name' => class_basename($className),
                    'file' => $file->getPathname(),
                    'table' => $this->getTableName($className),
                    'fillable' => $this->getFillableFields($className),
                ];
            }
        }
        
        return $models;
    }
    
    protected function getClassNameFromFile(string $relativePath, string $baseNamespace): string
    {
        $className = str_replace(['/', '.php'], ['\\', ''], $relativePath);
        return $baseNamespace . '\\' . $className;
    }
    
    protected function isEloquentModel(string $className): bool
    {
        try {
            if (!class_exists($className)) {
                return false;
            }
            
            $reflection = new ReflectionClass($className);
            
            // Check if it extends Eloquent Model
            return $reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class);
            
        } catch (ReflectionException $e) {
            return false;
        }
    }

	protected function isLaravelModel(string $className): bool
	{
		return once(function() use ($className) {
			try {
				if (!class_exists($className) || !is_subclass_of($className, Model::class)) {
					return false;
				}
				$reflection = new ReflectionClass($className);
				if ($reflection->isAbstract()) {
					return false;
				}
				$instance = new $className();
				return $instance->getTable();
			} catch (Throwable $e) {
			}
			return false;
		});

	}
    
    protected function getTableName(string $className): ?string
    {
	    if (!$this->isLaravelModel($className)) {
		    return null;
	    }
		return once(function() use ($className) {
			return with(new $className())->getTable();
		});
    }
    
    protected function getFillableFields(string $className): array
    {
		if (!$this->isLaravelModel($className)) {
			return [];
		}
		return once(function() use ($className) {
			return with (new $className())->getFillable();
		});
    }
    
    protected function findBestMatches(string $postType, array $models): array
    {
        $matches = [];
        
        foreach ($models as $model) {
            $score = $this->calculateMatchScore($postType, $model);
            
            if ($score > 0) {
                $matches[] = [
                    'model' => $model,
                    'score' => $score,
                    'confidence' => $this->getConfidenceLevel($score),
                    'reasons' => $this->getMatchReasons($postType, $model, $score),
                ];
            }
        }
        
        // Sort by score (highest first)
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return $matches;
    }
    
    protected function calculateMatchScore(string $postType, array $model): int
    {
        $score = 0;
        $modelName = strtolower($model['name']);
        $tableName = strtolower($model['table'] ?? '');
        $postType = strtolower($postType);
        
        // Exact matches
        if ($modelName === $postType) {
            $score += 100;
        } elseif ($tableName === $postType) {
            $score += 90;
        } elseif ($tableName === Str::plural($postType)) {
            $score += 85;
        } elseif ($modelName === Str::singular($postType)) {
            $score += 80;
        }
        
        // Partial matches
        if (str_contains($modelName, $postType)) {
            $score += 50;
        } elseif (str_contains($postType, $modelName)) {
            $score += 45;
        }
        
        // Plural/singular variations
        if ($modelName === Str::plural($postType)) {
            $score += 70;
        } elseif (Str::plural($modelName) === $postType) {
            $score += 65;
        }
        
        // Common WordPress post type patterns
        $wpPatterns = [
            'post' => ['article', 'blog', 'news', 'content'],
            'page' => ['static', 'content'],
            'attachment' => ['media', 'file', 'upload'],
            'product' => ['item', 'goods'],
            'event' => ['calendar', 'schedule'],
        ];
        
        foreach ($wpPatterns as $wpType => $synonyms) {
            if ($postType === $wpType) {
                foreach ($synonyms as $synonym) {
                    if (str_contains($modelName, $synonym)) {
                        $score += 30;
                        break;
                    }
                }
            }
        }
        
        return $score;
    }
    
    protected function getConfidenceLevel(int $score): string
    {
        if ($score >= 80) return 'high';
        if ($score >= 50) return 'medium';
        if ($score >= 20) return 'low';
        return 'very_low';
    }
    
    protected function getMatchReasons(string $postType, array $model, int $score): array
    {
        $reasons = [];
        $modelName = strtolower($model['name']);
        $tableName = strtolower($model['table'] ?? '');
        $postType = strtolower($postType);
        
        if ($modelName === $postType) {
            $reasons[] = 'Exact model name match';
        } elseif ($tableName === $postType) {
            $reasons[] = 'Exact table name match';
        } elseif ($tableName === Str::plural($postType)) {
            $reasons[] = 'Table name matches plural form';
        } elseif ($modelName === Str::singular($postType)) {
            $reasons[] = 'Model name matches singular form';
        }
        
        if (str_contains($modelName, $postType)) {
            $reasons[] = 'Model name contains post type';
        } elseif (str_contains($postType, $modelName)) {
            $reasons[] = 'Post type contains model name';
        }
        
        if ($score < 20) {
            $reasons[] = 'Low confidence match';
        }
        
        return $reasons;
    }
    
    /**
     * Get suggested model names for unmapped post types
     */
    public function suggestModelNames(array $unmappedPostTypes): array
    {
        $suggestions = [];
        
        foreach ($unmappedPostTypes as $postType => $stats) {
            $suggestions[$postType] = [
                'model_name' => Str::studly(Str::singular($postType)),
                'table_name' => Str::snake(Str::plural($postType)),
                'file_name' => Str::studly(Str::singular($postType)) . '.php',
                'namespace' => 'App\\Models',
                'full_class' => 'App\\Models\\' . Str::studly(Str::singular($postType)),
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Analyze field compatibility between WordPress data and model fillable fields
     */
    public function analyzeFieldCompatibility(array $wpFields, array $modelFillable): array
    {
        $compatible = [];
        $incompatible = [];
        $missing = [];
        
        foreach ($wpFields as $wpField) {
            if (in_array($wpField, $modelFillable)) {
                $compatible[] = $wpField;
            } else {
                // Check for similar field names
                $similar = $this->findSimilarFields($wpField, $modelFillable);
                if (!empty($similar)) {
                    $incompatible[] = [
                        'wp_field' => $wpField,
                        'similar_fields' => $similar,
                    ];
                } else {
                    $missing[] = $wpField;
                }
            }
        }
        
        return [
            'compatible' => $compatible,
            'incompatible' => $incompatible,
            'missing' => $missing,
            'compatibility_score' => count($compatible) / max(1, count($wpFields)),
        ];
    }
    
    protected function findSimilarFields(string $wpField, array $modelFields): array
    {
        $similar = [];
        
        foreach ($modelFields as $modelField) {
            // Check for similar names
            if (similar_text($wpField, $modelField, $percent) && $percent > 70) {
                $similar[] = [
                    'field' => $modelField,
                    'similarity' => $percent,
                ];
            }
        }
        
        // Sort by similarity
        usort($similar, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        
        return $similar;
    }
}