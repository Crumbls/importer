<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Xml\XmlSchema;

class WpxmlDriver extends XmlDriver
{
    public function __construct(array $config = [])
    {
        // Extract everything by default for migrations
        $defaultEntities = [
            'posts' => true,
            'postmeta' => true,
            'attachments' => true,
            'users' => true,
            'comments' => true,
            'commentmeta' => true,
            'terms' => true,
            'categories' => true,
            'tags' => true
        ];
        
        $wpConfig = array_merge([
            'schema' => XmlSchema::wordpress(),
            'enabled_entities' => array_merge($defaultEntities, $config['enabled_entities'] ?? []),
            'chunk_size' => $config['chunk_size'] ?? 100
        ], $config);
        
        parent::__construct($wpConfig);
    }

    public function validate(string $source): bool
    {
        if (!file_exists($source) || !is_readable($source)) {
            return false;
        }
        
        return $this->isWordPressXml($source);
    }
    
    // WordPress-specific fluent methods for all entities
    public function extractPosts(bool $extract = true): self
    {
        return $this->enableEntity('posts', $extract);
    }
    
    public function extractPostMeta(bool $extract = true): self
    {
        return $this->enableEntity('postmeta', $extract);
    }
    
    public function extractAttachments(bool $extract = true): self
    {
        return $this->enableEntity('attachments', $extract);
    }
    
    public function extractUsers(bool $extract = true): self
    {
        return $this->enableEntity('users', $extract);
    }
    
    public function extractComments(bool $extract = true): self
    {
        return $this->enableEntity('comments', $extract);
    }
    
    public function extractCommentMeta(bool $extract = true): self
    {
        return $this->enableEntity('commentmeta', $extract);
    }
    
    public function extractTerms(bool $extract = true): self
    {
        return $this->enableEntity('terms', $extract);
    }
    
    public function extractCategories(bool $extract = true): self
    {
        return $this->enableEntity('categories', $extract);
    }
    
    public function extractTags(bool $extract = true): self
    {
        return $this->enableEntity('tags', $extract);
    }
    
    // Convenience methods for common filtering
    public function onlyPosts(): self
    {
        return $this->onlyEntities(['posts', 'postmeta']);
    }
    
    public function onlyContent(): self
    {
        return $this->onlyEntities(['posts', 'postmeta', 'attachments', 'categories', 'tags']);
    }
    
    public function onlyUsers(): self
    {
        return $this->onlyEntities(['users']);
    }
    
    public function onlyComments(): self
    {
        return $this->onlyEntities(['comments', 'commentmeta']);
    }
    
    public function everything(): self
    {
        // Re-enable all entities (useful after filtering)
        foreach (array_keys($this->schema->getEntities()) as $entity) {
            $this->enableEntity($entity, true);
        }
        return $this;
    }
    
    protected function isWordPressXml(string $source): bool
    {
        try {
            // Read first few KB to check for WordPress XML markers
            $handle = fopen($source, 'r');
            if (!$handle) {
                return false;
            }
            
            $sample = fread($handle, 2048);
            fclose($handle);
            
            // Check for WordPress XML namespace and WXR version
            $hasWpNamespace = strpos($sample, 'xmlns:wp="https://wordpress.org/export/') !== false;
            $hasWxrVersion = strpos($sample, '<wp:wxr_version>') !== false;
            $hasBaseUrl = strpos($sample, '<wp:base_site_url>') !== false;
            
            return $hasWpNamespace && ($hasWxrVersion || $hasBaseUrl);
            
        } catch (\Exception $e) {
            return false;
        }
    }
}