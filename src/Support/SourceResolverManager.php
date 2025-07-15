<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Resolvers\Contracts\SourceResolverContract;

class SourceResolverManager
{
    protected array $resolvers = [];

    public function addResolver(SourceResolverContract $resolver): static
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    public function resolve(string $sourceType, string $sourceDetail): mixed
    {
        foreach ($this->resolvers as $resolver) {
			if ($resolver->canHandle($sourceType, $sourceDetail)) {
                return $resolver->resolve();
            }
        }

        throw new \InvalidArgumentException("No resolver found for source type: {$sourceDetail}");
    }

    public function getMetadata(string $sourceType, string $sourceDetail): array
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->canHandle($sourceType)) {
                return $resolver->getMetadata();
            }
        }

        return [];
    }
}