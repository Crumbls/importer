<?php

namespace Crumbls\Importer\Config;

class TransformerConfig
{
	protected array $mappings = [];
	protected array $types = [];
	protected array $transformations = [];

	public function addMapping(string $from, string $to): self
	{
		$this->mappings[$from] = $to;
		return $this;
	}

	public function validate(): void
	{
		// Validation logic
	}
}