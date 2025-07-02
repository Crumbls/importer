<?php

namespace Crumbls\Importer\Contracts;

/**
 * Interface for tags/taxonomy import drivers
 */
interface TagsImportDriver
{
    /**
     * Import a category from WordPress XML data
     */
    public function importCategory(array $categoryData): mixed;
    
    /**
     * Import a tag from WordPress XML data  
     */
    public function importTag(array $tagData): mixed;
    
    /**
     * Attach tags/categories to a model
     */
    public function attachToModel($model, array $tags, string $type = 'tag'): void;
    
    /**
     * Check if this driver is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get driver name
     */
    public function getName(): string;
}