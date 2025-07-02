<?php

namespace Crumbls\Importer\Support;

class ConfigurationValidator
{
    protected array $rules = [];
    protected array $errors = [];
    
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }
    
    public function validate(array $config): array
    {
        $this->errors = [];
        
        foreach ($this->rules as $field => $fieldRules) {
            $this->validateField($field, $config[$field] ?? null, $fieldRules);
        }
        
        return $this->errors;
    }
    
    public function isValid(array $config): bool
    {
        return empty($this->validate($config));
    }
    
    protected function validateField(string $field, $value, array $rules): void
    {
        foreach ($rules as $rule => $parameter) {
            if (!$this->applyRule($field, $value, $rule, $parameter)) {
                $this->errors[$field][] = $this->getErrorMessage($field, $rule, $parameter);
            }
        }
    }
    
    protected function applyRule(string $field, $value, string $rule, $parameter): bool
    {
        return match ($rule) {
            'required' => $value !== null && $value !== '',
            'type' => gettype($value) === $parameter || ($value === null && !in_array('required', array_keys($this->rules[$field] ?? []))),
            'min' => is_numeric($value) && $value >= $parameter,
            'max' => is_numeric($value) && $value <= $parameter,
            'in' => in_array($value, $parameter),
            'regex' => is_string($value) && preg_match($parameter, $value),
            'callable' => is_callable($parameter) && $parameter($value),
            default => true
        };
    }
    
    protected function getErrorMessage(string $field, string $rule, $parameter): string
    {
        return match ($rule) {
            'required' => "Field '{$field}' is required",
            'type' => "Field '{$field}' must be of type {$parameter}",
            'min' => "Field '{$field}' must be at least {$parameter}",
            'max' => "Field '{$field}' must not exceed {$parameter}",
            'in' => "Field '{$field}' must be one of: " . implode(', ', $parameter),
            'regex' => "Field '{$field}' format is invalid",
            'callable' => "Field '{$field}' validation failed",
            default => "Field '{$field}' is invalid"
        };
    }
}