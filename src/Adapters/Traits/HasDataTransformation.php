<?php

namespace Crumbls\Importer\Adapters\Traits;

trait HasDataTransformation
{
    protected function cleanColumnName(string $name): string
    {
        // Remove content in parentheses and trim whitespace (preserve original behavior)
        $cleaned = preg_replace('/\s*\([^)]*\)\s*/', '', trim($name));
        
        // Convert to snake_case using Laravel Str helper if available
        if (class_exists('\Illuminate\Support\Str')) {
            return \Illuminate\Support\Str::snake($cleaned);
        }
        
        // Fallback implementation
        $cleaned = preg_replace('/[^a-zA-Z0-9\s]/', '', $cleaned);
        $cleaned = preg_replace('/\s+/', '_', $cleaned);
        $cleaned = strtolower($cleaned);
        $cleaned = preg_replace('/_+/', '_', $cleaned);
        
        return trim($cleaned, '_');
    }
    
    protected function mapColumnNames(array $data, array $mapping): array
    {
        $mapped = [];
        
        foreach ($data as $key => $value) {
            $mappedKey = $mapping[$key] ?? $key;
            $mapped[$mappedKey] = $value;
        }
        
        return $mapped;
    }
    
    protected function normalizeRowData(array $row, array $schema): array
    {
        $normalized = [];
        
        foreach ($schema as $field => $config) {
            $value = $row[$field] ?? ($config['default'] ?? null);
            $type = $config['type'] ?? 'string';
            
            $normalized[$field] = $this->convertDataType($value, $type);
        }
        
        return $normalized;
    }
    
    protected function convertDataType($value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => $this->convertToBoolean($value),
            'array' => $this->convertToArray($value),
            'json' => $this->convertToJson($value),
            'date' => $this->convertToDate($value),
            'datetime' => $this->convertToDateTime($value),
            'email' => $this->normalizeEmail($value),
            'url' => $this->normalizeUrl($value),
            'phone' => $this->normalizePhone($value),
            default => (string) $value
        };
    }
    
    protected function sanitizeFieldName(string $name): string
    {
        // More aggressive sanitization for database field names
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $sanitized = preg_replace('/^[0-9]/', '_$0', $sanitized); // Ensure it doesn't start with number
        
        return strtolower($sanitized);
    }
    
    protected function convertToBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (bool) $value;
        }
        
        $stringValue = strtolower(trim((string) $value));
        
        return in_array($stringValue, ['true', '1', 'yes', 'on', 'enabled', 'active']);
    }
    
    protected function convertToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            // Try JSON decode first
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (array) $decoded;
            }
            
            // Try comma-separated values
            return array_map('trim', explode(',', $value));
        }
        
        return [$value];
    }
    
    protected function convertToJson($value): ?string
    {
        if (is_string($value)) {
            // Validate JSON
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        
        return null;
    }
    
    protected function convertToDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        
        try {
            $date = new \DateTime($value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function convertToDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        
        try {
            $date = new \DateTime($value);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function normalizeEmail($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        
        $email = filter_var(trim($value), FILTER_VALIDATE_EMAIL);
        return $email !== false ? strtolower($email) : null;
    }
    
    protected function normalizeUrl($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        
        $url = trim($value);
        
        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        $validated = filter_var($url, FILTER_VALIDATE_URL);
        return $validated !== false ? $validated : null;
    }
    
    protected function normalizePhone($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $value);
        
        // Basic length validation (international format)
        if (strlen($phone) < 7 || strlen($phone) > 15) {
            return null;
        }
        
        return $phone;
    }
    
    protected function cleanArrayKeys(array $data): array
    {
        $cleaned = [];
        
        foreach ($data as $key => $value) {
            $cleanKey = $this->cleanColumnName($key);
            $cleaned[$cleanKey] = $value;
        }
        
        return $cleaned;
    }
    
    protected function removeEmptyValues(array $data, bool $strict = false): array
    {
        return array_filter($data, function ($value) use ($strict) {
            if ($strict) {
                return $value !== null && $value !== '';
            }
            
            return !empty($value) || $value === '0' || $value === 0;
        });
    }
}