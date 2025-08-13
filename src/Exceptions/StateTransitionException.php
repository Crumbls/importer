<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class StateTransitionException extends Exception
{
    public static function noTransitionFound(string $currentState): self
    {
        return new self("No preferred transition found from state: " . class_basename($currentState));
    }

    public static function invalidStorageDriver(string $state = null): self
    {
        $message = 'Storage driver is not valid';
        if ($state) {
            $message .= " in state: " . class_basename($state);
        }
        return new self($message);
    }

    public static function contextNotAvailable(array $context = []): self
    {
        return new self('Import contract not available in state context. Context: ' . json_encode($context));
    }

    public static function storageNotConfigured(int $importId): self
    {
        return new self("No storage driver configured for import ID: {$importId}");
    }

    public static function connectionNotFound(string $connectionType = 'database'): self
    {
        return new self(ucfirst($connectionType) . " connection not found in metadata");
    }

    public static function configurationRequired(string $state): self
    {
        return new self("Configuration is required for " . class_basename($state));
    }
}