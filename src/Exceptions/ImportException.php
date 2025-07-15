<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class ImportException extends Exception
{
    public static function driverNotFound(string $sourceType): self
    {
        return new self("No handler method found for source type: {$sourceType}");
    }

    public static function invalidSourceFormat(string $source): self
    {
        return new self("Invalid source format: {$source}");
    }

    public static function sourceNotFound(string $source): self
    {
        return new self("Source not found: {$source}");
    }

    public static function parserCreationFailed(string $message = null): self
    {
        return new self($message ?? 'Failed to create parser for import');
    }

    public static function extractionFailed(string $message = null): self
    {
        return new self($message ?? 'Data extraction failed');
    }

    public static function processingFailed(string $message): self
    {
        return new self("Import processing failed: {$message}");
    }
}