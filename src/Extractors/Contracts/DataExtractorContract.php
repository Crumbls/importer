<?php

namespace Crumbls\Importer\Extractors\Contracts;

use DOMXPath;

interface DataExtractorContract
{
    /**
     * Extract data from a DOM node using XPath
     *
     * @param DOMXPath $xpath The XPath processor for the current item
     * @param array $context Additional context data
     * @return array The extracted data
     */
    public function extract(DOMXPath $xpath, array $context = []): array;
    
    /**
     * Get the name of this extractor for logging/debugging
     *
     * @return string
     */
    public function getName(): string;
    
    /**
     * Validate extracted data before returning
     *
     * @param array $data The extracted data
     * @return bool Whether the data is valid
     */
    public function validate(array $data): bool;
}