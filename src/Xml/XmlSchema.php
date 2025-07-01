<?php

namespace Crumbls\Importer\Xml;

class XmlSchema
{
    protected array $namespaces = [];
    protected array $entities = [];
    protected array $relationships = [];
    
    public function __construct(array $config = [])
    {
        $this->namespaces = $config['namespaces'] ?? [];
        $this->entities = $config['entities'] ?? [];
        $this->relationships = $config['relationships'] ?? [];
    }
    
    public static function wordpress(): self
    {
        return new self([
            'namespaces' => [
                'wp' => 'https://wordpress.org/export/1.2/',
                'content' => 'http://purl.org/rss/1.0/modules/content/',
                'dc' => 'http://purl.org/dc/elements/1.1/',
                'excerpt' => 'https://wordpress.org/export/1.2/excerpt/',
                'wfw' => 'http://wellformedweb.org/CommentAPI/'
            ],
            'entities' => [
                'posts' => [
                    'xpath' => '//item[wp:post_type]',
                    'table' => 'posts',
                    'fields' => [
                        'title' => 'title',
                        'content' => 'content:encoded',
                        'excerpt' => 'excerpt:encoded',
                        'post_type' => 'wp:post_type',
                        'status' => 'wp:status',
                        'post_date' => 'wp:post_date',
                        'post_date_gmt' => 'wp:post_date_gmt',
                        'post_modified' => 'wp:post_modified',
                        'post_modified_gmt' => 'wp:post_modified_gmt',
                        'author' => 'dc:creator',
                        'link' => 'link',
                        'guid' => 'guid',
                        'post_id' => 'wp:post_id',
                        'post_parent' => 'wp:post_parent',
                        'menu_order' => 'wp:menu_order',
                        'post_password' => 'wp:post_password',
                        'is_sticky' => 'wp:is_sticky',
                        'ping_status' => 'wp:ping_status',
                        'comment_status' => 'wp:comment_status',
                        'created_at' => null
                    ]
                ],
                'postmeta' => [
                    'xpath' => '//item[wp:post_type]/wp:postmeta',
                    'table' => 'postmeta',
                    'fields' => [
                        'post_id' => '../wp:post_id',
                        'meta_key' => 'wp:meta_key',
                        'meta_value' => 'wp:meta_value',
                        'created_at' => null
                    ]
                ],
                'attachments' => [
                    'xpath' => '//item[wp:post_type="attachment"]',
                    'table' => 'attachments',
                    'fields' => [
                        'title' => 'title',
                        'description' => 'description',
                        'post_id' => 'wp:post_id',
                        'post_parent' => 'wp:post_parent',
                        'attachment_url' => 'wp:attachment_url',
                        'post_date' => 'wp:post_date',
                        'author' => 'dc:creator',
                        'guid' => 'guid',
                        'created_at' => null
                    ]
                ],
                'users' => [
                    'xpath' => '//wp:author',
                    'table' => 'users',
                    'unique_key' => 'author_login',
                    'fields' => [
                        'author_id' => 'wp:author_id',
                        'author_login' => 'wp:author_login',
                        'author_email' => 'wp:author_email',
                        'author_display_name' => 'wp:author_display_name',
                        'author_first_name' => 'wp:author_first_name',
                        'author_last_name' => 'wp:author_last_name',
                        'created_at' => null
                    ]
                ],
                'comments' => [
                    'xpath' => '//wp:comment',
                    'table' => 'comments',
                    'fields' => [
                        'comment_id' => 'wp:comment_id',
                        'comment_post_id' => '../wp:post_id',
                        'author' => 'wp:comment_author',
                        'author_email' => 'wp:comment_author_email',
                        'author_url' => 'wp:comment_author_url',
                        'author_ip' => 'wp:comment_author_IP',
                        'content' => 'wp:comment_content',
                        'approved' => 'wp:comment_approved',
                        'comment_type' => 'wp:comment_type',
                        'parent_id' => 'wp:comment_parent',
                        'user_id' => 'wp:comment_user_id',
                        'comment_date' => 'wp:comment_date',
                        'comment_date_gmt' => 'wp:comment_date_gmt',
                        'created_at' => null
                    ]
                ],
                'commentmeta' => [
                    'xpath' => '//wp:comment/wp:commentmeta',
                    'table' => 'commentmeta',
                    'fields' => [
                        'comment_id' => '../wp:comment_id',
                        'meta_key' => 'wp:meta_key',
                        'meta_value' => 'wp:meta_value',
                        'created_at' => null
                    ]
                ],
                'terms' => [
                    'xpath' => '//wp:term',
                    'table' => 'terms',
                    'fields' => [
                        'term_id' => 'wp:term_id',
                        'name' => 'wp:term_name',
                        'slug' => 'wp:term_slug',
                        'taxonomy' => 'wp:term_taxonomy',
                        'description' => 'wp:term_description',
                        'parent' => 'wp:term_parent',
                        'created_at' => null
                    ]
                ],
                'categories' => [
                    'xpath' => '//category[@domain="category"]',
                    'table' => 'categories',
                    'unique_key' => 'nicename',
                    'fields' => [
                        'name' => 'text()',
                        'nicename' => '@nicename',
                        'domain' => '@domain',
                        'created_at' => null
                    ]
                ],
                'tags' => [
                    'xpath' => '//category[@domain="post_tag"]',
                    'table' => 'tags',
                    'unique_key' => 'nicename',
                    'fields' => [
                        'name' => 'text()',
                        'nicename' => '@nicename',
                        'domain' => '@domain',
                        'created_at' => null
                    ]
                ]
            ],
            'relationships' => [
                'posts_users' => [
                    'type' => 'belongs_to',
                    'parent' => 'users',
                    'child' => 'posts',
                    'foreign_key' => 'author',
                    'reference_key' => 'username'
                ],
                'comments_posts' => [
                    'type' => 'belongs_to',
                    'parent' => 'posts',
                    'child' => 'comments',
                    'foreign_key' => 'post_id', // Would need to be mapped
                    'reference_key' => 'id'
                ]
            ]
        ]);
    }
    
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }
    
    public function getEntities(): array
    {
        return $this->entities;
    }
    
    public function getEntity(string $name): ?array
    {
        return $this->entities[$name] ?? null;
    }
    
    public function getTableSchemas(): array
    {
        $schemas = [];
        
        foreach ($this->entities as $entityName => $config) {
            $tableName = $config['table'] ?? $entityName;
            $schemas[$tableName] = array_keys($config['fields']);
        }
        
        return $schemas;
    }
    
    public function getEnabledEntities(array $enabledMap): array
    {
        $enabled = [];
        
        foreach ($this->entities as $entityName => $config) {
            if ($enabledMap[$entityName] ?? true) {
                $enabled[$entityName] = $config;
            }
        }
        
        return $enabled;
    }
    
    public function addEntity(string $name, array $config): self
    {
        $this->entities[$name] = $config;
        return $this;
    }
    
    public function addNamespace(string $prefix, string $uri): self
    {
        $this->namespaces[$prefix] = $uri;
        return $this;
    }
    
    public function addRelationship(string $name, array $config): self
    {
        $this->relationships[$name] = $config;
        return $this;
    }
    
    public function getRequiredXpaths(): array
    {
        $xpaths = [];
        
        foreach ($this->entities as $entityName => $config) {
            $xpaths[$entityName] = $config['xpath'];
        }
        
        return $xpaths;
    }
    
    public function processFieldValue(string $fieldName, ?string $rawValue, array $entityConfig): string
    {
        // Handle auto-generated fields
        if ($rawValue === null) {
            return match ($fieldName) {
                'created_at' => date('Y-m-d H:i:s'),
                'slug' => isset($entityConfig['name']) ? \Illuminate\Support\Str::slug($entityConfig['name']) : '',
                default => ''
            };
        }
        
        // Handle data cleaning/transformation
        return match ($fieldName) {
            'email' => strtolower(trim($rawValue)),
            'status' => strtolower(trim($rawValue)),
            'approved' => $rawValue === '1' ? 'approved' : 'pending',
            default => trim($rawValue)
        };
    }
}