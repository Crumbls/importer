<?php

namespace Crumbls\Importer\Support;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class ModelAnalyzer
{
	public function getPropertyType(ReflectionClass $reflection, string $property): ?string
	{
		try {
			$property = $reflection->getProperty($property);
			$type = $property->getType();

			if ($type instanceof ReflectionNamedType) {
				return $type->getName();
			}

			// Check PHPDoc if no type hint
			return $this->getPropertyTypeFromDocBlock($property);
		} catch (\ReflectionException $e) {
			return null;
		}
	}

	protected function getPropertyTypeFromDocBlock(ReflectionProperty $property): ?string
	{
		$docComment = $property->getDocComment();
		if (!$docComment) {
			return null;
		}

		if (preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
			return $this->normalizeDocBlockType($matches[1]);
		}

		return null;
	}

	protected function normalizeDocBlockType(string $type): string
	{
		// Handle nullable types
		if (str_starts_with($type, '?')) {
			$type = substr($type, 1);
		}

		// Handle union types
		if (str_contains($type, '|')) {
			$types = explode('|', $type);
			// Return the first non-null type
			return collect($types)
				->filter(fn($t) => $t !== 'null')
				->first() ?? 'mixed';
		}

		return match($type) {
			'integer' => 'int',
			'boolean' => 'bool',
			'double' => 'float',
			default => $type
		};
	}
}