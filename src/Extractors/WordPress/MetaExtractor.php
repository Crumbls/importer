<?php

namespace Crumbls\Importer\Extractors\WordPress;

use Crumbls\Importer\Extractors\AbstractDataExtractor;
use DOMXPath;

class MetaExtractor extends AbstractDataExtractor
{
    public function getName(): string
    {
        return 'MetaExtractor';
    }
    
    protected function performExtraction(DOMXPath $xpath, array $context = []): array
    {
        $postId = $context['post_id'] ?? null;
        
        if (!$postId) {
            return []; // Cannot extract meta without a post ID
        }
        
        $metaData = [];
        $metaNodes = $xpath->query('.//wp:postmeta');
        
        foreach ($metaNodes as $metaNode) {
            $metaXpath = new DOMXPath($metaNode->ownerDocument);
            $metaXpath->setContextNode($metaNode);
            
            $metaKey = $this->getXPathValue($metaXpath, './/wp:meta_key');
            $metaValue = $this->getXPathValue($metaXpath, './/wp:meta_value');
            
            if (!empty($metaKey)) {
                $metaData[] = $this->addTimestamps([
                    'post_id' => $postId,
                    'meta_key' => $this->sanitizeString($metaKey, 255),
                    'meta_value' => $this->sanitizeMetaValue($metaValue),
                    'processed' => false,
                ]);
            }
        }
        
        return $metaData;
    }
    
    protected function sanitizeMetaValue(?string $value): string
    {
        if (empty($value)) {
            return '';
        }
        
        // Handle serialized data
        if ($this->isSerialized($value)) {
            return $value; // Keep serialized data as-is
        }
        
        // Handle JSON data
        if ($this->isJson($value)) {
            return $value; // Keep JSON data as-is
        }
        
        // Handle URLs
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $this->sanitizeUrl($value);
        }
        
        // Handle email addresses
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->sanitizeEmail($value);
        }
        
        // Handle numeric values
        if (is_numeric($value)) {
            return $value;
        }
        
        // Default string sanitization
        return $this->sanitizeString($value);
    }
    
    protected function isSerialized(string $value): bool
    {
        // WordPress serialized data patterns
        return preg_match('/^(a:\d+:\{|s:\d+:|i:\d+;|d:\d+\.\d+;|b:[01];|N;)/', $value) === 1;
    }
    
    protected function isJson(string $value): bool
    {
        if (!is_string($value) || strlen($value) < 2) {
            return false;
        }
        
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    public function validate(array $data): bool
    {
        // Meta data must have post_id and meta_key
        if (empty($data)) {
            return false;
        }
        
        // For arrays of meta items
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $metaItem) {
                if (!isset($metaItem['post_id']) || !isset($metaItem['meta_key']) || 
                    empty($metaItem['meta_key'])) {
                    return false;
                }
            }
            return true;
        }
        
        // For single meta item
        return isset($data['post_id']) && isset($data['meta_key']) && !empty($data['meta_key']);
    }
}