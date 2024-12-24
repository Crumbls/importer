<?php

namespace Crumbls\Importer\Transformers;

use Crumbls\Importer\Transformers\Contracts\TransformerInterface;
use Crumbls\Importer\Transformers\Exceptions\TransformationException;

abstract class AbstractTransformer implements TransformerInterface
{
	/**
	 * Validate parameters before transformation
	 */
	protected function validateParameters(array $parameters): void
	{
		$missing = array_diff($this->getRequiredParameters(), array_keys($parameters));

		if (!empty($missing)) {
			throw new TransformationException(
				sprintf(
					'Missing required parameters for %s: %s',
					$this->getName(),
					implode(', ', $missing)
				)
			);
		}
	}

	/**
	 * Get required parameters
	 */
	public function getRequiredParameters(): array
	{
		return [];
	}
}