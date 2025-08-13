<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class ParsingException extends Exception
{
    public static function fileNotReadable(string $filePath): self
    {
        return new self("Unable to open file for reading: {$filePath}");
    }

    public static function unsupportedSourceType(string $sourceType): self
    {
        return new self("Unsupported source type: {$sourceType}");
    }

    public static function synchronousExtractionNotRecommended(string $reason = null): self
    {
        $message = 'Synchronous extraction not recommended for large files. Please ensure your queue workers are running.';
        if ($reason) {
            $message .= " Reason: {$reason}";
        }
        return new self($message);
    }

    public static function memoryLimitExceeded(string $current, string $limit): self
    {
        return new self("Memory usage critical: {$current} / {$limit}");
    }

    public static function invalidBatchSize(int $size): self
    {
        return new self("Batch size must be at least 1, got: {$size}");
    }

    public static function invalidBatchRange(int $min, int $max): self
    {
        return new self("Min batch size ({$min}) cannot be greater than max batch size ({$max})");
    }

    public static function invalidMemoryThreshold(float $threshold): self
    {
        return new self("Memory threshold must be between 0 and 1, got: {$threshold}");
    }

    public static function invalidUpdateInterval(int $interval): self
    {
        return new self("Min update interval cannot be negative, got: {$interval}");
    }

    public static function invalidDuplicateHandling(string $method): self
    {
        return new self("Invalid duplicate handling method: {$method}");
    }

    public static function tableStructureNotFound(string $table = null): self
    {
        $message = 'Table structure not found';
        if ($table) {
            $message .= " for table: {$table}";
        }
        return new self($message);
    }

    public static function idColumnNotDetermined(array $availableColumns): self
    {
        return new self('Unable to determine posts table ID column. Available columns: ' . implode(', ', $availableColumns));
    }
}