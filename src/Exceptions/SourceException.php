<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class SourceException extends Exception
{
    public static function sourceDetailRequired(): self
    {
        return new self('Source detail is required for import processing');
    }

    public static function unsupportedSourceType(string $sourceType): self
    {
        return new self("Unsupported source type: {$sourceType}");
    }

    public static function fileNotFound(string $sourceType, string $sourceDetail): self
    {
        return new self("File not found on disk for '{$sourceType}': {$sourceDetail}");
    }

    public static function databaseConnectionFailed(): self
    {
        return new self('DatabaseSourceResolver not yet implemented');
    }

    public static function modelAlreadyExists(string $modelName, string $modelPath): self
    {
        return new self("Model '{$modelName}' already exists at: {$modelPath}");
    }

    public static function factoryAlreadyExists(string $filePath): self
    {
        return new self("Factory file already exists: {$filePath}");
    }

    public static function invalidState(string $state): self
    {
        return new self("Invalid application state: {$state}");
    }
}