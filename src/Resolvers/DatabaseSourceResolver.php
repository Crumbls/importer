<?php

namespace Crumbls\Importer\Resolvers;

use Crumbls\Importer\Resolvers\Contracts\SourceResolverContract;

class DatabaseSourceResolver implements SourceResolverContract
{
	public function __construct(protected string $sourceType, protected string $sourceDetail) {
	}

	public function canHandle(string $sourceType, string $sourceDetail): bool
    {
		return false;
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