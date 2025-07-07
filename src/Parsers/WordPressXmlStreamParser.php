<?php

namespace Crumbls\Importer\Parsers;

use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Support\SchemaDefinition;

class WordPressXmlStreamParser
{
    protected StorageDriverContract $storage;
    protected array $config;
    protected array $batches = [];
    protected int $batchSize;
    protected array $seenTerms = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 100,
            'extract_meta' => true,
            'extract_comments' => true,
            'extract_terms' => true,
            'extract_users' => true,
            'memory_limit' => '256M',
        ], $config);
        
        $this->batchSize = $this->config['batch_size'];
    }

    public function parse(ImportContract $import, StorageDriverContract $storage, SourceResolverManager $sourceResolver): array
    {
        $this->storage = $storage;
        $this->initializeBatches();
        $this->createTables();
        
        $stats = [
            'posts' => 0,
            'meta' => 0,
            'comments' => 0,
            'terms' => 0,
            'users' => 0,
            'bytes_processed' => 0,
            'memory_peak' => 0,
            'source_type' => $import->source_type,
            'source_detail' => $import->source_detail,
        ];

        // Resolve the source to get actual file path
        $filePath = $sourceResolver->resolve($import->source_type, $import->source_detail);
        
        $reader = new \XMLReader();
        $reader->open($filePath);
        
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->localName === 'item') {
                    $itemXml = $reader->readOuterXML();
                    $stats['bytes_processed'] += strlen($itemXml);
                    
                    $this->processItem($itemXml, $stats);
                    
                    // Memory management
                    if (memory_get_usage() > $this->parseMemoryLimit()) {
                        $this->flushAllBatches();
                        gc_collect_cycles();
                    }
                    
                    $stats['memory_peak'] = max($stats['memory_peak'], memory_get_peak_usage(true));
                } elseif ($reader->localName === 'author' && $reader->namespaceURI === 'http://wordpress.org/export/1.2/') {
                    if ($this->config['extract_users']) {
                        $authorXml = $reader->readOuterXML();
                        $stats['bytes_processed'] += strlen($authorXml);
                        
                        $this->processAuthor($authorXml, $stats);
                        
                        $stats['memory_peak'] = max($stats['memory_peak'], memory_get_peak_usage(true));
                    }
                }
            }
        }
        
        // Flush remaining batches
        $this->flushAllBatches();
        
        $reader->close();
        
        return $stats;
    }

    /**
     * Convenience method for parsing a direct file path
     */
    public function parseFile(string $filePath, StorageDriverContract $storage): array
    {
        $this->storage = $storage;
        $this->initializeBatches();
        $this->createTables();
        
        $stats = [
            'posts' => 0,
            'meta' => 0,
            'comments' => 0,
            'terms' => 0,
            'bytes_processed' => 0,
            'memory_peak' => 0,
            'source_type' => 'file::direct',
            'source_detail' => $filePath,
        ];

        return $this->parseFromPath($filePath, $stats);
    }

    protected function parseFromPath(string $filePath, array $stats): array
    {
        $reader = new \XMLReader();
        $reader->open($filePath);
        
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'item') {
                $itemXml = $reader->readOuterXML();
                $stats['bytes_processed'] += strlen($itemXml);
                
                $this->processItem($itemXml, $stats);
                
                // Memory management
                if (memory_get_usage() > $this->parseMemoryLimit()) {
                    $this->flushAllBatches();
                    gc_collect_cycles();
                }
                
                $stats['memory_peak'] = max($stats['memory_peak'], memory_get_peak_usage(true));
            }
        }
        
        // Flush remaining batches
        $this->flushAllBatches();
        
        $reader->close();
        
        return $stats;
    }

    protected function processItem(string $itemXml, array &$stats): void
    {
        try {
            // Use DOMDocument only for individual item (small memory footprint)
            $dom = new \DOMDocument();
            $dom->loadXML($itemXml);
            $xpath = new \DOMXPath($dom);
            
            // Register WordPress namespaces
            $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
            $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
            
            // Extract post data
            $post = $this->extractPost($xpath);
            if ($post) {
                $this->addToBatch('posts', $post);
                $stats['posts']++;
                
                // Extract related data
                if ($this->config['extract_meta']) {
                    $meta = $this->extractMeta($xpath, $post['post_id']);
                    foreach ($meta as $metaItem) {
                        $this->addToBatch('postmeta', $metaItem);
                        $stats['meta']++;
                    }
                }
                
                if ($this->config['extract_comments']) {
                    $comments = $this->extractComments($xpath, $post['post_id']);
                    foreach ($comments as $comment) {
                        $this->addToBatch('comments', $comment);
                        $stats['comments']++;
                    }
                }
                
                if ($this->config['extract_terms']) {
                    $terms = $this->extractTerms($xpath, $post['post_id']);
                    foreach ($terms as $term) {
                        // Only add term if we haven't seen it before
                        if (!isset($this->seenTerms[$term['term_id']])) {
                            $this->addToBatch('terms', $term);
                            $this->seenTerms[$term['term_id']] = true;
                            $stats['terms']++;
                        }
                        
                        // Always add the relationship
                        $this->addToBatch('term_relationships', [
                            'post_id' => $post['post_id'],
                            'term_id' => $term['term_id'],
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
            
        } catch (\Exception $e) {
            // Log error but continue processing
            error_log("WordPress XML parsing error: " . $e->getMessage());
        }
    }

    protected function extractPost(\DOMXPath $xpath): ?array
    {
        $postId = $this->getXPathValue($xpath, './/wp:post_id');
        if (!$postId) {
            return null;
        }

        return [
            'post_id' => (int) $postId,
            'post_title' => $this->getXPathValue($xpath, './/title'),
            'post_content' => $this->getXPathValue($xpath, './/content:encoded'),
            'post_excerpt' => $this->getXPathValue($xpath, './/wp:post_excerpt'),
            'post_status' => $this->getXPathValue($xpath, './/wp:status'),
            'post_type' => $this->getXPathValue($xpath, './/wp:post_type'),
            'post_date' => $this->convertWordPressDate($this->getXPathValue($xpath, './/wp:post_date')),
            'post_date_gmt' => $this->convertWordPressDate($this->getXPathValue($xpath, './/wp:post_date_gmt')),
            'post_name' => $this->getXPathValue($xpath, './/wp:post_name'),
            'post_parent' => (int) $this->getXPathValue($xpath, './/wp:post_parent'),
            'menu_order' => (int) $this->getXPathValue($xpath, './/wp:menu_order'),
            'guid' => $this->getXPathValue($xpath, './/guid'),
            'processed' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function extractMeta(\DOMXPath $xpath, int $postId): array
    {
        $metaNodes = $xpath->query('.//wp:postmeta');
        $meta = [];

        foreach ($metaNodes as $node) {
            $key = $xpath->evaluate('string(wp:meta_key)', $node);
            $value = $xpath->evaluate('string(wp:meta_value)', $node);
            
            if ($key) {
                $meta[] = [
                    'post_id' => $postId,
                    'meta_key' => $key,
                    'meta_value' => $value,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $meta;
    }

    protected function extractComments(\DOMXPath $xpath, int $postId): array
    {
        $commentNodes = $xpath->query('.//wp:comment');
        $comments = [];

        foreach ($commentNodes as $node) {
            $commentId = $xpath->evaluate('string(wp:comment_id)', $node);
            if ($commentId) {
                $comments[] = [
                    'comment_id' => (int) $commentId,
                    'post_id' => $postId,
                    'comment_author' => $xpath->evaluate('string(wp:comment_author)', $node),
                    'comment_author_email' => $xpath->evaluate('string(wp:comment_author_email)', $node),
                    'comment_author_url' => $xpath->evaluate('string(wp:comment_author_url)', $node),
                    'comment_author_IP' => $xpath->evaluate('string(wp:comment_author_IP)', $node),
                    'comment_date' => $this->convertWordPressDate($xpath->evaluate('string(wp:comment_date)', $node)),
                    'comment_date_gmt' => $this->convertWordPressDate($xpath->evaluate('string(wp:comment_date_gmt)', $node)),
                    'comment_content' => $xpath->evaluate('string(wp:comment_content)', $node),
                    'comment_approved' => $xpath->evaluate('string(wp:comment_approved)', $node),
                    'comment_parent' => (int) $xpath->evaluate('string(wp:comment_parent)', $node),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $comments;
    }

    protected function extractTerms(\DOMXPath $xpath, int $postId): array
    {
        $termNodes = $xpath->query('.//category[@domain]');
        $terms = [];

        foreach ($termNodes as $node) {
            $taxonomy = $node->getAttribute('domain');
            $nicename = $node->getAttribute('nicename');
            $name = $node->textContent;

            if ($name && $taxonomy) {
                $termId = crc32($taxonomy . '|' . $nicename); // Generate consistent ID
                
                $terms[] = [
                    'term_id' => $termId,
                    'name' => $name,
                    'slug' => $nicename,
                    'taxonomy' => $taxonomy,
                    'description' => '',
                    'parent' => 0,
                    'count' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $terms;
    }

    protected function processAuthor(string $authorXml, array &$stats): void
    {
        try {
            // Use DOMDocument only for individual author (small memory footprint)
            $dom = new \DOMDocument();
            $dom->loadXML($authorXml);
            $xpath = new \DOMXPath($dom);
            
            // Register WordPress namespaces
            $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
            
            // Extract user data
            $user = $this->extractUser($xpath);
            if ($user) {
                $this->addToBatch('users', $user);
                $stats['users']++;
            }
            
        } catch (\Exception $e) {
            // Log error but continue processing
            error_log("WordPress author parsing error: " . $e->getMessage());
        }
    }

    protected function extractUser(\DOMXPath $xpath): ?array
    {
        $userId = $this->getXPathValue($xpath, './/wp:author_id');
        if (!$userId) {
            return null;
        }

        return [
            'user_id' => (int) $userId,
            'login' => $this->getXPathValue($xpath, './/wp:author_login'),
            'email' => $this->getXPathValue($xpath, './/wp:author_email'),
            'display_name' => $this->getXPathValue($xpath, './/wp:author_display_name'),
            'first_name' => $this->getXPathValue($xpath, './/wp:author_first_name'),
            'last_name' => $this->getXPathValue($xpath, './/wp:author_last_name'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function initializeBatches(): void
    {
        $this->batches = [
            'posts' => [],
            'postmeta' => [],
            'comments' => [],
            'terms' => [],
            'term_relationships' => [],
            'users' => [],
        ];
        $this->seenTerms = [];
    }

    protected function createTables(): void
    {
        // Create posts table
        if (!$this->storage->tableExists('posts')) {
            $schema = (new SchemaDefinition('posts'))
                ->bigInteger('post_id', ['primary' => true])
                ->text('post_title', ['nullable' => true])
                ->longText('post_content', ['nullable' => true])
                ->text('post_excerpt', ['nullable' => true])
                ->string('post_status', 20, ['default' => 'publish'])
                ->string('post_type', 50, ['default' => 'post'])
                ->timestamp('post_date', ['nullable' => true])
                ->timestamp('post_date_gmt', ['nullable' => true])
                ->string('post_name', 255, ['nullable' => true])
                ->bigInteger('post_parent', ['default' => 0])
                ->integer('menu_order', ['default' => 0])
                ->text('guid', ['nullable' => true])
                ->boolean('processed', ['default' => false])
                ->timestamps()
                ->index(['post_type', 'post_status'])
                ->index('post_parent')
                ->index('processed');
                
            $this->storage->createTableFromSchema('posts', $schema->toArray());
        }

        // Create postmeta table
        if (!$this->storage->tableExists('postmeta')) {
            $schema = (new SchemaDefinition('postmeta'))
                ->integer('id', ['primary' => true])
                ->bigInteger('post_id')
                ->string('meta_key', 255, ['nullable' => true])
                ->longText('meta_value', ['nullable' => true])
                ->timestamps()
                ->index(['post_id', 'meta_key']);
                
            $this->storage->createTableFromSchema('postmeta', $schema->toArray());
        }

        // Create comments table
        if (!$this->storage->tableExists('comments')) {
            $schema = (new SchemaDefinition('comments'))
                ->bigInteger('comment_id', ['primary' => true])
                ->bigInteger('post_id')
                ->text('comment_author', ['nullable' => true])
                ->string('comment_author_email', 100, ['nullable' => true])
                ->text('comment_author_url', ['nullable' => true])
                ->string('comment_author_IP', 100, ['nullable' => true])
                ->timestamp('comment_date', ['nullable' => true])
                ->timestamp('comment_date_gmt', ['nullable' => true])
                ->longText('comment_content', ['nullable' => true])
                ->string('comment_approved', 20, ['default' => '1'])
                ->bigInteger('comment_parent', ['default' => 0])
                ->timestamps()
                ->index('post_id')
                ->index('comment_approved');
                
            $this->storage->createTableFromSchema('comments', $schema->toArray());
        }

        // Create terms table
        if (!$this->storage->tableExists('terms')) {
            $schema = (new SchemaDefinition('terms'))
                ->bigInteger('term_id', ['primary' => true])
                ->string('name')
                ->string('slug')
                ->string('taxonomy')
                ->longText('description', ['nullable' => true])
                ->bigInteger('parent', ['default' => 0])
                ->bigInteger('count', ['default' => 0])
                ->timestamps()
                ->index('taxonomy');
                
            $this->storage->createTableFromSchema('terms', $schema->toArray());
        }

        // Create term_relationships table
        if (!$this->storage->tableExists('term_relationships')) {
            $schema = (new SchemaDefinition('term_relationships'))
                ->bigInteger('post_id')
                ->bigInteger('term_id')
                ->timestamps()
                ->primary(['post_id', 'term_id']);
                
            $this->storage->createTableFromSchema('term_relationships', $schema->toArray());
        }

        // Create users table
        if (!$this->storage->tableExists('users')) {
            $schema = (new SchemaDefinition('users'))
                ->bigInteger('user_id', ['primary' => true])
                ->string('login', 255, ['nullable' => true])
                ->string('email', 255, ['nullable' => true])
                ->string('display_name', 255, ['nullable' => true])
                ->string('first_name', 255, ['nullable' => true])
                ->string('last_name', 255, ['nullable' => true])
                ->timestamps()
                ->index('login')
                ->index('email');
                
            $this->storage->createTableFromSchema('users', $schema->toArray());
        }
    }

    protected function addToBatch(string $table, array $data): void
    {
        $this->batches[$table][] = $data;
        
        // Auto-flush when batch is full
        if (count($this->batches[$table]) >= $this->batchSize) {
            $this->flushBatch($table);
        }
    }

    protected function flushBatch(string $table): void
    {
        if (!empty($this->batches[$table])) {
            $this->storage->insertBatch($table, $this->batches[$table]);
            $this->batches[$table] = [];
        }
    }

    protected function flushAllBatches(): void
    {
        foreach (array_keys($this->batches) as $table) {
            $this->flushBatch($table);
        }
    }

    protected function getXPathValue(\DOMXPath $xpath, string $query): string
    {
        $result = $xpath->evaluate("string({$query})");
        return $result ?: '';
    }

    protected function convertWordPressDate(string $date): ?string
    {
        if (empty($date) || $date === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date)->toDateTimeString();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseMemoryLimit(): int
    {
        $limit = $this->config['memory_limit'];
        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));
        
        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return $value;
        }
    }
}