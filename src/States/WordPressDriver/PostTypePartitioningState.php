<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\Console\Prompts\Shared\GenericAutoPrompt;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\ImportModelMap;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\Concerns\AnalyzesValues;
use Crumbls\Importer\States\Concerns\StreamingAnalyzesValues;
use Crumbls\Importer\States\Concerns\HasStorageDriver;
use Crumbls\Importer\States\Concerns\HasSchemaAnalysis;
use Crumbls\Importer\Support\MemoryManager;
use Crumbls\Importer\Facades\Storage;
use Crumbls\StateMachine\State;
use Exception;
use Crumbls\Importer\States\MappingState as BaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PostTypePartitioningState extends AbstractState
{
	use HasStorageDriver;
	
	// Cache for consistent table names across method calls
	protected array $tableNameCache = [];

	/**
	 * Get the prompt class for viewing this state
	 */
	public function getPromptClass(): string
	{
		return GenericAutoPrompt::class;
	}

	public function onEnter() : void {
	}

	public function execute() : bool {
		// Get the storage driver instance
		$storage = $this->getStorageDriver();
		
		// Verify the storage driver has database functionality
		if (!method_exists($storage, 'db')) {
			throw new Exception('Storage driver does not support database connections.');
		}
		
		// Get the database connection from storage
		$connection = $storage->db();

		$postTypes = $this->getPostTypeNames();
		
		// Get the base posts table schema
		$postsSchema = $this->getPostsTableSchema();
		
		// Create partitioned tables for each post type
		foreach ($postTypes as $postType) {
			$this->createPostTypeTable($postType, $postsSchema, $connection);
		}
		
		// Create ImportModelMaps for each post type
		foreach ($postTypes as $postType) {
			$this->createImportModelMap($postType, $postsSchema, $connection);
		}
		
		// Migrate data to partitioned tables
		foreach ($postTypes as $postType) {
			$this->migrateDataToPostTypeTable($postType, $postsSchema, $connection);
		}

		$this->transitionToNextState($this->getRecord());

		return true;
	}

	public function onExit() : void {
	}

	protected function getPostTypeNames() : array {
		// Get the storage driver instance
		$storage = $this->getStorageDriver();

		// Get the database connection from storage
		$connection = $storage->db();

		return $connection->table('posts')
			->select('post_type')
			->orderBy('post_type')
			->distinct()
			->get()
			->pluck('post_type')
			->toArray();
	}
	
	protected function getPostsTableSchema(): array {
		$storage = $this->getStorageDriver();
		$connection = $storage->db();
		
		// Get posts table columns
		$columns = $this->getStorageColumns('posts');
		
		// Remove post_type column as we'll be partitioning by it
		// Also remove timestamp columns since we'll add them via timestamps()
		$columns = array_filter($columns, fn($column) => !in_array($column, [
			'post_type', 'created_at', 'updated_at'
		]));
		
		// Get column types using database introspection
		$schemaBuilder = $connection->getSchemaBuilder();
		$schema = [];
		
		foreach ($columns as $column) {
			$columnType = $schemaBuilder->getColumnType('posts', $column);
			$schema[$column] = $this->mapSqlTypeToLaravel($columnType);
		}
		
		return $schema;
	}
	
	protected function createPostTypeTable(string $postType, array $postsSchema, $connection): void {
		$tableName = $this->getPostTypeTableName($postType);
		$schemaBuilder = $connection->getSchemaBuilder();
		
		// Get meta fields for this post type
		$metaFields = $this->getMetaFieldsForPostType($postType);
		
		// Check if table already exists
		if ($schemaBuilder->hasTable($tableName)) {
			Log::info("Table {$tableName} already exists. Checking for schema differences...");
			
			// Compare current schema with desired schema
			$shouldRecreate = $this->shouldRecreateTable($tableName, $postsSchema, $metaFields, $connection);
			
			if ($shouldRecreate) {
				Log::info("Schema differences detected. Dropping and recreating table: {$tableName}");
				$schemaBuilder->dropIfExists($tableName);
			} else {
				Log::info("Table {$tableName} schema is up to date. Skipping creation.");
				return;
			}
		}
		
		// Create table schema combining posts columns + meta fields
		$schemaBuilder->create($tableName, function ($table) use ($postsSchema, $metaFields) {
			// Add ID column
			$table->id();
			
			// Add all posts columns (except post_type)
			foreach ($postsSchema as $column => $type) {
				$this->addColumnByType($table, $column, $type);
			}
			
			// Add meta fields as columns
			foreach ($metaFields as $metaKey => $metaType) {
				$this->addColumnByType($table, $metaKey, $metaType);
			}
			
			// Add timestamps
			$table->timestamps();
		});
		
		// Successfully created partitioned table
	}
	
	protected function getPostTypeTableName(string $postType): string {
		// Check cache first to ensure consistency
		if (isset($this->tableNameCache[$postType])) {
			return $this->tableNameCache[$postType];
		}
		
		// Clean table naming - simple pluralized post type names
		$sanitizedPostType = preg_replace('/[^a-zA-Z0-9_]/', '_', $postType);
		$sanitizedPostType = preg_replace('/_{2,}/', '_', $sanitizedPostType);
		$sanitizedPostType = trim($sanitizedPostType, '_');
		$sanitizedPostType = strtolower($sanitizedPostType);
		
		// Simple pluralized name with suffix to avoid overwriting source tables
		$tableName = Str::plural($sanitizedPostType) . '_partitioned';
		
		// Table naming: postType → plural → plural_partitioned
		
		// Cache the result
		$this->tableNameCache[$postType] = $tableName;
		return $tableName;
	}
	
	protected function getMetaFieldsForPostType(string $postType): array {
		$storage = $this->getStorageDriver();
		$connection = $storage->db();
		
		// Determine the correct ID column name
		$idColumn = $this->getPostsIdColumn();
		// Get all meta keys for this post type
		$metaKeys = $connection->table('postmeta')
			->join('posts', 'postmeta.post_id', '=', "posts.{$idColumn}")
			->where('posts.post_type', $postType)
			->select('postmeta.meta_key')
			->distinct()
			->get()
			->pluck('meta_key')
			->toArray();
		
		$metaSchema = [];
		
		// Analyze each meta field to determine appropriate data type
		foreach ($metaKeys as $metaKey) {
			$metaSchema[$metaKey] = $this->analyzeMetaFieldType($metaKey, $postType);
		}
		
		return $metaSchema;
	}
	
	protected function analyzeMetaFieldType(string $metaKey, string $postType): string {
		$storage = $this->getStorageDriver();
		$connection = $storage->db();
		
		// Determine the correct ID column name
		$idColumn = $this->getPostsIdColumn();
		
		// Sample meta values for this key and post type
		$sampleValues = $connection->table('postmeta')
			->join('posts', 'postmeta.post_id', '=', "posts.{$idColumn}")
			->where('posts.post_type', $postType)
			->where('postmeta.meta_key', $metaKey)
			->whereNotNull('postmeta.meta_value')
			->where('postmeta.meta_value', '!=', '')
			->limit(100)
			->get()
			->pluck('meta_value')
			->toArray();
		
		if (empty($sampleValues)) {
			return 'text';
		}
		
		// Analyze the values to determine the best data type
		$allNumeric = true;
		$allInteger = true;
		$allBoolean = true;
		$allDate = true;
		
		foreach ($sampleValues as $value) {
			if (!is_numeric($value)) {
				$allNumeric = false;
				$allInteger = false;
			} elseif (!ctype_digit(ltrim($value, '-'))) {
				$allInteger = false;
			}
			
			if (!in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no'])) {
				$allBoolean = false;
			}
			
			if (!strtotime($value)) {
				$allDate = false;
			}
		}
		
		// Determine best type based on analysis
		if ($allBoolean) {
			return 'boolean';
		} elseif ($allInteger) {
			return 'integer';
		} elseif ($allNumeric) {
			return 'decimal';
		} elseif ($allDate) {
			return 'datetime';
		} else {
			// Check if values are generally short (likely varchar) or long (text)
			$avgLength = array_sum(array_map('strlen', $sampleValues)) / count($sampleValues);
			return $avgLength > 255 ? 'text' : 'string';
		}
	}
	
	protected function addColumnByType($table, string $columnName, string $type): void {
		match($type) {
			'boolean' => $table->boolean($columnName)->nullable(),
			'integer' => $table->integer($columnName)->nullable(),
			'bigint' => $table->bigInteger($columnName)->nullable(),
			'decimal' => $table->decimal($columnName, 10, 2)->nullable(),
			'datetime' => $table->datetime($columnName)->nullable(),
			'date' => $table->date($columnName)->nullable(),
			'text' => $table->text($columnName)->nullable(),
			'json' => $table->json($columnName)->nullable(),
			default => $table->string($columnName)->nullable(),
		};
	}
	
	protected function mapSqlTypeToLaravel(string $sqlType): string {
		return match(strtolower($sqlType)) {
			'int', 'integer' => 'integer',
			'bigint' => 'bigint',
			'tinyint' => 'boolean',
			'decimal', 'numeric', 'float', 'double' => 'decimal',
			'datetime', 'timestamp' => 'datetime',
			'date' => 'date',
			'text', 'longtext', 'mediumtext' => 'text',
			'json' => 'json',
			default => 'string',
		};
	}
	
	protected function getPostsIdColumn(): string {
		$storage = $this->getStorageDriver();
		$columns = $this->getStorageColumns('posts');
		
		// Check for common WordPress ID column variations
		if (in_array('ID', $columns)) {
			return 'ID';
		} elseif (in_array('post_id', $columns)) {
			return 'post_id';
		} elseif (in_array('id', $columns)) {
			return 'id';
		}
		
		// Fallback - throw descriptive error
		throw new Exception('Unable to determine posts table ID column. Available columns: ' . implode(', ', $columns));
	}
	
	protected function createImportModelMap(string $postType, array $postsSchema, $connection): void
	{
		$tableName = $this->getPostTypeTableName($postType);
		$metaFields = $this->getMetaFieldsForPostType($postType);
		$record = $this->getRecord();
		
		// Creating ImportModelMap for post type
		
		// Check if ImportModelMap already exists for this entity
		$existingMap = ImportModelMap::where('import_id', $record->id)
			->where('entity_type', $postType)
			->where('source_table', 'posts')
			->first();
		
		if ($existingMap) {
			// Updating existing ImportModelMap
			$modelMap = $existingMap;
		} else {
			// Creating new ImportModelMap
			$modelMap = new ImportModelMap();
		}
		
		// Build the comprehensive ImportModelMap structure
		$modelMap->fill([
			'import_id' => $record->id,
			'entity_type' => $postType,
			'source_table' => 'posts',
			'source_type' => 'post_type',
			'target_model' => $this->generateModelName($postType),
			'target_table' => $tableName,
			'driver' => 'WordPressDriver',
			'is_active' => true,
			'priority' => 100
		]);
		
		// Set source information (WordPress-specific)
		$modelMap->setSourceInfo([
			'original_identifier' => $postType,
			'source_tables' => ['posts', 'postmeta'],
			'entity_count' => $this->getPostTypeCount($postType),
			'driver_metadata' => [
				'post_type' => $postType,
				'meta_field_count' => count($metaFields),
				'uses_meta_fields' => !empty($metaFields),
				'hierarchical' => $this->isHierarchicalPostType($postType)
			]
		]);
		
		// Set destination information
		$modelMap->setDestinationInfo([
			'table_name' => $tableName,
			'model_name' => $this->generateModelName($postType),
			'namespace' => 'App\\Models',
			'migration_name' => 'create_' . $tableName . '_table',
			'file_path' => 'app/Models/' . $this->generateModelName($postType) . '.php'
		]);
		
		// Build comprehensive schema mapping
		$schemaMapping = $this->buildSchemaMapping($postsSchema, $metaFields);
		$modelMap->setSchemaMapping($schemaMapping);
		
		// Detect and set relationships
		$relationships = $this->detectRelationships($postType, $postsSchema);
		$modelMap->setRelationships($relationships);
		
		// Set conflict resolution strategy
		$conflictResolution = $this->buildConflictResolution($postType);
		$modelMap->setConflictResolution($conflictResolution);
		
		// Set data validation rules
		$dataValidation = $this->buildDataValidation($postsSchema, $metaFields);
		$modelMap->setDataValidation($dataValidation);
		
		// Set model metadata
		$modelMetadata = $this->buildModelMetadata($postType, $postsSchema, $metaFields);
		$modelMap->setModelMetadata($modelMetadata);
		
		// Set performance configuration
		$performanceConfig = $this->buildPerformanceConfig($postType);
		$modelMap->setPerformanceConfig($performanceConfig);
		
		// Set migration metadata
		$migrationMetadata = $this->buildMigrationMetadata($tableName);
		$modelMap->setMigrationMetadata($migrationMetadata);
		
		// Save the ImportModelMap
		$modelMap->save();
		
		// ImportModelMap created/updated successfully
	}
	
	protected function shouldRecreateTable(string $tableName, array $postsSchema, array $metaFields, $connection): bool {
		// Get current table columns
		$currentColumns = $this->getStorageColumns($tableName);
		
		// Build expected columns list
		$expectedColumns = ['id']; // Primary key
		$expectedColumns = array_merge($expectedColumns, array_keys($postsSchema));
		$expectedColumns = array_merge($expectedColumns, array_keys($metaFields));
		$expectedColumns[] = 'created_at';
		$expectedColumns[] = 'updated_at';
		
		// Sort both arrays for comparison
		sort($currentColumns);
		sort($expectedColumns);
		
		// Check if columns match
		$columnsDiffer = $currentColumns !== $expectedColumns;
		
		if ($columnsDiffer) {
			Log::info("Column differences detected for {$tableName}");
			Log::info("Current columns: " . implode(', ', $currentColumns));
			Log::info("Expected columns: " . implode(', ', $expectedColumns));
			return true;
		}
		
		// TODO: Could also check column types if needed for more granular comparison
		
		return false;
	}
	
	protected function migrateDataToPostTypeTable(string $postType, array $postsSchema, $connection): void {
		$tableName = $this->getPostTypeTableName($postType);
		$idColumn = $this->getPostsIdColumn();
		
		// Starting data migration
		
		// Get posts for this post type
		$posts = $connection->table('posts')
			->where('post_type', $postType)
			->get();
		
		if ($posts->isEmpty()) {
			// No posts found for this post type
			return;
		}
		
		$insertedCount = 0;
		$skippedCount = 0;
		
		foreach ($posts as $post) {
			$postId = $post->{$idColumn};
			
			// Check if record already exists (using original post ID)
			$existingRecord = $connection->table($tableName)
				->where($idColumn, $postId)
				->first();
			
			if ($existingRecord) {
				// Record already exists, skipping
				$skippedCount++;
				continue;
			}
			
			// Build the record data
			$recordData = $this->buildPostRecord($post, $postType, $postsSchema, $connection);
			
			// Insert the record
			$connection->table($tableName)->insert($recordData);
			$insertedCount++;
		}
		
		// Data migration completed
	}
	
	protected function buildPostRecord($post, string $postType, array $postsSchema, $connection): array {
		$idColumn = $this->getPostsIdColumn();
		$postId = $post->{$idColumn};
		
		// Start with post data (excluding post_type)
		$recordData = [];
		
		// Add post columns
		foreach (array_keys($postsSchema) as $column) {
			$recordData[$column] = $post->{$column} ?? null;
		}
		
		// Get meta data for this post
		$metaData = $connection->table('postmeta')
			->where('post_id', $postId)
			->get()
			->keyBy('meta_key')
			->map(fn($meta) => $meta->meta_value);
		
		// Add meta fields as columns
		$metaFields = $this->getMetaFieldsForPostType($postType);
		foreach (array_keys($metaFields) as $metaKey) {
			$recordData[$metaKey] = $metaData[$metaKey] ?? null;
		}
		
		// Add timestamps
		$recordData['created_at'] = now();
		$recordData['updated_at'] = now();
		
		return $recordData;
	}
	
	// =============================================
	// IMPORTMODELMAP BUILDER METHODS
	// =============================================
	
	protected function generateModelName(string $postType): string
	{
		// Convert post_type to PascalCase model name
		return Str::studly(Str::singular($postType));
	}
	
	protected function getPostTypeCount(string $postType): int
	{
		$storage = $this->getStorageDriver();
		$connection = $storage->db();
		
		return $connection->table('posts')
			->where('post_type', $postType)
			->count();
	}
	
	protected function isHierarchicalPostType(string $postType): bool
	{
		$storage = $this->getStorageDriver();
		$connection = $storage->db();
		
		// Check if any posts of this type have parent relationships
		$hasParents = $connection->table('posts')
			->where('post_type', $postType)
			->where('post_parent', '>', 0)
			->exists();
		
		return $hasParents;
	}
	
	protected function buildSchemaMapping(array $postsSchema, array $metaFields): array
	{
		$columns = [];
		
		// Add ID column
		$columns['id'] = [
			'source_identifier' => $this->getPostsIdColumn(),
			'source_context' => 'posts',
			'destination_column' => 'id',
			'laravel_column_type' => 'id',
			'parameters' => [],
			'constraints' => ['primary' => true],
			'nullable' => false,
			'source_type' => 'column'
		];
		
		// Add posts table columns
		foreach ($postsSchema as $column => $type) {
			$columns[$column] = [
				'source_identifier' => $column,
				'source_context' => 'posts',
				'destination_column' => $column,
				'laravel_column_type' => $type,
				'parameters' => $this->getColumnParameters($type),
				'constraints' => $this->getColumnConstraints($column, $type),
				'nullable' => $this->isColumnNullable($column),
				'source_type' => 'column'
			];
		}
		
		// Add meta fields as columns
		foreach ($metaFields as $metaKey => $metaType) {
			$columns[$metaKey] = [
				'source_identifier' => $metaKey,
				'source_context' => 'postmeta',
				'destination_column' => $metaKey,
				'laravel_column_type' => $metaType,
				'parameters' => $this->getColumnParameters($metaType),
				'constraints' => [],
				'nullable' => true,
				'source_type' => 'meta_field'
			];
		}
		
		return ['columns' => $columns];
	}
	
	protected function getColumnParameters(string $type): array
	{
		return match($type) {
			'decimal' => [10, 2],
			'string' => [255],
			default => []
		};
	}
	
	protected function getColumnConstraints(string $column, string $type): array
	{
		$constraints = [];
		
		// Add foreign key constraints for common WordPress fields
		if ($column === 'post_author') {
			$constraints['foreign'] = ['table' => 'users', 'column' => 'id'];
		}
		
		// Add indexes for commonly queried fields
		if (in_array($column, ['post_status', 'post_date', 'post_author'])) {
			$constraints['index'] = true;
		}
		
		return $constraints;
	}
	
	protected function isColumnNullable(string $column): bool
	{
		// WordPress core fields that are typically required
		$requiredFields = ['post_title', 'post_date', 'post_status'];
		return !in_array($column, $requiredFields);
	}
	
	protected function detectRelationships(string $postType, array $postsSchema): array
	{
		$relationships = [];
		
		// User relationship (post_author)
		if (isset($postsSchema['post_author'])) {
			$relationships['belongs_to']['user'] = [
				'foreign_key' => 'post_author',
				'related_model' => 'User',
				'related_table' => 'users',
				'source_mapping' => 'post_author'
			];
		}
		
		// Self-referencing parent relationship
		if (isset($postsSchema['post_parent']) && $this->isHierarchicalPostType($postType)) {
			$relationships['belongs_to']['parent'] = [
				'foreign_key' => 'post_parent',
				'related_model' => $this->generateModelName($postType),
				'related_table' => $this->getPostTypeTableName($postType),
				'source_mapping' => 'post_parent'
			];
			
			$relationships['has_many']['children'] = [
				'foreign_key' => 'post_parent',
				'related_model' => $this->generateModelName($postType),
				'related_table' => $this->getPostTypeTableName($postType)
			];
		}
		
		return $relationships;
	}
	
	protected function buildConflictResolution(string $postType): array
	{
		$modelName = $this->generateModelName($postType);
		$modelPath = "App\\Models\\{$modelName}";
		
		// Check if model already exists
		$modelExists = class_exists($modelPath);
		
		return [
			'strategy' => 'smart_extension', // Default strategy from config
			'conflict_detected' => $modelExists,
			'existing_model_info' => [
				'class_path' => $modelPath,
				'table_name' => $this->getPostTypeTableName($postType),
				'can_extend_safely' => !$modelExists, // Safe if doesn't exist
				'safety_score' => $modelExists ? 0.5 : 1.0
			],
			'extension_configuration' => [
				'preserve_existing_fillable' => true,
				'preserve_existing_relationships' => true,
				'add_import_metadata' => true,
				'create_backup' => true,
				'require_confirmation' => $modelExists
			]
		];
	}
	
	protected function buildDataValidation(array $postsSchema, array $metaFields): array
	{
		$rules = [];
		
		// WordPress post validation rules
		if (isset($postsSchema['post_title'])) {
			$rules['post_title'] = 'required|string|max:255';
		}
		
		if (isset($postsSchema['post_status'])) {
			$rules['post_status'] = 'required|string|in:publish,draft,private,pending';
		}
		
		if (isset($postsSchema['post_author'])) {
			$rules['post_author'] = 'required|integer|exists:users,id';
		}
		
		return [
			'required_fields' => ['post_title', 'post_status'],
			'validation_rules' => $rules,
			'data_cleaning_rules' => [
				'post_title' => 'trim|strip_tags',
				'post_content' => 'trim'
			]
		];
	}
	
	protected function buildModelMetadata(string $postType, array $postsSchema, array $metaFields): array
	{
		$fillable = array_keys($postsSchema);
		$fillable = array_merge($fillable, array_keys($metaFields));
		
		$casts = [];
		foreach ($metaFields as $metaKey => $metaType) {
			if ($metaType === 'decimal') {
				$casts[$metaKey] = 'decimal:2';
			} elseif ($metaType === 'datetime') {
				$casts[$metaKey] = 'datetime';
			} elseif ($metaType === 'boolean') {
				$casts[$metaKey] = 'boolean';
			}
		}
		
		return [
			'timestamps' => true,
			'fillable' => $fillable,
			'casts' => $casts,
			'traits' => ['HasImportTracking'],
			'interfaces' => []
		];
	}
	
	protected function buildPerformanceConfig(string $postType): array
	{
		$entityCount = $this->getPostTypeCount($postType);
		
		return [
			'batch_size' => min(1000, max(100, $entityCount / 10)),
			'use_transactions' => true,
			'chunk_processing' => $entityCount > 1000,
			'memory_limit' => '512M',
			'index_strategy' => [
				'indexes' => ['post_status', 'post_date', 'post_author'],
				'foreign_keys' => [
					'post_author' => ['users', 'id']
				]
			]
		];
	}
	
	protected function buildMigrationMetadata(string $tableName): array
	{
		return [
			'version' => date('Y_m_d_His'),
			'dependencies' => ['create_users_table'],
			'rollback_strategy' => 'drop_table',
			'create_separate_meta_table' => false, // Meta fields are flattened
			'relationship_migrations' => []
		];
	}
	
}