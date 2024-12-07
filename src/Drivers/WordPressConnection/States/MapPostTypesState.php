<?php

namespace Crumbls\Importer\Drivers\WordPressConnection\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use PDO;
use Illuminate\Support\Str;

class MapPostTypesState extends AbstractState
{
	private const COLUMN_MAPPING = [
		'ID' => 'id',
		'post_content' => 'content',
		'post_title' => 'title',
		'post_excerpt' => 'excerpt',
		'post_status' => 'status',
		'post_name' => 'slug',
		'post_author' => 'author_id',
		'post_parent' => 'parent_id',
		'post_type' => 'type',
		'post_date' => 'created_at',
		'post_modified' => 'updated_at'
	];

	private const BASE_COLUMNS = [
		'ID' => ['type' => 'id', 'unsigned' => true, 'nullable' => false],
		'post_author' => ['type' => 'integer', 'unsigned' => true, 'nullable' => false, 'default' => 0],
		'post_date' => ['type' => 'datetime', 'nullable' => false],
		'post_date_gmt' => ['type' => 'datetime', 'nullable' => false],
		'post_content' => ['type' => 'longtext', 'nullable' => true],
		'post_title' => ['type' => 'string', 'length' => 255, 'nullable' => true],
		'post_excerpt' => ['type' => 'text', 'nullable' => true],
		'post_status' => ['type' => 'string', 'length' => 20, 'nullable' => false, 'default' => 'publish'],
		'post_name' => ['type' => 'string', 'length' => 200, 'nullable' => false],
		'post_type' => ['type' => 'string', 'length' => 20, 'nullable' => false, 'default' => 'post']
	];

	public function getName(): string {
		return 'map-post-types';
	}

	public function handle(): void {
		$connection = $this->getDriver()->getImportConnection();

		$record = $this->getRecord();

		$md = $record->metadata ?? [];
		$md['tables'] = $md['tables'] ?? [];

		// Get all post types
		$types = $connection->table('posts')
			->select('post_type')
			->distinct()
			->get()
			->pluck('post_type');

		$namespace = app()->getNamespace().'Models';

		foreach ($types as $type) {
			$modelName = $this->generateModelName($type);
			$columns = $this->mapColumns(self::BASE_COLUMNS);

			// Get meta keys for this post type
			$metaKeys = $connection->table('posts')
				->join('postmeta', 'posts.ID', '=', 'postmeta.post_id')
				->where('post_type', $type)
				->select('meta_key')
				->distinct()
				->get()
				->pluck('meta_key');

			// Analyze meta values to determine column types
			foreach ($metaKeys as $key) {
				$sample = $connection->table('postmeta')
					->join('posts', 'posts.ID', '=', 'postmeta.post_id')
					->where('post_type', $type)
					->where('meta_key', $key)
					->whereNotNull('meta_value')
					->limit(100)
					->pluck('meta_value');

				$columns[$this->transformMetaKey($key)] = $this->determineColumnType($sample);
			}

			/*
			$postTypes[$type] = [
				'count' => $connection->table('posts')->where('post_type', $type)->count(),
				'columns' => $columns,
				'model' => [
					'name' => $modelName,
					'table' => $this->generateTableName($type),
					'namespace' => $namespace
				]
			];
			*/

			$mapper = new ColumnMapper();

			$md['tables'][$type] = [
				'count' => $connection->table('posts')->where('post_type', $type)->count(),
				'columns' => $mapper->mapData($columns),
				'model' => [
					'column_mapping' => self::COLUMN_MAPPING,
					'name' => $modelName,
					'table' => $this->generateTableName($type),
					'namespace' => $namespace
				]
			];
		}

		$record->update([
			'metadata' => $md
		]);
	}


	private function determineColumnType($values): array {
		$allNumeric = true;
		$allInteger = true;
		$maxLength = 0;
		$hasNull = false;
		$isJson = true;
		$isDate = true;
		$isSerializedArray = true;

		foreach ($values as $value) {
			if ($value === null) {
				$hasNull = true;
				continue;
			}

			// Check numeric
			if (!is_numeric($value)) {
				$allNumeric = false;
				$allInteger = false;
			} elseif ($allInteger && strpos($value, '.') !== false) {
				$allInteger = false;
			}

			// Check JSON
			if ($isJson) {
				json_decode($value);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$isJson = false;
				}
			}

			// Check date
			if ($isDate && !strtotime($value)) {
				$isDate = false;
			}

			// Check serialized array
			if ($isSerializedArray && !@unserialize($value)) {
				$isSerializedArray = false;
			}

			$maxLength = max($maxLength, strlen($value));
		}

		if ($isJson) {
			return ['type' => 'json', 'nullable' => $hasNull];
		}

		if ($isDate) {
			return ['type' => 'datetime', 'nullable' => $hasNull];
		}

		if ($isSerializedArray) {
			return ['type' => 'serialized', 'nullable' => $hasNull];
		}

		if ($allInteger) {
			return ['type' => 'integer', 'nullable' => $hasNull, 'unsigned' => !str_contains(implode('', $values), '-')];
		}

		if ($allNumeric) {
			return ['type' => 'decimal', 'nullable' => $hasNull, 'precision' => 10, 'scale' => 2];
		}

		if ($maxLength <= 255) {
			return ['type' => 'string', 'length' => $maxLength, 'nullable' => $hasNull];
		}

		return ['type' => 'text', 'nullable' => $hasNull];
	}


	private function generateModelName(string $type): string {
		return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $type)));
	}

	private function generateTableName(string $type): string {
		return strtolower(Str::plural($type));
	}


	private function mapColumns(array $columns): array {
		$mapped = [];
		foreach ($columns as $originalName => $config) {
			$newName = self::COLUMN_MAPPING[$originalName] ?? $this->transformMetaKey($originalName);
			$mapped[$newName] = $config;
		}
		return $mapped;
	}

	public function transformMetaKey(string $key): string {
		$key = preg_replace('/^(_wp_|wp_|post_)/', '', $key);
		return Str::snake($key);
	}

	public function mapData(array $data, array $columnMap = []): array {
		$mapped = [];
		$map = array_merge(self::COLUMN_MAPPING, $columnMap);

		foreach ($data as $key => $value) {
			$newKey = $map[$key] ?? $this->transformMetaKey($key);
			$mapped[$newKey] = $value;
		}

		return $mapped;
	}
}