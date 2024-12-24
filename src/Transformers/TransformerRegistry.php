<?php

namespace Crumbls\Importer\Transformers;

use Crumbls\Importer\Transformers\Contracts\TransformerInterface;
use Crumbls\Importer\Exceptions\InvalidTransformerException;

class TransformerRegistry
{
	/**
	 * @var array<string, TransformerInterface>
	 */
	protected array $transformers = [];

	/**
	 * @var array<string, string>
	 */
	protected array $operationMap = [];

	/**
	 * Register a new transformer
	 */
	public function register(TransformerInterface $transformer): void
	{
		$this->transformers[$transformer->getName()] = $transformer;

		// Register all operation names if available
		if (method_exists($transformer, 'getOperationNames')) {
			foreach ($transformer->getOperationNames() as $operation) {
				$this->operationMap[$operation] = $transformer->getName();
			}
		}
	}

	/**
	 * Get a transformer by name
	 */
	public function get(string $name): TransformerInterface
	{
		// Check if this is an operation name
		if (isset($this->operationMap[$name])) {
			$name = $this->operationMap[$name];
		}

		if (!isset($this->transformers[$name])) {
			dd($this->transformers);
			throw new InvalidTransformerException("Transformer not found: {$name}");
		}

		return $this->transformers[$name];
	}

	/**
	 * Check if a transformer exists
	 */
	public function has(string $name): bool
	{
		return isset($this->transformers[$name]) || isset($this->operationMap[$name]);
	}
}