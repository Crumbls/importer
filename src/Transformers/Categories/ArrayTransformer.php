<?php

namespace Crumbls\Importer\Transformers\Categories;

use Crumbls\Importer\Transformers\AbstractTransformer;
use Illuminate\Support\Str;

class ArrayTransformer extends AbstractTransformer
{
	public function getName(): string
	{
		return 'array';
	}

	public function getOperationNames(): array
	{
		return [
			'array',
			'json_decode',
			'json_encode',
			'serialize',
			'unserialize',
			'implode',
			'explode',
			'join',
			'split',
			'pluck',
			'filter',
			'map',
			'sort',
			'reverse',
			'unique',
			'merge',
			'slice',
			'keys',
			'values',
			'count',
			'first',
			'last',
			'random',
			'chunk'
		];
	}

	public function transform(mixed $value, array $parameters = []): mixed
	{
		$operation = $parameters['type'] ?? 'array';

		return match ($operation) {
			'json_decode' => $this->jsonDecode($value, $parameters),
			'json_encode' => $this->jsonEncode($value, $parameters),
			'serialize' => serialize($value),
			'unserialize' => unserialize($value),
			'implode', 'join' => $this->implode($value, $parameters),
			'explode', 'split' => $this->explode($value, $parameters),
			'pluck' => $this->pluck($value, $parameters),
			'filter' => $this->filter($value, $parameters),
			'map' => $this->map($value, $parameters),
			'sort' => $this->sort($value, $parameters),
			'reverse' => array_reverse($this->ensureArray($value)),
			'unique' => array_unique($this->ensureArray($value)),
			'merge' => array_merge($this->ensureArray($value), $parameters['array'] ?? []),
			'slice' => array_slice(
				$this->ensureArray($value),
				$parameters['offset'] ?? 0,
				$parameters['length'] ?? null
			),
			'keys' => array_keys($this->ensureArray($value)),
			'values' => array_values($this->ensureArray($value)),
			'count' => count($this->ensureArray($value)),
			'first' => $this->first($value),
			'last' => $this->last($value),
			'random' => $this->random($value, $parameters),
			'chunk' => array_chunk(
				$this->ensureArray($value),
				$parameters['size'] ?? 1,
				$parameters['preserve_keys'] ?? false
			),
			default => $this->ensureArray($value)
		};
	}

	protected function ensureArray($value): array
	{
		if (is_array($value)) return $value;
		if (is_object($value)) return (array)$value;
		return [$value];
	}

	protected function jsonDecode($value, array $parameters): mixed
	{
		return json_decode($value, $parameters['assoc'] ?? true);
	}

	protected function jsonEncode($value, array $parameters): string
	{
		return json_encode($value, $parameters['options'] ?? 0);
	}

	protected function implode($value, array $parameters): string
	{
		return implode($parameters['glue'] ?? ',', $this->ensureArray($value));
	}

	protected function explode($value, array $parameters): array
	{
		if (is_array($value)) return $value;
		return explode($parameters['delimiter'] ?? ',', $value);
	}

	protected function pluck($value, array $parameters): array
	{
		$array = $this->ensureArray($value);
		$key = $parameters['key'] ?? null;
		return array_column($array, $key);
	}

	protected function filter($value, array $parameters): array
	{
		$array = $this->ensureArray($value);
		$key = $parameters['key'] ?? null;
		$operator = $parameters['operator'] ?? '=';
		$compare = $parameters['value'] ?? null;

		return array_filter($array, function ($item) use ($key, $operator, $compare) {
			$itemValue = $key ? ($item[$key] ?? null) : $item;

			return match ($operator) {
				'=' => $itemValue == $compare,
				'!=' => $itemValue != $compare,
				'>' => $itemValue > $compare,
				'<' => $itemValue < $compare,
				'contains' => is_string($itemValue) && str_contains($itemValue, $compare),
				'in' => in_array($itemValue, (array)$compare),
				default => true
			};
		});
	}

	protected function map($value, array $parameters): array
	{
		$array = $this->ensureArray($value);
		$callback = $parameters['callback'] ?? null;

		if (!$callback) return $array;

		return array_map($callback, $array);
	}

	protected function sort($value, array $parameters): array
	{
		$array = $this->ensureArray($value);
		$direction = $parameters['direction'] ?? 'asc';
		$type = $parameters['type'] ?? 'regular';

		if ($direction === 'desc') {
			$array = array_reverse($array);
		}

		match ($type) {
			'numeric' => sort($array, SORT_NUMERIC),
			'string' => sort($array, SORT_STRING),
			'natural' => natsort($array),
			default => sort($array)
		};

		return $array;
	}

	protected function first($value): mixed
	{
		$array = $this->ensureArray($value);
		return reset($array);
	}

	protected function last($value): mixed
	{
		$array = $this->ensureArray($value);
		return end($array);
	}

	protected function random($value, array $parameters): mixed
	{
		$array = $this->ensureArray($value);
		$count = $parameters['count'] ?? 1;

		if ($count === 1) {
			return $array[array_rand($array)];
		}

		$keys = array_rand($array, min($count, count($array)));
		return array_intersect_key($array, array_flip((array)$keys));
	}
}