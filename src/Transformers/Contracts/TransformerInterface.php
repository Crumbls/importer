<?php

namespace Crumbls\Importer\Transformers\Contracts;

interface TransformerInterface
{
	/**
	 * Transform a value
	 *
	 * @param mixed $value
	 * @param array $parameters
	 * @return mixed
	 */
	public function transform(mixed $value, array $parameters = []): mixed;

	/**
	 * Get the transformer name
	 */
	public function getName(): string;

	/**
	 * Get required parameters
	 *
	 * @return array
	 */
	public function getRequiredParameters(): array;
}