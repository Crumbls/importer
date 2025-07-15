<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class ConfigurationException extends Exception
{
    public static function invalidConfiguration(string $key, string $message = null): self
    {
        $defaultMessage = "Invalid configuration for '{$key}'";
        return new self($message ? "{$defaultMessage}: {$message}" : $defaultMessage);
    }

    public static function missingConfiguration(string $key): self
    {
        return new self("Missing required configuration: {$key}");
    }

    public static function invalidDriver(string $driver): self
    {
        return new self("Invalid driver configuration: {$driver}");
    }
}