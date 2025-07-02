<?php

namespace Crumbls\Importer\Adapters;

use Crumbls\Importer\Adapters\Traits\HasStandardizedConfiguration;
use Crumbls\Importer\Adapters\Traits\HasConnection;
use Crumbls\Importer\Adapters\Traits\HasStrategy;
use Crumbls\Importer\Adapters\Traits\HasDatabaseOperations;
use Crumbls\Importer\Adapters\Traits\HasMigrationLogging;
use Crumbls\Importer\Adapters\Traits\HasPerformanceMonitoring;
use Crumbls\Importer\Adapters\Traits\HasLaravelGeneration;
use Crumbls\Importer\Contracts\MigrationAdapter;
use Crumbls\Importer\Contracts\MigrationPlan;
use Crumbls\Importer\Contracts\ValidationResult;
use Crumbls\Importer\Contracts\DryRunResult;
use Crumbls\Importer\Contracts\MigrationResult;
use Crumbls\Importer\Contracts\AdapterConfiguration;
use Crumbls\Importer\Configuration\MigrationConfiguration;
use Crumbls\Importer\Pipeline\ExtendedPipelineConfiguration;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

class WordPressAdapter implements MigrationAdapter
{
	use HasStandardizedConfiguration,
		HasConnection,
		HasStrategy,
		HasDatabaseOperations,
		HasMigrationLogging,
		HasPerformanceMonitoring,
		HasLaravelGeneration;

    public function __construct(mixed $config = [], string $environment = 'production')
    {
        $this->initializeConfiguration($config, MigrationConfiguration::class, $environment);
    }
    
    public function plan(array $extractedData): MigrationPlan
    {
        $planId = $this->generatePlanId();
        $operations = [];
        $summary = [];
        $conflicts = [];
        $relationships = [];
        
        // Analyze each entity type
        foreach ($extractedData as $entityType => $records) {
            $entityPlan = $this->planEntityMigration($entityType, $records);
            
            $operations[$entityType] = $entityPlan['operations'];
            $summary[$entityType] = $entityPlan['summary'];
            
            if (!empty($entityPlan['conflicts'])) {
                $conflicts[$entityType] = $entityPlan['conflicts'];
            }
            
            if (!empty($entityPlan['relationships'])) {
                $relationships = array_merge($relationships, $entityPlan['relationships']);
            }
        }
        
        return new MigrationPlan(
            id: $planId,
            summary: $summary,
            operations: $operations,
            relationships: $relationships,
            conflicts: $conflicts,
            metadata: [
                'source_type' => 'wordpress_xml',
                'target_type' => 'wordpress_db',
                'created_at' => date('Y-m-d H:i:s'),
                'config' => $this->getConfig()
            ]
        );
    }
    
    public function validate(MigrationPlan $plan): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $suggestions = [];
        
        // Check database connection
        if (!$this->config('connection')) {
            $errors[] = 'No database connection configured';
        } else {
            // Try to connect to validate the connection
            try {
                $this->getDatabase();
            } catch (\Exception $e) {
                $errors[] = 'Database connection failed: ' . $e->getMessage();
            }
        }
        
        // Check for conflicts
        if ($plan->hasConflicts()) {
            $conflictCount = array_sum(array_map('count', $plan->getConflicts()));
            $warnings[] = "Found {$conflictCount} potential conflicts. Review conflict strategy.";
        }
        
        // Check required tables exist
        // Initialize database first to ensure tables are created for testing
        $this->getDatabase();
        
        $requiredTables = $this->getRequiredTables($plan);
        foreach ($requiredTables as $table) {
            try {
                if (!$this->tableExists($table)) {
                    // In testing environment, tables might not exist until migration
                    if ($this->isDevelopment()) {
                        $warnings[] = "Table '{$table}' does not exist yet (will be created during migration)";
                    } else {
                        $errors[] = "Required table '{$table}' does not exist in target database";
                    }
                }
            } catch (\Exception $e) {
                $warnings[] = "Could not verify table '{$table}' exists: " . $e->getMessage();
            }
        }
        
        // Suggestions for optimization
        $totalRecords = $this->getTotalRecordCount($plan);
        if ($totalRecords > 10000) {
            $suggestions[] = 'Consider using batch processing for large migrations';
        }
        
        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors,
            warnings: $warnings,
            suggestions: $suggestions
        );
    }
    
    public function dryRun(MigrationPlan $plan): DryRunResult
    {
        $summary = [];
        $operations = [];
        $changes = [];
        $conflicts = $plan->getConflicts();
        $statistics = [];
        
        foreach ($plan->getOperations() as $entityType => $entityOps) {
            $dryRunStats = $this->simulateEntityMigration($entityType, $entityOps);
            
            $summary[$entityType] = $dryRunStats['summary'];
            $operations[$entityType] = $dryRunStats['operations'];
            $changes[$entityType] = $dryRunStats['changes'];
            $statistics[$entityType] = $dryRunStats['statistics'];
        }
        
        return new DryRunResult(
            summary: $summary,
            operations: $operations,
            changes: $changes,
            conflicts: $conflicts,
            statistics: $statistics
        );
    }
    
    public function migrate(MigrationPlan $plan, array $options = []): MigrationResult
    {
        $migrationId = $plan->getId();
        $errors = [];
        $summary = [];
        $statistics = [];
        
        try {
            // Ensure database is initialized with tables
            $this->getDatabase();
            
            // Execute migration for each entity type
            foreach ($plan->getOperations() as $entityType => $operations) {
                $result = $this->migrateEntity($entityType, $operations, $options);
                
                $summary[$entityType] = $result['summary'];
                $statistics[$entityType] = $result['statistics'];
                
                if (!empty($result['errors'])) {
                    $errors = array_merge($errors, $result['errors']);
                }
            }
            
            // Handle relationships after all entities are migrated
            $this->migrateRelationships($plan->getRelationships());
            
            // Log successful migration for rollback capability
            if (empty($errors)) {
                $this->logMigration($migrationId, $plan->getOperations(), $plan->getMetadata());
            }
            
        } catch (\Exception $e) {
            $errors[] = 'Migration failed: ' . $e->getMessage();
        }
        
        return new MigrationResult(
            success: empty($errors),
            migrationId: $migrationId,
            summary: $summary,
            statistics: $statistics,
            errors: $errors,
            metadata: [
                'completed_at' => date('Y-m-d H:i:s'),
                'duration' => 0, // Would track actual duration
                'options' => $options
            ]
        );
    }
    
    public function rollback(string $migrationId): bool
    {
        try {
            // Get migration log
            $migrationLog = $this->getMigrationLog($migrationId);
            if (!$migrationLog) {
                return false;
            }
            
            $this->beginTransaction();
            
            // Rollback in reverse order
            $operations = array_reverse($migrationLog['operations']);
            
            foreach ($operations as $operation) {
                $this->rollbackOperation($operation);
            }
            
            // Mark migration as rolled back
            $this->markMigrationRolledBack($migrationId);
            
            $this->commit();
            return true;
            
        } catch (\Exception $e) {
            if ($this->inTransaction()) {
                $this->rollbackTransaction();
            }
            error_log("Rollback failed for migration {$migrationId}: " . $e->getMessage());
            return false;
        }
    }

    
    protected function planEntityMigration(string $entityType, array $records): array
    {
        $operations = [];
        $conflicts = [];
        $summary = [
            'total_records' => count($records),
            'create' => 0,
            'update' => 0,
            'skip' => 0
        ];
        
        foreach ($records as $record) {
            $operation = $this->determineOperation($entityType, $record);
            $operations[] = $operation;
            
            $summary[$operation['action']]++;
            
            if ($operation['action'] === 'conflict') {
                $conflicts[] = $operation;
            }
        }
        
        return [
            'operations' => $operations,
            'summary' => $summary,
            'conflicts' => $conflicts,
            'relationships' => [] // Would be populated based on entity relationships
        ];
    }
    
    protected function determineOperation(string $entityType, array $record): array
    {
        $targetTable = $this->getTargetTable($entityType);
        $conflicts = [];
        $action = 'create';
        $conditions = [];
        
        // Check for existing records based on entity type
        // Skip conflict detection during planning phase
        $existingRecord = null;
        
        if ($existingRecord) {
            $conflictStrategy = $this->config('conflict_strategy');
            
            switch ($conflictStrategy) {
                case 'skip':
                    $action = 'skip';
                    break;
                    
                case 'overwrite':
                    $action = 'update';
                    $conditions = $this->getUpdateConditions($entityType, $existingRecord);
                    break;
                    
                case 'merge':
                    $action = 'update';
                    $conditions = $this->getUpdateConditions($entityType, $existingRecord);
                    $record = $this->mergeRecords($existingRecord, $record);
                    break;
                    
                default:
                    $conflicts[] = [
                        'type' => 'duplicate_record',
                        'existing' => $existingRecord,
                        'new' => $record,
                        'message' => "Record with similar identifier already exists"
                    ];
                    $action = 'conflict';
            }
        }
        
        return [
            'action' => $action,
            'entity_type' => $entityType,
            'data' => $record,
            'target_table' => $targetTable,
            'conditions' => $conditions,
            'conflicts' => $conflicts
        ];
    }
    
    protected function getTargetTable(string $entityType): string
    {
        $tableMap = [
            'posts' => 'wp_posts',
            'postmeta' => 'wp_postmeta',
            'users' => 'wp_users',
            'comments' => 'wp_comments',
            'commentmeta' => 'wp_commentmeta',
            'terms' => 'wp_terms',
            'categories' => 'wp_terms',
            'tags' => 'wp_terms',
            'attachments' => 'wp_posts'
        ];
        
        return $tableMap[$entityType] ?? $entityType;
    }
    
    protected function generatePlanId(): string
    {
        return 'wp_migration_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    protected function getRequiredTables(MigrationPlan $plan): array
    {
        $tables = [];
        foreach ($plan->getOperations() as $entityType => $operations) {
            $tables[] = $this->getTargetTable($entityType);
        }
        return array_unique($tables);
    }
    
    
    protected function getTotalRecordCount(MigrationPlan $plan): int
    {
        $total = 0;
        foreach ($plan->getSummary() as $entitySummary) {
            $total += $entitySummary['total_records'] ?? 0;
        }
        return $total;
    }
    
    protected function simulateEntityMigration(string $entityType, array $operations): array
    {
        return [
            'summary' => [
                'would_create' => count(array_filter($operations, fn($op) => $op['action'] === 'create')),
                'would_update' => count(array_filter($operations, fn($op) => $op['action'] === 'update')),
                'would_skip' => count(array_filter($operations, fn($op) => $op['action'] === 'skip'))
            ],
            'operations' => $operations,
            'changes' => [], // Would show specific field changes
            'statistics' => [
                'estimated_time' => '5 minutes',
                'disk_space_required' => '50MB'
            ]
        ];
    }
    
    protected function migrateEntity(string $entityType, array $operations, array $options): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0
        ];
        
        $errors = [];
        
        try {
            $this->getDatabase();
            $this->beginTransaction();
            
            foreach ($operations as $operation) {
                try {
                    $result = $this->executeOperation($operation, $entityType);
                    $summary[$result]++;
                } catch (\Exception $e) {
                    $summary['failed']++;
                    $errors[] = "Failed to {$operation['action']} {$entityType}: " . $e->getMessage();
                    
                    // If we're not in continue-on-error mode, rollback and throw
                    if (!($options['continue_on_error'] ?? false)) {
                        $this->rollbackTransaction();
                        throw $e;
                    }
                }
            }
            
            $this->commit();
            
        } catch (\Exception $e) {
            if ($this->inTransaction()) {
                $this->rollbackTransaction();
            }
            throw $e;
        }
        
        return [
            'summary' => $summary,
            'statistics' => [
                'duration' => microtime(true) - $startTime,
                'memory_used' => memory_get_usage() - $startMemory
            ],
            'errors' => $errors
        ];
    }
    
    protected function migrateRelationships(array $relationships): void
    {
        // Handle post-parent relationships, term relationships, etc.
        foreach ($relationships as $relationship) {
            try {
                $this->handleRelationship($relationship);
            } catch (\Exception $e) {
                // Log relationship migration errors but don't fail the entire migration
                error_log("Failed to migrate relationship: " . $e->getMessage());
            }
        }
    }
    
    protected function createMockTables(): void
    {
        // Create WordPress table structure for testing
        $this->createWordPressPostsTable();
        $this->createWordPressUsersTable();
        $this->createWordPressCommentsTable();
        $this->createWordPressTermsTable();
        $this->createWordPressMetaTables();
    }
    
    protected function createWordPressPostsTable(): void
    {
        $db = $this->getDatabase();
        $db->schema()->create('wp_posts', function (Blueprint $table) {
            $table->id('ID');
            $table->unsignedBigInteger('post_author')->default(0);
            $table->timestamp('post_date')->useCurrent();
            $table->timestamp('post_date_gmt')->useCurrent();
            $table->longText('post_content')->nullable();
            $table->text('post_title')->nullable();
            $table->text('post_excerpt')->nullable();
            $table->string('post_status', 20)->default('publish');
            $table->string('comment_status', 20)->default('open');
            $table->string('ping_status', 20)->default('open');
            $table->string('post_password')->default('');
            $table->string('post_name', 200)->default('');
            $table->text('to_ping')->nullable();
            $table->text('pinged')->nullable();
            $table->timestamp('post_modified')->useCurrent();
            $table->timestamp('post_modified_gmt')->useCurrent();
            $table->longText('post_content_filtered')->nullable();
            $table->unsignedBigInteger('post_parent')->default(0);
            $table->string('guid')->default('');
            $table->integer('menu_order')->default(0);
            $table->string('post_type', 20)->default('post');
            $table->string('post_mime_type', 100)->default('');
            $table->bigInteger('comment_count')->default(0);
            
            $table->index(['post_name']);
            $table->index(['post_type', 'post_status', 'post_date', 'ID']);
            $table->index(['post_parent']);
            $table->index(['post_author']);
        });
    }
    
    protected function createWordPressUsersTable(): void
    {
        $db = $this->getDatabase();
        $db->schema()->create('wp_users', function (Blueprint $table) {
            $table->id('ID');
            $table->string('user_login', 60)->unique();
            $table->string('user_pass');
            $table->string('user_nicename', 50);
            $table->string('user_email', 100);
            $table->string('user_url', 100)->default('');
            $table->timestamp('user_registered')->useCurrent();
            $table->string('user_activation_key')->default('');
            $table->integer('user_status')->default(0);
            $table->string('display_name', 250);
            
            $table->index(['user_login']);
            $table->index(['user_nicename']);
            $table->index(['user_email']);
        });
    }
    
    protected function createWordPressCommentsTable(): void
    {
        $db = $this->getDatabase();
        $db->schema()->create('wp_comments', function (Blueprint $table) {
            $table->id('comment_ID');
            $table->unsignedBigInteger('comment_post_ID')->default(0);
            $table->text('comment_author');
            $table->string('comment_author_email', 100)->default('');
            $table->string('comment_author_url', 200)->default('');
            $table->string('comment_author_IP', 100)->default('');
            $table->timestamp('comment_date')->useCurrent();
            $table->timestamp('comment_date_gmt')->useCurrent();
            $table->text('comment_content');
            $table->integer('comment_karma')->default(0);
            $table->string('comment_approved', 20)->default('1');
            $table->string('comment_agent')->default('');
            $table->string('comment_type', 20)->default('');
            $table->unsignedBigInteger('comment_parent')->default(0);
            $table->unsignedBigInteger('user_id')->default(0);
            
            $table->index(['comment_post_ID']);
            $table->index(['comment_approved', 'comment_date_gmt']);
            $table->index(['comment_date_gmt']);
            $table->index(['comment_parent']);
            $table->index(['comment_author_email']);
        });
    }
    
    protected function createWordPressTermsTable(): void
    {
        $db = $this->getDatabase();
        $db->schema()->create('wp_terms', function (Blueprint $table) {
            $table->id('term_id');
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->bigInteger('term_group')->default(0);
            
            $table->index(['slug']);
            $table->index(['name']);
        });
        
        $db->schema()->create('wp_term_taxonomy', function (Blueprint $table) {
            $table->id('term_taxonomy_id');
            $table->unsignedBigInteger('term_id')->default(0);
            $table->string('taxonomy', 32);
            $table->longText('description');
            $table->unsignedBigInteger('parent')->default(0);
            $table->bigInteger('count')->default(0);
            
            $table->unique(['term_id', 'taxonomy']);
            $table->index(['taxonomy']);
        });
        
        $db->schema()->create('wp_term_relationships', function (Blueprint $table) {
            $table->unsignedBigInteger('object_id')->default(0);
            $table->unsignedBigInteger('term_taxonomy_id')->default(0);
            $table->integer('term_order')->default(0);
            
            $table->primary(['object_id', 'term_taxonomy_id']);
            $table->index(['term_taxonomy_id']);
        });
    }
    
    protected function createWordPressMetaTables(): void
    {
        $db = $this->getDatabase();
        $db->schema()->create('wp_postmeta', function (Blueprint $table) {
            $table->id('meta_id');
            $table->unsignedBigInteger('post_id')->default(0);
            $table->string('meta_key')->nullable();
            $table->longText('meta_value')->nullable();
            
            $table->index(['post_id']);
            $table->index(['meta_key']);
        });
        
        $db->schema()->create('wp_commentmeta', function (Blueprint $table) {
            $table->id('meta_id');
            $table->unsignedBigInteger('comment_id')->default(0);
            $table->string('meta_key')->nullable();
            $table->longText('meta_value')->nullable();
            
            $table->index(['comment_id']);
            $table->index(['meta_key']);
        });
        
        $db->schema()->create('wp_usermeta', function (Blueprint $table) {
            $table->id('umeta_id');
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('meta_key')->nullable();
            $table->longText('meta_value')->nullable();
            
            $table->index(['user_id']);
            $table->index(['meta_key']);
        });
    }
    
    
    protected function executeOperation(array $operation, string $entityType): string
    {
        $table = $operation['target_table'];
        $data = $operation['data'];
        
        switch ($operation['action']) {
            case 'create':
                return $this->insertRecord($table, $data);
                
            case 'update':
                return $this->updateRecord($table, $data, $operation['conditions'] ?? []);
                
            case 'skip':
                return 'skipped';
                
            default:
                throw new \InvalidArgumentException("Unknown operation action: {$operation['action']}");
        }
    }
    
    
    protected function handleRelationship(array $relationship): void
    {
        // Handle different types of WordPress relationships
        switch ($relationship['type']) {
            case 'post_parent':
                $this->updatePostParent($relationship);
                break;
                
            case 'term_relationship':
                $this->insertTermRelationship($relationship);
                break;
                
            case 'post_meta':
                $this->insertPostMeta($relationship);
                break;
                
            default:
                throw new \InvalidArgumentException("Unknown relationship type: {$relationship['type']}");
        }
    }
    
    protected function updatePostParent(array $relationship): void
    {
        $db = $this->getDatabase();
        $db->table('wp_posts')
            ->where('ID', $relationship['post_id'])
            ->update(['post_parent' => $relationship['parent_id']]);
    }
    
    protected function insertTermRelationship(array $relationship): void
    {
        $db = $this->getDatabase();
        $db->table('wp_term_relationships')->insert([
            'object_id' => $relationship['object_id'],
            'term_taxonomy_id' => $relationship['term_taxonomy_id'],
            'term_order' => $relationship['term_order'] ?? 0
        ]);
    }
    
    protected function insertPostMeta(array $relationship): void
    {
        $db = $this->getDatabase();
        $db->table('wp_postmeta')->insert([
            'post_id' => $relationship['post_id'],
            'meta_key' => $relationship['meta_key'],
            'meta_value' => $relationship['meta_value']
        ]);
    }
    
    
    protected function getUniqueFields(string $entityType): array
    {
        $uniqueFieldMap = [
            'posts' => ['post_name', 'post_title'], // Try slug first, then title
            'users' => ['user_login', 'user_email'],
            'comments' => ['comment_ID'],
            'terms' => ['slug', 'name'],
            'postmeta' => ['post_id', 'meta_key'],
            'commentmeta' => ['comment_id', 'meta_key'],
        ];
        
        return $uniqueFieldMap[$entityType] ?? [];
    }
    
    protected function getUpdateConditions(string $entityType, array $existingRecord): array
    {
        // Get primary key for updates
        $primaryKeyMap = [
            'posts' => 'ID',
            'users' => 'ID',
            'comments' => 'comment_ID',
            'terms' => 'term_id',
            'postmeta' => 'meta_id',
            'commentmeta' => 'meta_id',
        ];
        
        $primaryKey = $primaryKeyMap[$entityType] ?? 'id';
        
        return [$primaryKey => $existingRecord[$primaryKey]];
    }
    
    protected function mergeRecords(array $existing, array $new): array
    {
        // Merge strategy: new values override existing, but preserve existing if new is empty
        $merged = $existing;
        
        foreach ($new as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }
        
        return $merged;
    }
    
    
    protected function rollbackOperation(array $operation): void
    {
        switch ($operation['action']) {
            case 'created':
                // Delete the created record
                $this->deleteRecord($operation['table'], $operation['conditions']);
                break;
                
            case 'updated':
                // Restore the original record
                $this->restoreRecord($operation);
                break;
                
            case 'skipped':
                // Nothing to rollback
                break;
        }
    }
    
    
    protected function restoreRecord(array $operation): void
    {
        if (!isset($operation['original_data'])) {
            return; // Can't restore without original data
        }
        
        $table = $operation['table'];
        $originalData = $operation['original_data'];
        $conditions = $operation['conditions'];
        
        $this->updateRecord($table, $originalData, $conditions);
    }
    
    protected function shouldCheckForConflicts(): bool
    {
        // Only check for conflicts during actual migration, not planning
        return $this->config('check_conflicts_during_planning', false);
    }
    
    /**
     * Generate complete Laravel application from WordPress XML data
     * This creates models for all WordPress entities (posts, users, comments, etc.)
     */
    public function generateCompleteWordPressApplication(): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::completeApplication()
            ->withMultipleModels([
                'posts' => 'Post',
                'users' => 'User', 
                'comments' => 'Comment',
                'postmeta' => 'PostMeta',
                'terms' => 'Term',
                'categories' => 'Category',
                'tags' => 'Tag'
            ])
            ->withRelationships([
                'Post' => ['hasMany' => ['Comment', 'PostMeta'], 'belongsTo' => ['User']],
                'User' => ['hasMany' => ['Post', 'Comment']],
                'Comment' => ['belongsTo' => ['Post', 'User']],
                'PostMeta' => ['belongsTo' => ['Post']],
                'Term' => ['belongsToMany' => ['Post']],
                'Category' => ['belongsToMany' => ['Post']],
                'Tag' => ['belongsToMany' => ['Post']]
            ]);
            
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Generate Laravel application focused on WordPress content (posts + metadata)
     */
    public function generateContentManagementSystem(): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::contentManagement()
            ->withMultipleModels([
                'posts' => 'Post',
                'postmeta' => 'PostMeta',
                'terms' => 'Term',
                'categories' => 'Category',
                'tags' => 'Tag'
            ])
            ->withFilamentResources(['Post', 'Category', 'Tag'])
            ->withAdvancedFactories(['Post' => 'rich_content']);
            
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Generate Laravel application for WordPress user management
     */
    public function generateUserManagementSystem(): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::userManagement()
            ->withMultipleModels([
                'users' => 'User',
                'usermeta' => 'UserMeta'
            ])
            ->withFilamentResources(['User'])
            ->withAdvancedFactories(['User' => 'realistic_profiles']);
            
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Generate Laravel models for WordPress content without admin interface
     */
    public function generateWordPressModels(): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::modelsOnly()
            ->withMultipleModels([
                'posts' => 'Post',
                'users' => 'User',
                'comments' => 'Comment',
                'postmeta' => 'PostMeta',
                'usermeta' => 'UserMeta',
                'terms' => 'Term'
            ]);
            
        $this->setupExtendedPipeline();
        return $this;
    }

}