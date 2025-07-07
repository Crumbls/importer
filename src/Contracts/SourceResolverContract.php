<?php

namespace Crumbls\Importer\Contracts;

interface SourceResolverContract
{
    public function resolve(): mixed;
    
    public function canHandle(string $sourceType): bool;
    
    public function getMetadata(): array;
}