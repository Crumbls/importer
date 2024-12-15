<?php


namespace Crumbls\Importer\Traits;

trait HasColumnMapping {
	protected array $columnDefinitions = [];

	public function getColumnDefinitions(): array {
		return array_merge($this->getDefaultColumnDefinitions(), $this->columnDefinitions);
	}

	public function mapRow(array $data): array {
		$mapped = [];
		$definitions = $this->getColumnDefinitions();

		foreach ($data as $key => $value) {
			if (!isset($definitions[$key])) {
				continue;
			}

			$def = $definitions[$key];
			$mapped[$def->newName] = $this->transformValue($value, $def);
		}

		return $mapped;
	}

	protected function transformValue($value, ColumnDefinition $definition): mixed {
		if ($value === null) {
			return $definition->nullable ? null : $definition->default;
		}

		return match($definition->transform) {
			'integer' => (int) $value,
			'float' => (float) $value,
			'boolean' => (bool) $value,
			'datetime' => date('Y-m-d H:i:s', strtotime($value)),
			'date' => date('Y-m-d', strtotime($value)),
			'json' => is_string($value) ? json_decode($value, true) : $value,
			'string' => (string) $value,
			default => $value
		};
	}

	protected function getDefaultColumnDefinitions(): array {
		return [];
	}
}
