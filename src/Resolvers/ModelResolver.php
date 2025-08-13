<?php

namespace Crumbls\Importer\Resolvers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Model resolver with dynamic method support
 * 
 * @method static class-string<Model> import()
 * @method static class-string<Model> importModelMap()
 * @method static class-string<Model> user()
 * @method static Model instance(string $key)
 */
class ModelResolver
{
    /** @var array<string, class-string<Model>> */
    protected static array $cache = [];

    /**
     * @param string $name
     * @param array<mixed> $arguments
     * @return class-string<Model>
     */
    public static function __callStatic(string $name, array $arguments): string
    {
        $key = strtolower($name);
        
        if (isset(static::$cache[$key])) {
            return static::$cache[$key];
        }

        $modelClass = static::resolveModel($key);
        
        static::$cache[$key] = $modelClass;
        
        return $modelClass;
    }

    /**
     * @return class-string<Model>
     */
    public static function user(): string
    {
        return once(function() {
            /** @var class-string<Model>|null $modelClass */
            $modelClass = Config::get('auth.providers.users.model') ?? User::class;

            if (!class_exists($modelClass)) {
                throw new InvalidArgumentException("Model class '{$modelClass}' does not exist.");
            }

            return $modelClass;
        });
    }

	public static function all() : array {
		return (array) Config::get('importer.models', []);
	}

    /**
     * @return class-string<Model>
     */
    protected static function resolveModel(string $key): string
    {
		$key = strtolower($key);
	        /** @var class-string<Model>|null $modelClass */
	        $modelClass = Config::get("importer.models.{$key}");

	        if ($modelClass === null) {
	            /** @var array<string, class-string<Model>> $models */
	            $models = Config::get('importer.models', []);
	            $availableModels = array_keys($models);

	            if (!empty($availableModels)) {
	                throw new InvalidArgumentException("Model '{$key}' not found in importer configuration. Available models: " . implode(', ', $availableModels));
	            }
	            throw new InvalidArgumentException("Model '{$key}' not found in importer configuration.");
	        }

	        // Validate that the class exists
	        if (!class_exists($modelClass)) {
	            throw new InvalidArgumentException("Model class '{$modelClass}' does not exist.");
	        }

	        return $modelClass;

    }
    
    public static function clearCache(): void
    {
        static::$cache = [];
    }

    /**
     * @return array<string, class-string<Model>>
     */
    public static function getCache(): array
    {
        return static::$cache;
    }

    /**
     * Get a new instance of the resolved model
     */
    public static function instance(string $key): Model
    {
        $modelClass = static::resolveModel($key);
        return new $modelClass();
    }
}