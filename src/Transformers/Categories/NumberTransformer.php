<?php

namespace Crumbls\Importer\Transformers\Categories;
use Crumbls\Importer\Transformers\AbstractTransformer;

class NumberTransformer extends AbstractTransformer
{
	public function getName(): string
	{
		return 'number';
	}

	public function getOperationNames(): array
	{
		return [
			'number',
			'integer',
			'float',
			'round',
			'ceil',
			'floor',
			'abs',
			'number_format',
			'currency_format',
			'percentage',
			'decimal',
			'add',
			'subtract',
			'multiply',
			'divide',
			'mod',
			'min',
			'max',
			'between',
			'clamp'
		];
	}

	public function transform(mixed $value, array $parameters = []): mixed
	{
		if ($value === null) return null;

		$operation = $parameters['type'] ?? 'number';

		return match ($operation) {
			'integer' => (int)$value,
			'float' => (float)$value,
			'round' => round($value, $parameters['precision'] ?? 0, $parameters['mode'] ?? PHP_ROUND_HALF_UP),
			'ceil' => ceil($value),
			'floor' => floor($value),
			'abs' => abs($value),
			'number_format' => number_format(
				$value,
				$parameters['decimals'] ?? 0,
				$parameters['decimal_point'] ?? '.',
				$parameters['thousands_sep'] ?? ','
			),
			'currency_format' => $this->formatCurrency($value, $parameters),
			'percentage' => $this->formatPercentage($value, $parameters),
			'decimal' => number_format($value, $parameters['precision'] ?? 2, '.', ''),
			'add' => $value + ($parameters['amount'] ?? 0),
			'subtract' => $value - ($parameters['amount'] ?? 0),
			'multiply' => $value * ($parameters['factor'] ?? 1),
			'divide' => $parameters['divisor'] ? $value / $parameters['divisor'] : $value,
			'mod' => $value % ($parameters['divisor'] ?? 1),
			'min' => max($value, $parameters['min'] ?? $value),
			'max' => min($value, $parameters['max'] ?? $value),
			'between' => $this->between($value, $parameters),
			'clamp' => $this->clamp($value, $parameters),
			default => $value
		};
	}

	protected function formatCurrency($value, array $parameters): string
	{
		$symbol = $parameters['symbol'] ?? '$';
		$position = $parameters['symbol_position'] ?? 'before';
		$formatted = number_format(
			$value,
			$parameters['decimals'] ?? 2,
			$parameters['decimal_point'] ?? '.',
			$parameters['thousands_sep'] ?? ','
		);

		return $position === 'before' ? $symbol . $formatted : $formatted . $symbol;
	}

	protected function formatPercentage($value, array $parameters): string
	{
		$multiply = $parameters['multiply'] ?? true;
		$decimals = $parameters['decimals'] ?? 2;

		$value = $multiply ? $value * 100 : $value;
		return number_format($value, $decimals) . '%';
	}

	protected function between($value, array $parameters): float
	{
		$min = $parameters['min'] ?? -INF;
		$max = $parameters['max'] ?? INF;
		return max(min($value, $max), $min);
	}

	protected function clamp($value, array $parameters): float
	{
		$min = $parameters['min'] ?? $value;
		$max = $parameters['max'] ?? $value;
		return max(min($value, $max), $min);
	}
}