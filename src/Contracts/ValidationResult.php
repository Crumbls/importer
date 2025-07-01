<?php

namespace Crumbls\Importer\Contracts;

class ValidationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $suggestions = []
    ) {}
    
    public function isValid(): bool
    {
        return $this->isValid;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
    
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
    
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'suggestions' => $this->suggestions
        ];
    }
}