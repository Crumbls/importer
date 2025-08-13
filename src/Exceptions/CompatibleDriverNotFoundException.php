<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class CompatibleDriverNotFoundException extends Exception
{
    public static function forSource(string $sourceType, string $sourceDetail, array $availableDrivers = []): self
    {
        $message = "No compatible import driver found for source type '{$sourceType}'";
        
        if ($sourceDetail) {
            $message .= " with source '{$sourceDetail}'";
        }
        
        $message .= ".";
        
        if (!empty($availableDrivers)) {
            $driverList = implode(', ', array_map(fn($driver) => class_basename($driver), $availableDrivers));
            $message .= " Available drivers: {$driverList}.";
        }
        
        $message .= " Consider:\n";
        $message .= "• Verifying the source format is supported\n";
        $message .= "• Checking if source data is accessible/readable\n";
        $message .= "• Adding a custom driver if needed\n";
        $message .= "• Reviewing driver priority order";
        
        return new static($message);
    }
    
    public static function noDriversRegistered(): self
    {
        return new static(
            "No import drivers are registered. " .
            "Ensure drivers are properly configured in your ImporterServiceProvider."
        );
    }
    
    public static function driverTestFailed(string $driverClass, string $reason): self
    {
        return new static(
            "Driver '{$driverClass}' failed compatibility test: {$reason}. " .
            "Check driver requirements and source format compatibility."
        );
    }
}