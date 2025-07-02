<?php

namespace Crumbls\Importer\Contracts;

/**
 * Interface for media import drivers
 */
interface MediaImportDriver
{
    /**
     * Import an attachment from WordPress XML data
     */
    public function importAttachment(array $attachmentData, string $downloadUrl = null): mixed;
    
    /**
     * Set featured image for a model
     */
    public function setFeaturedImage($model, $mediaId): void;
    
    /**
     * Check if this driver is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get driver name
     */
    public function getName(): string;
}