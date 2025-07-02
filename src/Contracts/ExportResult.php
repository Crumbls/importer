<?php

namespace Crumbls\Importer\Contracts;

interface ExportResult
{
    /**
     * Get the number of records exported
     */
    public function getExported(): int;
    
    /**
     * Get the number of records that failed to export
     */
    public function getFailed(): int;
    
    /**
     * Check if the export was successful
     */
    public function isSuccessful(): bool;
    
    /**
     * Check if there were any errors during export
     */
    public function hasErrors(): bool;
    
    /**
     * Get array of errors that occurred during export
     */
    public function getErrors(): array;
    
    /**
     * Get the destination path/location of the exported data
     */
    public function getDestination(): string;
    
    /**
     * Get the export format used
     */
    public function getFormat(): string;
    
    /**
     * Get export statistics and metadata
     */
    public function getStats(): array;
    
    /**
     * Get the time taken for the export
     */
    public function getDuration(): float;
}