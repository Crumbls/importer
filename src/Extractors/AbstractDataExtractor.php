<?php

namespace Crumbls\Importer\Extractors;

use Crumbls\Importer\Extractors\Contracts\DataExtractorContract;
use DOMXPath;
use Illuminate\Support\Facades\Log;

abstract class AbstractDataExtractor implements DataExtractorContract
{
    protected array $config;
    protected array $extractionStats = [];
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->extractionStats = [
            'items_extracted' => 0,
            'items_failed' => 0,
            'validation_failures' => 0,
        ];
    }
    
    public function extract(DOMXPath $xpath, array $context = []): array
    {
        try {
            $data = $this->performExtraction($xpath, $context);
            
            if ($this->validate($data)) {
                $this->extractionStats['items_extracted']++;
                return $data;
            } else {
                $this->extractionStats['validation_failures']++;
                Log::warning($this->getName() . ' validation failed', ['data' => $data]);
                return [];
            }
            
        } catch (\Exception $e) {
            $this->extractionStats['items_failed']++;
            Log::error($this->getName() . ' extraction failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Perform the actual extraction logic (implemented by child classes)
     */
    abstract protected function performExtraction(DOMXPath $xpath, array $context = []): array;
    
    public function validate(array $data): bool
    {
        // Basic validation - can be overridden by child classes
        return !empty($data);
    }
    
    public function getExtractionStats(): array
    {
        return $this->extractionStats;
    }
    
    // Common utility methods for all extractors
    
    protected function getXPathValue(DOMXPath $xpath, string $query): string
    {
        $result = $xpath->evaluate("string({$query})");
        return $result ?: '';
    }
    
    protected function sanitizeString(?string $value, int $maxLength = null): string
    {
        if (empty($value)) {
            return '';
        }
        
        // Strip potentially dangerous HTML tags but keep basic formatting
        $value = strip_tags($value, '<p><br><strong><em><ul><ol><li><a><blockquote><h1><h2><h3><h4><h5><h6>');
        
        // Trim whitespace
        $value = trim($value);
        
        // Limit length if specified
        if ($maxLength && strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        
        return $value;
    }
    
    protected function sanitizeEmail(?string $email): string
    {
        if (empty($email)) {
            return '';
        }
        
        // Basic email validation and sanitization
        $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        
        return $email;
    }
    
    protected function sanitizeUrl(?string $url): string
    {
        if (empty($url)) {
            return '';
        }
        
        // Basic URL validation and sanitization
        $url = filter_var(trim($url), FILTER_SANITIZE_URL);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        
        return $url;
    }
    
    protected function sanitizeInteger($value): int
    {
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }
    
    protected function convertWordPressDate(string $date): ?string
    {
        if (empty($date) || $date === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date)->toDateTimeString();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function generateSecureId(string ...$components): int
    {
        // Use SHA-256 for better collision resistance than CRC32
        $hash = hash('sha256', implode('|', $components));
        
        // Convert first 8 characters to integer (much lower collision rate than CRC32)
        return abs(hexdec(substr($hash, 0, 8)));
    }
    
    protected function addTimestamps(array $data): array
    {
        $timestamp = date('Y-m-d H:i:s');
        return array_merge($data, [
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}