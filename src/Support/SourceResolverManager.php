<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Resolvers\Contracts\SourceResolverContract;
use Crumbls\Importer\Exceptions\ImportException;

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

        throw ImportException::sourceNotFound($sourceDetail);
    }

    public function getMetadata(string $sourceType, string $sourceDetail): array
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->canHandle($sourceType,$sourceDetail)) {
                return $resolver->getMetadata();
            }
        }

        return [];
    }
}