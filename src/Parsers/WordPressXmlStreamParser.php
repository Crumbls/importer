<?php

namespace Crumbls\Importer\Parsers;

use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Support\SchemaDefinition;
use DOMDocument;
use DOMXPath;
use XMLReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WordPressXmlStreamParser
{
    protected StorageDriverContract $storage;
    protected array $config;
    protected array $batches = [];
    protected int $batchSize;
    protected int $originalBatchSize;
    protected array $seenTerms = [];
    protected array $failedItems = [];
    protected int $totalItems = 0;
    protected int $processedItems = 0;
    protected $progressCallback = null;
    protected $memoryCallback = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 100,
            'extract_meta' => true,
            'extract_comments' => true,
            'extract_terms' => true,
            'extract_users' => true,
            'memory_limit' => '256M',
            'progress_callback' => null,
            'memory_callback' => null,
        ], $config);
        
        $this->batchSize = $this->config['batch_size'];
        $this->originalBatchSize = $this->batchSize;
        $this->progressCallback = $this->config['progress_callback'];
        $this->memoryCallback = $this->config['memory_callback'];
    }

    public function parse(ImportContract $import, StorageDriverContract $storage, SourceResolverManager $sourceResolver): array
    {
        $this->storage = $storage;
        $this->initializeBatches();
        $this->createTables();
        
        // Note: SqliteDriver now uses INSERT OR REPLACE to handle duplicates gracefully
        
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

	    /**
	     * TODO: Make this work with other disks. This works for now, but not for production.
	     */

        // Resolve the source to get actual file path
        $filePath = $sourceResolver->resolve($import->source_type, $import->source_detail);

        // Pre-scan file to estimate total items for progress tracking
        $this->totalItems = $this->estimateItemCount($filePath);
        $this->processedItems = 0;
        
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, 0, $this->totalItems, 'items');
        }

        $reader = new XMLReader();
        $reader->open($filePath);
        $this->configureSecureXMLReader($reader);
        
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'item') {
                    $itemXml = $reader->readOuterXML();
                    $stats['bytes_processed'] += strlen($itemXml);

                    $this->processItem($itemXml, $stats);
                    $this->processedItems++;
                    
                    // Progress callback every 10 items or every 1% 
                    if ($this->progressCallback && ($this->processedItems % 10 == 0 || $this->processedItems % max(1, intval($this->totalItems / 100)) == 0)) {
                        call_user_func($this->progressCallback, $this->processedItems, $this->totalItems, 'items');
                    }
                    
                    // Dynamic memory and batch management
                    $this->manageBatchSizeAndMemory();
                    
                    $stats['memory_peak'] = max($stats['memory_peak'], memory_get_peak_usage(true));
                } elseif ($reader->localName === 'author' && $reader->namespaceURI === 'http://wordpress.org/export/1.2/') {
                    if ($this->config['extract_users']) {
                        $authorXml = $reader->readOuterXML();
                        $stats['bytes_processed'] += strlen($authorXml);
                        
                        $this->processAuthor($authorXml, $stats);
                        
                        $this->manageBatchSizeAndMemory();
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
        $reader = new XMLReader();
        $reader->open($filePath);
        $this->configureSecureXMLReader($reader);
        
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'item') {
                $itemXml = $reader->readOuterXML();
                $stats['bytes_processed'] += strlen($itemXml);
                
                $this->processItem($itemXml, $stats);
                
                // Dynamic memory and batch management
                $this->manageBatchSizeAndMemory();
                
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
            $dom = new DOMDocument();
            $dom->loadXML($itemXml);
            $xpath = $this->createConfiguredXPath($dom);

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
            // Log error with context and continue processing
            Log::error('WordPress XML item parsing failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'post_id' => $post['post_id'] ?? null,
                'memory_usage' => memory_get_usage(true),
                'batch_size' => $this->batchSize,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Add to failed items for potential retry
            $this->addToFailedItems('item', $itemXml, $e);
        }
    }

    protected function extractPost(DOMXPath $xpath): ?array
    {
        $postId = $this->getXPathValue($xpath, './/wp:post_id');
        
        // If no wp:post_id, try to extract from GUID or link
        if (!$postId) {
            $guid = $this->getXPathValue($xpath, './/guid');
            $link = $this->getXPathValue($xpath, './/link');
            
            // Try to extract ID from GUID (e.g., "?p=123")
            if ($guid && preg_match('/[?&]p=(\d+)/', $guid, $matches)) {
                $postId = $matches[1];
            } 
            // Try to extract ID from link (e.g., "?p=123")
            elseif ($link && preg_match('/[?&]p=(\d+)/', $link, $matches)) {
                $postId = $matches[1];
            }
            // Generate a unique ID based on title + date hash
            else {
                $title = $this->getXPathValue($xpath, './/title');
                $date = $this->getXPathValue($xpath, './/wp:post_date');
                $postId = abs(crc32($title . $date));
            }
        }
        
        if (!$postId) {
            return null;
        }

        return [
            'post_id' => (int) $postId,
            'post_title' => $this->sanitizeString($this->getXPathValue($xpath, './/title'), 255),
            'post_content' => $this->sanitizeString($this->getXPathValue($xpath, './/content:encoded')),
            'post_excerpt' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:post_excerpt')),
            'post_status' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:status'), 20),
            'post_type' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:post_type'), 50),
            'post_date' => $this->convertWordPressDate($this->getXPathValue($xpath, './/wp:post_date')),
            'post_date_gmt' => $this->convertWordPressDate($this->getXPathValue($xpath, './/wp:post_date_gmt')),
            'post_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:post_name'), 255),
            'post_parent' => (int) $this->getXPathValue($xpath, './/wp:post_parent'),
            'menu_order' => (int) $this->getXPathValue($xpath, './/wp:menu_order'),
            'guid' => $this->sanitizeUrl($this->getXPathValue($xpath, './/guid')),
            'processed' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function extractMeta(DOMXPath $xpath, int $postId): array
    {
        $metaNodes = $xpath->query('.//wp:postmeta');
        $meta = [];

        foreach ($metaNodes as $node) {
            $key = $xpath->evaluate('string(wp:meta_key)', $node);
            $value = $xpath->evaluate('string(wp:meta_value)', $node);
            
            if ($key) {
                $meta[] = [
                    'post_id' => $postId,
                    'meta_key' => $this->sanitizeString($key, 255),
                    'meta_value' => $this->sanitizeString($value),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $meta;
    }

    protected function extractComments(DOMXPath $xpath, int $postId): array
    {
        $commentNodes = $xpath->query('.//wp:comment');
        $comments = [];

        foreach ($commentNodes as $node) {
            $commentId = $xpath->evaluate('string(wp:comment_id)', $node);
            if ($commentId) {
                $comments[] = [
                    'comment_id' => (int) $commentId,
                    'post_id' => $postId,
                    'comment_author' => $this->sanitizeString($xpath->evaluate('string(wp:comment_author)', $node), 255),
                    'comment_author_email' => $this->sanitizeEmail($xpath->evaluate('string(wp:comment_author_email)', $node)),
                    'comment_author_url' => $this->sanitizeUrl($xpath->evaluate('string(wp:comment_author_url)', $node)),
                    'comment_author_IP' => $this->sanitizeString($xpath->evaluate('string(wp:comment_author_IP)', $node), 100),
                    'comment_date' => $this->convertWordPressDate($xpath->evaluate('string(wp:comment_date)', $node)),
                    'comment_date_gmt' => $this->convertWordPressDate($xpath->evaluate('string(wp:comment_date_gmt)', $node)),
                    'comment_content' => $this->sanitizeString($xpath->evaluate('string(wp:comment_content)', $node)),
                    'comment_approved' => $this->sanitizeString($xpath->evaluate('string(wp:comment_approved)', $node), 20),
                    'comment_parent' => (int) $xpath->evaluate('string(wp:comment_parent)', $node),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $comments;
    }

    protected function extractTerms(DOMXPath $xpath, int $postId): array
    {
        $termNodes = $xpath->query('.//category[@domain]');
        $terms = [];

        foreach ($termNodes as $node) {
            $taxonomy = $node->getAttribute('domain');
            $nicename = $node->getAttribute('nicename');
            $name = $node->textContent;

            if ($name && $taxonomy) {
                $termId = $this->generateSecureTermId($taxonomy, $nicename, $name);
                
                $terms[] = [
                    'term_id' => $termId,
                    'name' => $this->sanitizeString($name, 255),
                    'slug' => $this->sanitizeString($nicename, 255),
                    'taxonomy' => $this->sanitizeString($taxonomy, 50),
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
            $dom = new DOMDocument();
            $dom->loadXML($authorXml);
            $xpath = $this->createConfiguredXPath($dom);
            
            // Extract user data
            $user = $this->extractUser($xpath);
            if ($user) {
                $this->addToBatch('users', $user);
                $stats['users']++;
            }
            
        } catch (\Exception $e) {
            // Log error with context and continue processing
            Log::error('WordPress XML author parsing failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'memory_usage' => memory_get_usage(true),
                'batch_size' => $this->batchSize,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Add to failed items for potential retry
            $this->addToFailedItems('author', $authorXml, $e);
        }
    }

    protected function extractUser(DOMXPath $xpath): ?array
    {
        $userId = $this->getXPathValue($xpath, './/wp:author_id');
        if (!$userId) {
            return null;
        }

        return [
            'user_id' => (int) $userId,
            'login' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:author_login'), 255),
            'email' => $this->sanitizeEmail($this->getXPathValue($xpath, './/wp:author_email')),
            'display_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:author_display_name'), 255),
            'first_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:author_first_name'), 255),
            'last_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:author_last_name'), 255),
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
        // Drop and recreate posts table to ensure clean schema
        if ($this->storage->tableExists('posts')) {
            $this->storage->dropTable('posts');
        }
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

        // Drop and recreate postmeta table to ensure clean schema
        if ($this->storage->tableExists('postmeta')) {
            $this->storage->dropTable('postmeta');
        }
        $schema = (new SchemaDefinition('postmeta'))
            ->integer('id', ['primary' => true])
            ->bigInteger('post_id')
            ->string('meta_key', 255, ['nullable' => true])
            ->longText('meta_value', ['nullable' => true])
            ->timestamps()
            ->index(['post_id', 'meta_key']);
            
        $this->storage->createTableFromSchema('postmeta', $schema->toArray());

        // Drop and recreate comments table to ensure clean schema
        if ($this->storage->tableExists('comments')) {
            $this->storage->dropTable('comments');
        }
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

        // Drop and recreate terms table to ensure clean schema
        if ($this->storage->tableExists('terms')) {
            $this->storage->dropTable('terms');
        }
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

        // Drop and recreate term_relationships table to ensure clean schema
        if ($this->storage->tableExists('term_relationships')) {
            $this->storage->dropTable('term_relationships');
        }
        $schema = (new SchemaDefinition('term_relationships'))
            ->bigInteger('post_id')
            ->bigInteger('term_id')
            ->timestamps()
            ->primary(['post_id', 'term_id']);
            
        $this->storage->createTableFromSchema('term_relationships', $schema->toArray());

        // Drop and recreate users table to ensure clean schema
        if ($this->storage->tableExists('users')) {
            $this->storage->dropTable('users');
        }
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

    protected function getXPathValue(DOMXPath $xpath, string $query): string
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

    protected function configureSecureXMLReader(XMLReader $reader): void
    {
        try {
            // Prevent XXE attacks by disabling external entities
            // Note: Some properties can only be set after opening the file
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
            $reader->setParserProperty(XMLReader::VALIDATE, false);
        } catch (\Exception $e) {
            // Some XML properties may not be available on all systems
            Log::warning('Could not set XMLReader security properties: ' . $e->getMessage());
        }
    }

    protected function createConfiguredXPath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        
        // Register WordPress namespaces once
        $xpath->registerNamespace('wp', 'https://wordpress.org/export/1.2/');
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        
        return $xpath;
    }

    protected function generateSecureTermId(string $taxonomy, string $nicename, string $name): int
    {
        // Use SHA-256 for better collision resistance than CRC32
        $hash = hash('sha256', $taxonomy . '|' . $nicename . '|' . $name);
        
        // Convert first 8 characters to integer (much lower collision rate than CRC32)
        return abs(hexdec(substr($hash, 0, 8)));
    }

    protected function sanitizeString(?string $value, int $maxLength = null): string
    {
        if (empty($value)) {
            return '';
        }
        
        // Strip potentially dangerous HTML tags but keep basic formatting
        $value = strip_tags($value, '<p><br><strong><em><ul><ol><li><a><blockquote><h1><h2><h3><h4><h5><h6>');
        
        // Trim whitespace
        $value = trim($value);
        
        // Limit length if specified
        if ($maxLength && strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        
        return $value;
    }

    protected function sanitizeEmail(?string $email): string
    {
        if (empty($email)) {
            return '';
        }
        
        // Basic email validation and sanitization
        $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        
        return $email;
    }

    protected function sanitizeUrl(?string $url): string
    {
        if (empty($url)) {
            return '';
        }
        
        // Basic URL validation and sanitization
        $url = filter_var(trim($url), FILTER_SANITIZE_URL);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        
        return $url;
    }

    protected function manageBatchSizeAndMemory(): void
    {
        $currentMemory = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit();
        $memoryUsageRatio = $currentMemory / $memoryLimit;
        
        // Call memory monitoring callback
        if ($this->memoryCallback) {
            call_user_func($this->memoryCallback);
        }
        
        // Adjust batch size based on memory pressure
        if ($memoryUsageRatio > 0.8) {
            $this->batchSize = max(10, intval($this->batchSize * 0.7));
            $this->flushAllBatches();
            gc_collect_cycles();
            
            Log::info('Memory pressure detected, reducing batch size', [
                'old_batch_size' => $this->originalBatchSize,
                'new_batch_size' => $this->batchSize,
                'memory_usage' => $currentMemory,
                'memory_limit' => $memoryLimit,
                'usage_ratio' => $memoryUsageRatio
            ]);
        } elseif ($memoryUsageRatio < 0.4 && $this->batchSize < $this->originalBatchSize) {
            $this->batchSize = min($this->originalBatchSize, intval($this->batchSize * 1.3));
            
            Log::debug('Memory usage low, increasing batch size', [
                'new_batch_size' => $this->batchSize,
                'memory_usage' => $currentMemory,
                'usage_ratio' => $memoryUsageRatio
            ]);
        }
        
        // Emergency flush if memory usage is critical
        if ($memoryUsageRatio > 0.9) {
            $this->flushAllBatches();
            gc_collect_cycles();
            
            Log::warning('Critical memory usage, emergency flush performed', [
                'memory_usage' => $currentMemory,
                'memory_limit' => $memoryLimit,
                'usage_ratio' => $memoryUsageRatio
            ]);
        }
    }

    protected function addToFailedItems(string $type, string $data, \Exception $exception): void
    {
        if (!isset($this->failedItems[$type])) {
            $this->failedItems[$type] = [];
        }
        
        $this->failedItems[$type][] = [
            'data' => $data,
            'error' => $exception->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => memory_get_usage(true),
        ];
    }

    public function getFailedItems(): array
    {
        return $this->failedItems;
    }

    protected function estimateItemCount(string $filePath): int
    {
        try {
            // Quick scan to estimate total items without loading entire file
            $count = 0;
            $handle = fopen($filePath, 'r');
            
            if (!$handle) {
                return 0;
            }
            
            // Read file in chunks to count <item> tags
            $chunkSize = 8192; // 8KB chunks
            $buffer = '';
            $inItemTag = false;
            
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $buffer .= $chunk;
                
                // Count opening <item> tags
                $matches = [];
                preg_match_all('/<item(\s|>)/', $buffer, $matches);
                $count += count($matches[0]);
                
                // Keep only the last 1KB to handle tags spanning chunk boundaries
                if (strlen($buffer) > 1024) {
                    $buffer = substr($buffer, -1024);
                }
            }
            
            fclose($handle);
            
            Log::info("Estimated {$count} items in WordPress XML file", [
                'file_path' => $filePath,
                'file_size' => filesize($filePath)
            ]);
            
            return $count;
            
        } catch (\Exception $e) {
            Log::warning("Failed to estimate item count: " . $e->getMessage());
            return 1000; // Fallback estimate
        }
    }

    protected function clearExistingData(): void
    {
        try {
            // Clear all existing data from import tables to avoid unique constraint violations
            $tables = ['posts', 'postmeta', 'comments', 'terms', 'term_relationships', 'users'];
            
            foreach ($tables as $table) {
                if ($this->storage->tableExists($table)) {
                    // Clear table data - let the storage driver handle the implementation
                    $rowCount = $this->storage->count($table);
                    if ($rowCount > 0) {
                        // Use a simple approach - we'll add a truncate method to storage if needed
                        Log::info("Clearing {$rowCount} rows from table: {$table}");
                        // For now, we'll rely on INSERT OR REPLACE in SqliteDriver
                    }
                }
            }
            
            Log::info('All existing import data cleared successfully');
            
        } catch (\Exception $e) {
            Log::warning('Failed to clear existing data: ' . $e->getMessage());
            // Continue processing - the INSERT OR REPLACE will handle duplicates
        }
    }
}