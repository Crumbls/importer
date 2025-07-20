<?php

namespace Crumbls\Importer\Models\Concerns;

use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\Support\SourceResolverManager;

trait HasSourceResolver {
	public function getSourceResolver(): SourceResolverManager
	{
		if (!$this->source_detail) {
			throw new \Exception('Source detail is required.');
		}

		$sourceResolver = new SourceResolverManager();

		[$sourceType, $sourceDetail] = explode('::', $this->source_detail, 2);

		if ($this->source_type == 'storage') {
			$sourceResolver->addResolver(new FileSourceResolver($sourceType, $this->source_detail));
		} else {
			throw new \Exception("Unsupported source type: {$this->source_type}");
		}
		return $sourceResolver;
	}

	public function getSourceMeta() : array {
		$sourceResolver = $this->getSourceResolver();
		return $sourceResolver->getMetadata($this->source_type, $this->source_detail);

	}
}