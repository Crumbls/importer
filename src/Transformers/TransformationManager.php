<?php

namespace Crumbls\Importer\Transformers;

class TransformationManager
{
	public function __construct(
		protected TransformerRegistry $registry
	) {}

	/**
	 * Apply a transformation
	 */
	public function transform(mixed $value, array $transformation): mixed
	{
		$transformer = $this->registry->get($transformation['type']);

		return $transformer->transform(
			$value,
			$transformation['parameters'] ?? []
		);
	}

	/**
	 * Apply multiple transformations
	 */
	public function transformMany(mixed $value, array $transformations): mixed
	{
		foreach ($transformations as $transformation) {
			$value = $this->transform($value, $transformation);
		}

		return $value;
	}
}