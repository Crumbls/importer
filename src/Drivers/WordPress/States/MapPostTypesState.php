<?php

namespace Crumbls\Importer\Drivers\WordPress\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Traits\HasTableTransformer;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use Crumbls\Importer\Transformers\TransformationDefinition;
use Illuminate\Support\Str;
use Selective\Transformer\ArrayTransformer;

/**
 * TODO: We will end up using a version of this code under common code to map other tables.
 * This is here just as a brainstorming point.
 */
class MapPostTypesState extends AbstractState
{
	use IsTableSchemaAware,
		HasTransformerDefinition;

	public function getName(): string {
		return 'map-post-types';
	}

	public function handle(): void {

		$connection = $this->getDriver()->getImportConnection();

		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$md['transformers'] = array_key_exists('transformers', $md) && is_array($md['transformers']) ? $md['transformers'] : [];

		// Get all post types
		$types = $connection->table('posts')
			->select('post_type')
			->distinct()
			->get()
			->pluck('post_type');

		$namespace = app()->getNamespace().'Models\\';

		$schema = $this->getTableSchema($connection, 'posts');

		$prefix = array_key_exists('table_prefix', $md) && is_string($md['table_prefix']) ? $md['table_prefix'] : '';

		foreach ($types as $type) {
			$modelName = $this->generateModelName($type);

			// Define your transformation
			$definition = new TransformationDefinition($type);
			$definition
				->setModelName($namespace.$modelName)
				->setFromTable($prefix.'posts')
				->setToTable($this->generateTableName($type))
				->exclude([
					'post_date_gmt',
					'comment_count',
					'menu_order',
					'to_ping',
					'post_modified_gmt',
					'post_content_filtered',
					'post_type'
				])
				->map('ID')
				->to('id')
				->type('bigIncrements')

				->map('post_parent')
				->to('parent_id')
				->type('unsignedBigInteger')
				->nullable(true)
				->default(null)
				->map('post_status')
				->to('status')
				->type('string')
				->nullable(true)
				->default(null)
				->map('post_date')
				->to('created_at')
				->type('datetime')
				->transform(TransformationDefinition::TRANSFORM_DATE_FORMAT, ['format' => 'Y-m-d H:i:s'])
				->map('post_modified')
				->to('updated_at')
				->type('datetime')
				->transform(TransformationDefinition::TRANSFORM_DATE_FORMAT, ['format' => 'Y-m-d H:i:s'])
				->map('post_name')
				->to('name')
				->type('string')
				->map('post_mime_type')
				->to('mime_type')
				->type('string')
				->map('post_content')
				->to('content')
				->type('longtext')
//				->transform(TransformationDefinition::TRANSFORM_STRIP_TAGS)
				->transform(TransformationDefinition::TRANSFORM_TRIM)
				->map('post_author')
				->to('user_id')
				->type('bigInteger')
				->map('post_title')
				->to('title')
				->type('text')
				->map('post_excerpt')
				->to('excerpt')
				->type('text')
				->map('post_password')
				->to('password')
				->type('string')

				->nullable(true)
				->default(null)

			;

			$this->setTransformer($definition);

			$existing = $definition->getMappedKeys();

			foreach($schema as $column) {
				if (in_array($column['name'], $existing)) {
					continue;
				}

				if ($definition->isExcluded($column['name'])) {
					continue;
				}

				/**
				 * I am not happy with how this is working right now. Jesus.
				 */
				$this->defineColumn($column);
			}

//			$serialized = $definition->toArray();
//			dd($serialized);

			// Get meta keys for this post type
			$metaKeys = $connection->table('posts')
				->join('postmeta', 'posts.ID', '=', 'postmeta.post_id')
				->where('post_type', $type)
				->select('meta_key')
				->distinct()
				->get()
				->pluck('meta_key');

			if ($metaKeys->count()) {

				// Analyze meta values to determine column types
				$metaKeys->diff($definition->getKeys())
					->each(function($key) use ($connection, $definition, $type) {
						$sample = $connection->table('postmeta')
							->join('posts', 'posts.ID', '=', 'postmeta.post_id')
							->where('post_type', $type)
							->where('meta_key', $key)
//							->whereNotNull('meta_value')
							->limit(100)
							->pluck('meta_value');

							$col = $this->determineColumnType($sample);

						$definition->map('postmeta.'.$key)
							->to($key)
							->type($col['type'])
							->nullable($col['nullable']);

					});

			}

			$md['transformers'][$type] = $definition->toArray();

		}
//dd($md['transformers']);
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
}