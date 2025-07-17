<?php

namespace Crumbls\Importer\Extractors\WordPress;

use Crumbls\Importer\Extractors\AbstractDataExtractor;
use DOMXPath;

class PostExtractor extends AbstractDataExtractor
{
    public function getName(): string
    {
        return 'PostExtractor';
    }
    
    protected function performExtraction(DOMXPath $xpath, array $context = []): array
    {
        $postId = $this->extractPostId($xpath);
        
        if (!$postId) {
            return []; // Cannot extract without a post ID
        }
        
        return $this->addTimestamps([
            'post_id' => $postId,
            'post_title' => $this->sanitizeString($this->getXPathValue($xpath, './/title'), 255),
            'post_content' => $this->sanitizeString($this->getXPathValue($xpath, './/content:encoded')),
            'post_excerpt' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:post_excerpt')),
            'post_status' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:status'), 20),
            'post_type' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:post_type'), 50),
            'post_date' => $this->convertWordPressDate($this->getXPathValue($xpath, './/wp:post_date')),
            'post_date_gmt' => $this->convertWordPressDate($this->getXPathValue($xpath, './/wp:post_date_gmt')),
            'post_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:post_name'), 255),
            'post_parent' => $this->sanitizeInteger($this->getXPathValue($xpath, './/wp:post_parent')),
            'menu_order' => $this->sanitizeInteger($this->getXPathValue($xpath, './/wp:menu_order')),
            'guid' => $this->sanitizeUrl($this->getXPathValue($xpath, './/guid')),
            'processed' => false,
        ]);
    }
    
    protected function extractPostId(DOMXPath $xpath): ?int
    {
        // Try to get post ID from wp:post_id
        $postId = $this->getXPathValue($xpath, './/wp:post_id');
        
        if ($postId) {
            return (int) $postId;
        }
        
        // If no wp:post_id, try to extract from GUID or link
        $guid = $this->getXPathValue($xpath, './/guid');
        $link = $this->getXPathValue($xpath, './/link');
        
        // Try to extract ID from GUID (e.g., "?p=123")
        if ($guid && preg_match('/[?&]p=(\d+)/', $guid, $matches)) {
            return (int) $matches[1];
        } 
        
        // Try to extract ID from link (e.g., "?p=123")
        if ($link && preg_match('/[?&]p=(\d+)/', $link, $matches)) {
            return (int) $matches[1];
        }
        
        // Generate a unique ID based on title + date hash as last resort
        $title = $this->getXPathValue($xpath, './/title');
        $date = $this->getXPathValue($xpath, './/wp:post_date');
        
        if ($title || $date) {
            return $this->generateSecureId($title, $date);
        }
        
        return null;
    }
    
    public function validate(array $data): bool
    {
        // A valid post must have an ID and either a title or content
        return isset($data['post_id']) && 
               $data['post_id'] > 0 && 
               (!empty($data['post_title']) || !empty($data['post_content']));
    }
}