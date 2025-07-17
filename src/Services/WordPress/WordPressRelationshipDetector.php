<?php

namespace Crumbls\Importer\Services\WordPress;

use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Support\Str;

class WordPressRelationshipDetector
{
    /**
     * Detect WordPress relationships and convert to Laravel Eloquent relationships
     */
    public function detectRelationships(string $postType, array $analysisData): array
    {
        $relationships = [];
        
        // Add core WordPress relationships
        $relationships = array_merge($relationships, $this->getCoreRelationships($postType, $analysisData));
        
        // Add taxonomy relationships
        $relationships = array_merge($relationships, $this->getTaxonomyRelationships($postType, $analysisData));
        
        // Add meta-based relationships
        $relationships = array_merge($relationships, $this->getMetaRelationships($postType, $analysisData));
        
        // Add post-type specific relationships
        $relationships = array_merge($relationships, $this->getPostTypeSpecificRelationships($postType, $analysisData));
        
        return $relationships;
    }
    
    protected function getCoreRelationships(string $postType, array $analysisData): array
    {
        $relationships = [];
        
        // Author relationship (every post type has an author)
        $relationships[] = [
            'name' => 'author',
            'type' => 'belongsTo',
            'related_model' => ModelResolver::user(),
            'foreign_key' => 'author_id',
            'owner_key' => 'id',
            'nullable' => true,
        ];
        
        // Parent relationship (hierarchical post types)
        if ($this->isHierarchical($postType)) {
            $modelClass = $this->getModelClassForPostType($postType);
            
            $relationships[] = [
                'name' => 'parent',
                'type' => 'belongsTo',
                'related_model' => $modelClass,
                'foreign_key' => 'parent_id',
                'owner_key' => 'id',
                'nullable' => true,
            ];
            
            $relationships[] = [
                'name' => 'children',
                'type' => 'hasMany',
                'related_model' => $modelClass,
                'foreign_key' => 'parent_id',
                'local_key' => 'id',
            ];
        }
        
        // Comments relationship (if comments are enabled)
        if ($this->hasComments($postType, $analysisData)) {
            $relationships[] = [
                'name' => 'comments',
                'type' => 'hasMany',
                'related_model' => ModelResolver::comment(),
                'foreign_key' => 'post_id',
                'local_key' => 'id',
            ];
        }
        
        // Featured image relationship
        if ($this->hasFeaturedImage($analysisData)) {
            $relationships[] = [
                'name' => 'featuredImage',
                'type' => 'belongsTo',
                'related_model' => ModelResolver::media(),
                'foreign_key' => 'featured_image_id',
                'owner_key' => 'id',
                'nullable' => true,
            ];
        }
        
        return $relationships;
    }
    
    /**
     * Get the model class for a given post type using ModelResolver
     */
    protected function getModelClassForPostType(string $postType): string
    {
        try {
            // Try to resolve the post type directly
            return ModelResolver::__callStatic($postType, []);
        } catch (\Exception $e) {
            // If it fails, try some common variations
            $variations = [
                Str::singular($postType),
                Str::plural($postType),
                str_replace('_', '', $postType),
            ];
            
            foreach ($variations as $variation) {
                try {
                    return ModelResolver::__callStatic($variation, []);
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // If all variations fail, throw the original exception
            throw $e;
        }
    }
    
    protected function getTaxonomyRelationships(string $postType, array $analysisData): array
    {
        $relationships = [];
        $taxonomies = $analysisData['taxonomies'] ?? [];
        
        foreach ($taxonomies as $taxonomy => $taxonomyData) {
            if ($this->postTypeUsesTaxonomy($postType, $taxonomy, $analysisData)) {
                $taxonomyModelKey = $this->getTaxonomyModelKey($taxonomy);
                $relationshipName = $this->getTaxonomyRelationshipName($taxonomy);
                
                $relationships[] = [
                    'name' => $relationshipName,
                    'type' => 'belongsToMany',
                    'related_model' => $this->getModelClassForPostType($taxonomyModelKey),
                    'pivot_table' => $this->getPivotTableName($postType, $taxonomy),
                    'foreign_pivot_key' => $this->getPostForeignKey($postType),
                    'related_pivot_key' => $this->getTaxonomyForeignKey($taxonomy),
                    'pivot_columns' => ['term_order'],
                ];
            }
        }
        
        return $relationships;
    }
    
    protected function getMetaRelationships(string $postType, array $analysisData): array
    {
        $relationships = [];
        $metaFields = $analysisData['meta_fields'] ?? [];
        
        foreach ($metaFields as $meta) {
            $metaKey = $meta['field_name'];
            
            // Check for ID fields that might be foreign keys
            if ($this->isIdField($metaKey)) {
                $relationship = $this->detectIdFieldRelationship($metaKey, $meta, $analysisData);
                if ($relationship) {
                    $relationships[] = $relationship;
                }
            }
        }
        
        return $relationships;
    }
    
    protected function getPostTypeSpecificRelationships(string $postType, array $analysisData): array
    {
        return match($postType) {
            'attachment' => $this->getAttachmentRelationships($analysisData),
            'product' => $this->getProductRelationships($analysisData),
            'event' => $this->getEventRelationships($analysisData),
            'nav_menu_item' => $this->getMenuItemRelationships($analysisData),
            'revision' => $this->getRevisionRelationships($analysisData),
            default => [],
        };
    }
    
    protected function getAttachmentRelationships(array $analysisData): array
    {
        return [
            [
                'name' => 'attachedTo',
                'type' => 'belongsTo',
                'related_model' => ModelResolver::post(), // or make it polymorphic
                'foreign_key' => 'parent_id',
                'owner_key' => 'id',
                'nullable' => true,
            ],
            [
                'name' => 'postThumbnails',
                'type' => 'hasMany',
                'related_model' => ModelResolver::post(),
                'foreign_key' => 'featured_image_id',
                'local_key' => 'id',
            ],
        ];
    }
    
    protected function getProductRelationships(array $analysisData): array
    {
        $relationships = [];
        
        // Product variations (if WooCommerce)
        $relationships[] = [
            'name' => 'variations',
            'type' => 'hasMany',
            'related_model' => 'App\\Models\\ProductVariation',
            'foreign_key' => 'parent_id',
            'local_key' => 'id',
        ];
        
        // Product gallery
        $relationships[] = [
            'name' => 'gallery',
            'type' => 'belongsToMany',
            'related_model' => 'App\\Models\\Media',
            'pivot_table' => 'product_gallery',
            'foreign_pivot_key' => 'product_id',
            'related_pivot_key' => 'media_id',
            'pivot_columns' => ['sort_order'],
        ];
        
        // Related products
        $relationships[] = [
            'name' => 'relatedProducts',
            'type' => 'belongsToMany',
            'related_model' => 'App\\Models\\Product',
            'pivot_table' => 'product_relations',
            'foreign_pivot_key' => 'product_id',
            'related_pivot_key' => 'related_product_id',
            'pivot_columns' => ['relation_type'],
        ];
        
        return $relationships;
    }
    
    protected function getEventRelationships(array $analysisData): array
    {
        return [
            [
                'name' => 'venue',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\Venue',
                'foreign_key' => 'venue_id',
                'owner_key' => 'id',
                'nullable' => true,
            ],
            [
                'name' => 'organizer',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\Organizer',
                'foreign_key' => 'organizer_id',
                'owner_key' => 'id',
                'nullable' => true,
            ],
            [
                'name' => 'attendees',
                'type' => 'belongsToMany',
                'related_model' => 'App\\Models\\User',
                'pivot_table' => 'event_attendees',
                'foreign_pivot_key' => 'event_id',
                'related_pivot_key' => 'user_id',
                'pivot_columns' => ['status', 'registered_at'],
            ],
        ];
    }
    
    protected function getMenuItemRelationships(array $analysisData): array
    {
        return [
            [
                'name' => 'menu',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\Menu',
                'foreign_key' => 'menu_id',
                'owner_key' => 'id',
            ],
            [
                'name' => 'parent',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\MenuItem',
                'foreign_key' => 'menu_item_parent',
                'owner_key' => 'id',
                'nullable' => true,
            ],
            [
                'name' => 'children',
                'type' => 'hasMany',
                'related_model' => 'App\\Models\\MenuItem',
                'foreign_key' => 'menu_item_parent',
                'local_key' => 'id',
            ],
            [
                'name' => 'linkedObject',
                'type' => 'morphTo',
                'morph_type' => 'object_type',
                'morph_id' => 'object_id',
            ],
        ];
    }
    
    protected function getRevisionRelationships(array $analysisData): array
    {
        return [
            [
                'name' => 'original',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\Post',
                'foreign_key' => 'parent_id',
                'owner_key' => 'id',
            ],
        ];
    }
    
    protected function isHierarchical(string $postType): bool
    {
        $hierarchical = ['page', 'attachment', 'nav_menu_item'];
        return in_array($postType, $hierarchical);
    }
    
    protected function hasComments(string $postType, array $analysisData): bool
    {
        $commentableTypes = ['post', 'page', 'product', 'event'];
        return in_array($postType, $commentableTypes) && isset($analysisData['comments']);
    }
    
    protected function hasFeaturedImage(array $analysisData): bool
    {
        $metaFields = $analysisData['meta_fields'] ?? [];
        
        foreach ($metaFields as $meta) {
            if ($meta['field_name'] === '_thumbnail_id') {
                return true;
            }
        }
        
        return false;
    }
    
    protected function postTypeUsesTaxonomy(string $postType, string $taxonomy, array $analysisData): bool
    {
        // Common WordPress taxonomy associations
        $associations = [
            'post' => ['category', 'post_tag'],
            'page' => [],
            'attachment' => [],
            'product' => ['product_cat', 'product_tag', 'product_type'],
            'event' => ['event_category', 'event_tag'],
        ];
        
        return in_array($taxonomy, $associations[$postType] ?? []);
    }
    
    protected function getTaxonomyModelKey(string $taxonomy): string
    {
        $mappings = [
            'category' => 'category',
            'post_tag' => 'tag',
            'product_cat' => 'product_category',
            'product_tag' => 'product_tag',
            'event_category' => 'event_category',
            'event_tag' => 'event_tag',
        ];
        
        return $mappings[$taxonomy] ?? Str::singular($taxonomy);
    }
    
    protected function getTaxonomyRelationshipName(string $taxonomy): string
    {
        $mappings = [
            'category' => 'categories',
            'post_tag' => 'tags',
            'product_cat' => 'categories',
            'product_tag' => 'tags',
            'event_category' => 'categories',
            'event_tag' => 'tags',
        ];
        
        return $mappings[$taxonomy] ?? Str::plural($taxonomy);
    }
    
    protected function getPivotTableName(string $postType, string $taxonomy): string
    {
        $postTable = Str::snake(Str::plural($postType));
        $taxonomyTable = Str::snake(Str::plural($taxonomy));
        
        // Laravel convention: alphabetical order
        $tables = [$postTable, $taxonomyTable];
        sort($tables);
        
        return implode('_', $tables);
    }
    
    protected function getPostForeignKey(string $postType): string
    {
        return Str::singular($postType) . '_id';
    }
    
    protected function getTaxonomyForeignKey(string $taxonomy): string
    {
        return Str::singular($taxonomy) . '_id';
    }
    
    protected function isIdField(string $metaKey): bool
    {
        return str_ends_with($metaKey, '_id') || str_contains($metaKey, '_id_');
    }
    
    protected function detectIdFieldRelationship(string $metaKey, array $meta, array $analysisData): ?array
    {
        // Common WordPress ID field patterns
        $patterns = [
            '_thumbnail_id' => [
                'name' => 'featuredImage',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\Media',
            ],
            '_edit_last' => [
                'name' => 'lastEditor',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\User',
            ],
            '_wp_attachment_id' => [
                'name' => 'attachment',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\Media',
            ],
        ];
        
        if (isset($patterns[$metaKey])) {
            $pattern = $patterns[$metaKey];
            $pattern['foreign_key'] = Str::snake(ltrim($metaKey, '_'));
            $pattern['owner_key'] = 'id';
            $pattern['nullable'] = true;
            
            return $pattern;
        }
        
        return null;
    }
    
    /**
     * Generate relationship method code for models
     */
    public function generateRelationshipMethods(array $relationships): string
    {
        $methods = [];
        
        foreach ($relationships as $relationship) {
            $methods[] = $this->generateRelationshipMethod($relationship);
        }
        
        return implode("\n\n", $methods);
    }
    
    protected function generateRelationshipMethod(array $relationship): string
    {
        $name = $relationship['name'];
        $type = $relationship['type'];
        $relatedModel = $relationship['related_model'];
        
        $method = "    public function {$name}(): " . ucfirst($type) . "\n    {\n";
        
        switch ($type) {
            case 'belongsTo':
                $foreignKey = $relationship['foreign_key'] ?? null;
                $ownerKey = $relationship['owner_key'] ?? null;
                
                $method .= "        return \$this->belongsTo({$relatedModel}::class";
                if ($foreignKey) $method .= ", '{$foreignKey}'";
                if ($ownerKey && $foreignKey) $method .= ", '{$ownerKey}'";
                $method .= ");";
                break;
                
            case 'hasMany':
                $foreignKey = $relationship['foreign_key'] ?? null;
                $localKey = $relationship['local_key'] ?? null;
                
                $method .= "        return \$this->hasMany({$relatedModel}::class";
                if ($foreignKey) $method .= ", '{$foreignKey}'";
                if ($localKey && $foreignKey) $method .= ", '{$localKey}'";
                $method .= ");";
                break;
                
            case 'belongsToMany':
                $pivotTable = $relationship['pivot_table'] ?? null;
                $foreignPivotKey = $relationship['foreign_pivot_key'] ?? null;
                $relatedPivotKey = $relationship['related_pivot_key'] ?? null;
                
                $method .= "        return \$this->belongsToMany({$relatedModel}::class";
                if ($pivotTable) $method .= ", '{$pivotTable}'";
                if ($foreignPivotKey && $pivotTable) $method .= ", '{$foreignPivotKey}'";
                if ($relatedPivotKey && $foreignPivotKey) $method .= ", '{$relatedPivotKey}'";
                $method .= ")";
                
                if (isset($relationship['pivot_columns'])) {
                    $pivotColumns = implode("', '", $relationship['pivot_columns']);
                    $method .= "\n            ->withPivot('{$pivotColumns}')";
                }
                
                $method .= "\n            ->withTimestamps();";
                break;
                
            case 'morphTo':
                $method .= "        return \$this->morphTo();";
                break;
        }
        
        $method .= "\n    }";
        
        return $method;
    }
}