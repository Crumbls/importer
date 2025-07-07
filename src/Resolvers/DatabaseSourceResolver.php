<?php

namespace Crumbls\Importer\Resolvers;

use Crumbls\Importer\Contracts\SourceResolverContract;

class DatabaseSourceResolver implements SourceResolverContract
{
    protected string $sourceType;
    protected string $sourceDetail;

    public function __construct(string $sourceType, string $sourceDetail)
    {
        $this->sourceType = $sourceType;
        $this->sourceDetail = $sourceDetail;
    }

    public function canHandle(string $sourceType): bool
    {
        return str_starts_with($sourceType, 'database::');
    }

    public function resolve(): mixed
    {
        // TODO: Implement database connection resolution
        throw new \RuntimeException('DatabaseSourceResolver not yet implemented');
    }

    public function getMetadata(): array
    {
        // TODO: Return database metadata (table count, size, etc.)
        return [];
    }
}