<?php

namespace Crumbls\Importer\Transformers;

use InvalidArgumentException;
use Selective\Transformer\ArrayTransformer;

class TransformationDefinition
{
	protected string $name;
	protected string $modelName;
	protected string $fromTable;
	protected string $toTable;
	protected array $mappings = [];
	protected array $types = [];
	protected array $transformations = [];
	protected array $excludedColumns = [];

	// Predefined transformation types
	public const TRANSFORM_STRIP_TAGS = 'strip_tags';
	public const TRANSFORM_LOWERCASE = 'lowercase';
	public const TRANSFORM_UPPERCASE = 'uppercase';
	public const TRANSFORM_TRIM = 'trim';
	public const TRANSFORM_DATE_FORMAT = 'date_format';

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function setModelName(string $modelName): self
	{
		$this->modelName = $modelName;
		return $this;
	}

	public function getModelName(): string
	{
		return $this->modelName ?? '';
	}

	public function setFromTable(string $fromTable): self
	{
		$this->fromTable = $fromTable;
		return $this;
	}

	public function getFromTable(): string
	{
		return $this->fromTable ?? '';
	}

	public function setToTable(string $toTable): self
	{
		$this->toTable = $toTable;
		return $this;
	}

	public function getToTable(): string
	{
		return $this->toTable ?? '';
	}

	public function getMappedKeys(): array {
		return array_keys($this->mappings);
	}

	public function getKeys(): array {
		return array_unique(array_merge(array_keys($this->mappings), array_values($this->mappings)));
	}

	public function exclude(array|string $columns): self
	{
		$columns = is_array($columns) ? $columns : [$columns];
		$this->excludedColumns = array_merge($this->excludedColumns, $columns);
		return $this;
	}

	public function isExcluded(string $column): bool
	{
		return in_array($column, $this->excludedColumns);
	}

	public function map(string $from): self
	{
		$this->currentField = $from;
		return $this;
	}

	public function to(string $to): self
	{
		if (!isset($this->currentField)) {
			throw new InvalidArgumentException('Must call map() before to()');
		}

		$this->mappings[$this->currentField] = $to;
		return $this;
	}

	public function type(string $type, ?array $parameters = null): self
	{
		if (!isset($this->currentField)) {
			throw new InvalidArgumentException('Must call map() before type()');
		}

		$this->types[$this->currentField] = [
			'type' => $type,
			'parameters' => $parameters
		];

		return $this;
	}

	public function transform(string $transformationType, ?array $parameters = []): self
	{
		if (!isset($this->currentField)) {
			throw new InvalidArgumentException('Must call map() before transform()');
		}

		if (!isset($this->transformations[$this->currentField])) {
			$this->transformations[$this->currentField] = [];
		}

		$this->transformations[$this->currentField][] = [
			'type' => $transformationType,
			'parameters' => $parameters
		];

		return $this;
	}

	protected function applyTransformation(string $type, $value, array $parameters = [])
	{
		switch ($type) {
			case self::TRANSFORM_STRIP_TAGS:
				return strip_tags($value);
			case self::TRANSFORM_LOWERCASE:
				return strtolower($value);
			case self::TRANSFORM_UPPERCASE:
				return strtoupper($value);
			case self::TRANSFORM_TRIM:
				return trim($value);
			case self::TRANSFORM_DATE_FORMAT:
				return date($parameters['format'] ?? 'Y-m-d H:i:s', strtotime($value));
			default:
				return $value;
		}
	}

	public function buildTransformer(): ArrayTransformer
	{
		$transformer = new ArrayTransformer();

		foreach ($this->mappings as $from => $to) {
			$rule = $transformer->transform($from)->to($to);

			if (isset($this->transformations[$from])) {
				$rule->withCallback(function ($value) use ($from) {
					foreach ($this->transformations[$from] as $transformation) {
						$value = $this->applyTransformation(
							$transformation['type'],
							$value,
							$transformation['parameters']
						);
					}
					return $value;
				});
			}
		}

		return $transformer;
	}

	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'model_name' => $this->modelName ?? '',
			'from_table' => $this->fromTable ?? '',
			'to_table' => $this->toTable ?? '',
			'mappings' => $this->mappings,
			'types' => $this->types,
			'transformations' => $this->transformations,
			'excluded_columns' => $this->excludedColumns
		];
	}

	public static function fromArray(array $data): self
	{
		$instance = new self($data['name']);
		$instance->modelName = $data['model_name'] ?? '';
		$instance->fromTable = $data['from_table'] ?? '';
		$instance->toTable = $data['to_table'] ?? '';
		$instance->mappings = $data['mappings'];
		$instance->types = $data['types'];
		$instance->transformations = $data['transformations'];
		$instance->excludedColumns = $data['excluded_columns'];

		return $instance;
	}

	public function nullable(bool $nullable = true): self
	{
		if (!isset($this->currentField)) {
			throw new InvalidArgumentException('Must call map() before nullable()');
		}

		$this->modifiers[$this->currentField]['nullable'] = $nullable;
		return $this;
	}

	public function default($value): self
	{
		if (!isset($this->currentField)) {
			throw new InvalidArgumentException('Must call map() before default()');
		}

		$this->modifiers[$this->currentField]['default'] = $value;
		return $this;
	}
}