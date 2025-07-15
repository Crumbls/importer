<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class StorageException extends Exception
{
    public static function connectionFailed(string $message = null): self
    {
        return new self($message ?? 'Database connection failed');
    }

    public static function invalidTableName(string $tableName): self
    {
        return new self("Invalid table name: {$tableName}. Table names must start with a letter or underscore and contain only letters, numbers, and underscores.");
    }

    public static function invalidColumnName(string $columnName): self
    {
        return new self("Invalid column name: {$columnName}. Column names must start with a letter or underscore and contain only letters, numbers, and underscores.");
    }

    public static function tableNotFound(string $tableName): self
    {
        return new self("Table '{$tableName}' does not exist");
    }

    public static function storePathNotSet(): self
    {
        return new self('Storage path has not been set');
    }

    public static function fileCreationFailed(string $path): self
    {
        return new self("Failed to create storage file: {$path}");
    }
}