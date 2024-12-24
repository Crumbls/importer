<?php

namespace Crumbls\Importer\Transformers\Categories;

use Crumbls\Importer\Transformers\AbstractTransformer;

class DateTransformer extends AbstractTransformer
{
	public function getName(): string
	{
		return 'date';
	}

	/**
	 * Get all supported operation names
	 */
	public function getOperationNames(): array
	{
		return [
			'date',
			'date_format',
			'datetime',
			'timestamp',
			'timezone_convert',
			'relative_date',
			'add_days',
			'sub_days',
			'start_of_day',
			'end_of_day',
			'start_of_month',
			'end_of_month'
		];
	}

	public function transform(mixed $value, array $parameters = []): mixed
	{
		if (!$value) return null;

		// Map old operation names to new ones
		$operation = match($parameters['type'] ?? 'format') {
			'date_format' => 'format',
			'timezone_convert' => 'timezone',
			default => $parameters['type'] ?? 'format'
		};

		return match($operation) {
			'format' => $this->formatDate($value, $parameters),
			'modify' => $this->modifyDate($value, $parameters),
			'timezone' => $this->convertTimezone($value, $parameters),
			'relative' => $this->getRelativeDate($value),
			'add_days' => $this->modifyDate($value, ['modification' => "+{$parameters['days']} days"]),
			'sub_days' => $this->modifyDate($value, ['modification' => "-{$parameters['days']} days"]),
			'start_of_day' => $this->modifyDate($value, ['modification' => 'start of day']),
			'end_of_day' => $this->modifyDate($value, ['modification' => 'end of day']),
			'start_of_month' => $this->modifyDate($value, ['modification' => 'first day of this month']),
			'end_of_month' => $this->modifyDate($value, ['modification' => 'last day of this month']),
			default => $value
		};
	}

	protected function formatDate($value, array $parameters): string
	{
		$format = $parameters['format'] ?? 'Y-m-d H:i:s';
		return date($format, strtotime($value));
	}

	protected function modifyDate($value, array $parameters): string
	{
		$modification = $parameters['modification'] ?? '';
		return date('Y-m-d H:i:s', strtotime($modification, strtotime($value)));
	}

	protected function convertTimezone($value, array $parameters): string
	{
		$fromTz = $parameters['from'] ?? 'UTC';
		$toTz = $parameters['to'] ?? date_default_timezone_get();

		$date = new \DateTime($value, new \DateTimeZone($fromTz));
		$date->setTimezone(new \DateTimeZone($toTz));

		return $date->format($parameters['format'] ?? 'Y-m-d H:i:s');
	}

	protected function getRelativeDate($value): string
	{
		$time = strtotime($value);
		$now = time();
		$diff = $now - $time;

		return match(true) {
			$diff < 60 => 'just now',
			$diff < 3600 => floor($diff / 60) . ' minutes ago',
			$diff < 86400 => floor($diff / 3600) . ' hours ago',
			$diff < 604800 => floor($diff / 86400) . ' days ago',
			default => date('Y-m-d', $time)
		};
	}
}

