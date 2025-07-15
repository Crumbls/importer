<?php

namespace Crumbls\Importer\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class ModelResolver
{
    protected static array $cache = [];

    /**
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
    protected static function resolveModel(string $key): string
    {
        // Special case for users - pull from system config
        if ($key === 'user') {
            $modelClass = Config::get('auth.providers.users.model', User::class);
        } else {
            // For other models, check our package config
            $modelClass = Config::get("importer.models.{$key}");
            
            if (!$modelClass) {
                $availableModels = array_keys(Config::get('importer.models', []));
                $suggestion = $availableModels ? ' Available models: ' . implode(', ', $availableModels) : '';
                throw new InvalidArgumentException("Model '{$key}' not found in importer configuration.{$suggestion}");
            }
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