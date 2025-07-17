<?php

namespace Crumbls\Importer\Services\WordPress;

use Crumbls\Importer\Services\ModelGenerator;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Support\Str;

class WordPressModelGenerator extends ModelGenerator
{
    protected WordPressColumnMapper $columnMapper;
    protected WordPressRelationshipDetector $relationshipDetector;
    
    public function __construct()
    {
        parent::__construct();
        $this->columnMapper = new WordPressColumnMapper();
        $this->relationshipDetector = new WordPressRelationshipDetector();
    }
    
    /**
     * Create a WordPress-optimized Laravel model
     */
    public function createWordPressModel(array $config): array
    {
        $postType = $config['post_type'];
        $analysisData = $config['analysis_data'] ?? [];
        
        // Generate smart WordPress-to-Laravel mappings
        $laravelConfig = $this->transformWordPressConfig($config, $analysisData);
        
        return $this->createModel($laravelConfig);
    }
    
    protected function transformWordPressConfig(array $config, array $analysisData): array
    {
        $postType = $config['post_type'];
        $modelName = $config['model_name'] ?? Str::studly(Str::singular($postType));
        
        // Smart table naming
        $tableName = $this->getSmartTableName($postType);
        
        // Generate WordPress-aware columns
        $columns = $this->generateWordPressColumns($postType, $analysisData);
        
        // Detect WordPress relationships
        $relationships = $this->relationshipDetector->detectRelationships($postType, $analysisData);
        
        // Generate smart fillable fields
        $fillable = $this->generateWordPressFillable($columns);
        
        // Generate smart casts
        $casts = $this->generateWordPressCasts($columns);
        
        return [
            'name' => $modelName,
            'table' => $tableName,
            'namespace' => $config['namespace'] ?? 'App\\Models',
            'fillable' => $fillable,
            'casts' => $casts,
            'columns' => $columns,
            'relationships' => $relationships,
            'create_migration' => $config['create_migration'] ?? true,
            'create_factory' => $config['create_factory'] ?? false,
            'wordpress_post_type' => $postType,
        ];
    }
    
    protected function getSmartTableName(string $postType): string
    {
        // Smart WordPress post type to Laravel table mapping
        $mappings = [
            'post' => 'posts',
            'page' => 'pages', 
            'attachment' => 'media', // More Laravel-like
            'nav_menu_item' => 'menu_items',
            'custom_css' => 'custom_css',
            'customize_changeset' => 'changesets',
            'oembed_cache' => 'oembed_cache',
            'user_request' => 'user_requests',
            'wp_block' => 'blocks',
            'wp_template' => 'templates',
            'wp_template_part' => 'template_parts',
            'wp_global_styles' => 'global_styles',
            'wp_navigation' => 'navigations',
        ];
        
        if (isset($mappings[$postType])) {
            return $mappings[$postType];
        }
        
        // Default to Laravel convention
        return Str::snake(Str::plural($postType));
    }
    
    protected function generateWordPressColumns(string $postType, array $analysisData): array
    {
        // Start with base WordPress post columns
        $columns = $this->getBaseWordPressColumns();
        
        // Add post-type specific columns
        $specificColumns = $this->getPostTypeSpecificColumns($postType);
        $columns = array_merge($columns, $specificColumns);
        
        // Add meta fields as actual columns (for commonly used ones)
        $metaColumns = $this->convertMetaToColumns($postType, $analysisData);
        $columns = array_merge($columns, $metaColumns);
        
        return $columns;
    }
    
    protected function getBaseWordPressColumns(): array
    {
        return [
            [
                'name' => 'id',
                'type' => 'id',
                'comment' => 'Laravel primary key',
            ],
            [
                'name' => 'wordpress_id',
                'type' => 'unsignedBigInteger',
                'nullable' => false,
                'unique' => true,
                'wordpress_field' => 'post_id',
                'comment' => 'Original WordPress post ID for syncing',
                'index' => true,
            ],
            [
                'name' => 'title',
                'type' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => '',
                'wordpress_field' => 'post_title',
                'fillable' => true,
            ],
            [
                'name' => 'content',
                'type' => 'longText',
                'nullable' => true,
                'wordpress_field' => 'post_content',
                'fillable' => true,
            ],
            [
                'name' => 'excerpt',
                'type' => 'text',
                'nullable' => true,
                'wordpress_field' => 'post_excerpt',
                'fillable' => true,
            ],
            [
                'name' => 'status',
                'type' => 'string',
                'length' => 20,
                'nullable' => false,
                'default' => 'draft',
                'wordpress_field' => 'post_status',
                'fillable' => true,
                'index' => true,
            ],
            [
                'name' => 'slug',
                'type' => 'string',
                'length' => 200,
                'nullable' => false,
                'wordpress_field' => 'post_name',
                'fillable' => true,
                'unique' => true,
            ],
            [
                'name' => 'published_at',
                'type' => 'datetime',
                'nullable' => true,
                'wordpress_field' => 'post_date',
                'fillable' => true,
                'cast' => 'datetime',
                'index' => true,
            ],
            [
                'name' => 'published_at_gmt',
                'type' => 'datetime',
                'nullable' => true,
                'wordpress_field' => 'post_date_gmt',
                'cast' => 'datetime',
            ],
            [
                'name' => 'modified_at',
                'type' => 'datetime',
                'nullable' => true,
                'wordpress_field' => 'post_modified',
                'cast' => 'datetime',
            ],
            [
                'name' => 'modified_at_gmt',
                'type' => 'datetime',
                'nullable' => true,
                'wordpress_field' => 'post_modified_gmt',
                'cast' => 'datetime',
            ],
            [
                'name' => 'author_id',
                'type' => 'unsignedBigInteger',
                'nullable' => true,
                'wordpress_field' => 'post_author',
                'fillable' => true,
                'foreign_key' => 'users.id',
                'index' => true,
            ],
            [
                'name' => 'parent_id',
                'type' => 'unsignedBigInteger',
                'nullable' => true,
                'wordpress_field' => 'post_parent',
                'fillable' => true,
                'self_reference' => true,
                'index' => true,
            ],
            [
                'name' => 'menu_order',
                'type' => 'integer',
                'nullable' => false,
                'default' => 0,
                'wordpress_field' => 'menu_order',
                'fillable' => true,
            ],
            [
                'name' => 'guid',
                'type' => 'string',
                'length' => 255,
                'nullable' => true,
                'wordpress_field' => 'guid',
            ],
            [
                'name' => 'comment_status',
                'type' => 'string',
                'length' => 20,
                'nullable' => false,
                'default' => 'open',
                'wordpress_field' => 'comment_status',
                'fillable' => true,
            ],
            [
                'name' => 'ping_status',
                'type' => 'string',
                'length' => 20,
                'nullable' => false,
                'default' => 'open',
                'wordpress_field' => 'ping_status',
                'fillable' => true,
            ],
            [
                'name' => 'comment_count',
                'type' => 'integer',
                'nullable' => false,
                'default' => 0,
                'wordpress_field' => 'comment_count',
            ],
        ];
    }
    
    protected function getPostTypeSpecificColumns(string $postType): array
    {
        return match($postType) {
            'attachment' => $this->getAttachmentColumns(),
            'nav_menu_item' => $this->getMenuItemColumns(),
            'product' => $this->getProductColumns(),
            'event' => $this->getEventColumns(),
            default => []
        };
    }
    
    protected function getAttachmentColumns(): array
    {
        return [
            [
                'name' => 'file_path',
                'type' => 'string',
                'length' => 500,
                'nullable' => true,
                'wordpress_meta' => '_wp_attached_file',
                'fillable' => true,
            ],
            [
                'name' => 'file_size',
                'type' => 'unsignedBigInteger',
                'nullable' => true,
                'wordpress_meta' => '_wp_attachment_metadata.filesize',
                'cast' => 'integer',
            ],
            [
                'name' => 'mime_type',
                'type' => 'string',
                'length' => 100,
                'nullable' => true,
                'wordpress_field' => 'post_mime_type',
                'fillable' => true,
                'index' => true,
            ],
            [
                'name' => 'alt_text',
                'type' => 'string',
                'length' => 255,
                'nullable' => true,
                'wordpress_meta' => '_wp_attachment_image_alt',
                'fillable' => true,
            ],
            [
                'name' => 'metadata',
                'type' => 'json',
                'nullable' => true,
                'wordpress_meta' => '_wp_attachment_metadata',
                'cast' => 'array',
            ],
        ];
    }
    
    protected function getMenuItemColumns(): array
    {
        return [
            [
                'name' => 'menu_item_parent',
                'type' => 'unsignedBigInteger',
                'nullable' => true,
                'wordpress_meta' => '_menu_item_menu_item_parent',
                'index' => true,
            ],
            [
                'name' => 'object_type',
                'type' => 'string',
                'length' => 100,
                'nullable' => true,
                'wordpress_meta' => '_menu_item_type',
                'fillable' => true,
            ],
            [
                'name' => 'object_id',
                'type' => 'unsignedBigInteger',
                'nullable' => true,
                'wordpress_meta' => '_menu_item_object_id',
            ],
            [
                'name' => 'url',
                'type' => 'string',
                'length' => 500,
                'nullable' => true,
                'wordpress_meta' => '_menu_item_url',
                'fillable' => true,
            ],
            [
                'name' => 'target',
                'type' => 'string',
                'length' => 50,
                'nullable' => true,
                'wordpress_meta' => '_menu_item_target',
                'fillable' => true,
            ],
        ];
    }
    
    protected function getProductColumns(): array
    {
        return [
            [
                'name' => 'sku',
                'type' => 'string',
                'length' => 100,
                'nullable' => true,
                'unique' => true,
                'wordpress_meta' => '_sku',
                'fillable' => true,
            ],
            [
                'name' => 'price',
                'type' => 'decimal',
                'precision' => 10,
                'scale' => 2,
                'nullable' => true,
                'wordpress_meta' => '_price',
                'fillable' => true,
                'cast' => 'decimal:2',
            ],
            [
                'name' => 'sale_price',
                'type' => 'decimal',
                'precision' => 10,
                'scale' => 2,
                'nullable' => true,
                'wordpress_meta' => '_sale_price',
                'fillable' => true,
                'cast' => 'decimal:2',
            ],
            [
                'name' => 'stock_quantity',
                'type' => 'integer',
                'nullable' => true,
                'wordpress_meta' => '_stock',
                'fillable' => true,
                'cast' => 'integer',
            ],
            [
                'name' => 'manage_stock',
                'type' => 'boolean',
                'nullable' => false,
                'default' => false,
                'wordpress_meta' => '_manage_stock',
                'fillable' => true,
                'cast' => 'boolean',
            ],
        ];
    }
    
    protected function getEventColumns(): array
    {
        return [
            [
                'name' => 'event_start',
                'type' => 'datetime',
                'nullable' => true,
                'wordpress_meta' => '_event_start_date',
                'fillable' => true,
                'cast' => 'datetime',
                'index' => true,
            ],
            [
                'name' => 'event_end',
                'type' => 'datetime',
                'nullable' => true,
                'wordpress_meta' => '_event_end_date',
                'fillable' => true,
                'cast' => 'datetime',
                'index' => true,
            ],
            [
                'name' => 'location',
                'type' => 'string',
                'length' => 255,
                'nullable' => true,
                'wordpress_meta' => '_event_location',
                'fillable' => true,
            ],
            [
                'name' => 'all_day',
                'type' => 'boolean',
                'nullable' => false,
                'default' => false,
                'wordpress_meta' => '_event_all_day',
                'fillable' => true,
                'cast' => 'boolean',
            ],
        ];
    }
    
    protected function convertMetaToColumns(string $postType, array $analysisData): array
    {
        $columns = [];
        $metaFields = $analysisData['meta_fields'] ?? [];
        
        // Get commonly used meta fields that should become actual columns
        $commonMetaFields = $this->getCommonMetaFields($postType);
        
        foreach ($metaFields as $meta) {
            $metaKey = $meta['field_name'];
            
            // Only convert high-usage, common meta fields to columns
            if ($this->shouldConvertMetaToColumn($metaKey, $meta, $commonMetaFields)) {
                $columns[] = $this->convertMetaFieldToColumn($metaKey, $meta);
            }
        }
        
        return $columns;
    }
    
    protected function getCommonMetaFields(string $postType): array
    {
        $common = [
            '_thumbnail_id' => 'featured_image_id',
            '_edit_last' => null, // Skip
            '_edit_lock' => null, // Skip
            '_wp_page_template' => 'page_template',
        ];
        
        return match($postType) {
            'product' => array_merge($common, [
                '_sku' => 'sku',
                '_price' => 'price',
                '_sale_price' => 'sale_price',
                '_stock' => 'stock_quantity',
                '_manage_stock' => 'manage_stock',
                '_visibility' => 'visibility',
                '_featured' => 'featured',
            ]),
            'attachment' => array_merge($common, [
                '_wp_attached_file' => 'file_path',
                '_wp_attachment_metadata' => 'metadata',
                '_wp_attachment_image_alt' => 'alt_text',
            ]),
            'event' => array_merge($common, [
                '_event_start_date' => 'event_start',
                '_event_end_date' => 'event_end',
                '_event_location' => 'location',
                '_event_all_day' => 'all_day',
            ]),
            default => $common,
        };
    }
    
    protected function shouldConvertMetaToColumn(string $metaKey, array $meta, array $commonMeta): bool
    {
        // Must be in common meta fields
        if (!isset($commonMeta[$metaKey])) {
            return false;
        }
        
        // Skip if mapped to null
        if ($commonMeta[$metaKey] === null) {
            return false;
        }
        
        // High confidence and usage
        return ($meta['confidence'] ?? 0) > 70;
    }
    
    protected function convertMetaFieldToColumn(string $metaKey, array $meta): array
    {
        $columnName = $this->columnMapper->mapMetaKeyToColumn($metaKey);
        $columnType = $this->columnMapper->mapMetaTypeToColumn($meta['type']);
        
        $column = [
            'name' => $columnName,
            'type' => $columnType['type'],
            'nullable' => true,
            'wordpress_meta' => $metaKey,
            'fillable' => true,
        ];
        
        // Add type-specific properties
        if (isset($columnType['cast'])) {
            $column['cast'] = $columnType['cast'];
        }
        
        if (isset($columnType['length'])) {
            $column['length'] = $columnType['length'];
        }
        
        if (isset($columnType['precision'])) {
            $column['precision'] = $columnType['precision'];
            $column['scale'] = $columnType['scale'] ?? 2;
        }
        
        return $column;
    }
    
    protected function generateWordPressFillable(array $columns): array
    {
        $fillable = ['wordpress_id']; // Always include wordpress_id for syncing
        
        foreach ($columns as $column) {
            if (($column['fillable'] ?? false) && 
                !in_array($column['name'], ['id', 'wordpress_id', 'created_at', 'updated_at'])) {
                $fillable[] = $column['name'];
            }
        }
        
        return $fillable;
    }
    
    protected function generateWordPressCasts(array $columns): array
    {
        $casts = [];
        
        foreach ($columns as $column) {
            if (isset($column['cast'])) {
                $casts[$column['name']] = $column['cast'];
            }
        }
        
        return $casts;
    }
    
    protected function getModelStub(): string
    {
        return '<?php

namespace {{namespace}};

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class {{className}} extends Model
{
    use HasFactory;

    protected $table = \'{{tableName}}\';

    protected $fillable = {{fillable}};

    protected $casts = {{casts}};

    // WordPress post type this model represents
    public const POST_TYPE = \'{{postType}}\';

    {{relationships}}

    {{scopes}}
}
';
    }
}