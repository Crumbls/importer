<?php

namespace Crumbls\Importer\Exceptions;

use Exception;

class TuiException extends Exception
{
    public static function promptClassNotFound(string $promptClass): self
    {
        return new self("Prompt class does not exist: {$promptClass}");
    }

    public static function promptClassInvalidInterface(string $promptClass, string $requiredInterface): self
    {
        return new self("Prompt class '{$promptClass}' must implement {$requiredInterface}");
    }

    public static function pageClassNotFound(string $pageClass): self
    {
        return new self("Page class does not exist: {$pageClass}");
    }

    public static function pageCreationFailed(string $pageClass, string $reason): self
    {
        return new self("Failed to create page instance for '{$pageClass}': {$reason}");
    }

    public static function pageMountFailed(string $pageClass, string $reason): self
    {
        return new self("Failed to mount page '{$pageClass}': {$reason}");
    }

    public static function requiredPropertyMissing(string $property, string $context = null): self
    {
        $message = "Required property '{$property}' must be provided";
        if ($context) {
            $message .= " via {$context}";
        }
        return new self($message);
    }

    public static function ttyRequired(): self
    {
        return new self('TUI requires a proper TTY terminal. Please run this command directly in your terminal, not through an IDE or other wrapper.');
    }

    public static function recordNotFound(string $recordType = 'record'): self
    {
        return new self(ucfirst($recordType) . " not found");
    }
}