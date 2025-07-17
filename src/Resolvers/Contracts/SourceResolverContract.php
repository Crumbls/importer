<?php

namespace Crumbls\Importer\Resolvers\Contracts;

interface SourceResolverContract
{
    public function resolve(): mixed;
    
    public function canHandle(string $sourceType, string $sourceDetail): bool;
    
    public function getMetadata(): array;
}