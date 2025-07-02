<?php

namespace Crumbls\Importer\Support;

class WordPressXmlParser
{
    protected array $posts = [];
    protected array $postmeta = [];
    protected array $comments = [];
    protected array $users = [];
    protected array $categories = [];
    protected array $tags = [];
    protected array $statistics = [];
    
    public function parseFile(string $xmlFilePath): array
    {
        if (!file_exists($xmlFilePath)) {
            throw new \InvalidArgumentException("XML file not found: {$xmlFilePath}");
        }
        
        $this->reset();
        
        // Load XML with error handling
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlFilePath);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessages = array_map(fn($error) => $error->message, $errors);
            throw new \RuntimeException('Failed to parse XML: ' . implode(', ', $errorMessages));
        }
        
        // Register WordPress XML namespaces
        $namespaces = $xml->getNamespaces(true);
        
        // Parse channel information
        $channel = $xml->channel;
        
        // Parse items (posts, pages, attachments, etc.)
        // Handle multiple items properly with SimpleXML
        if (isset($channel->item)) {
            // When there are multiple <item> elements, SimpleXML makes them iterable
            foreach ($channel->item as $item) {
                $this->parseItem($item);
            }
        }
        
        // Calculate statistics
        $this->calculateStatistics();
        
        return [
            'posts' => $this->posts,
            'postmeta' => $this->postmeta,
            'comments' => $this->comments,
            'users' => $this->users,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'statistics' => $this->statistics
        ];
    }
    
    protected function reset(): void
    {
        $this->posts = [];
        $this->postmeta = [];
        $this->comments = [];
        $this->users = [];
        $this->categories = [];
        $this->tags = [];
        $this->statistics = [];
    }
    
    
    protected function parseItem(\SimpleXMLElement $item): void
    {
        // Get namespaced elements
        $wp = $item->children('wp', true);
        $content = $item->children('content', true);
        $excerpt = $item->children('excerpt', true);
        
        // Extract basic post data
        $post = [
            'ID' => (string)$wp->post_id,
            'post_title' => (string)$item->title,
            'post_content' => (string)$content->encoded,
            'post_excerpt' => (string)$excerpt->encoded,
            'post_date' => (string)$wp->post_date,
            'post_date_gmt' => (string)$wp->post_date_gmt,
            'post_modified' => (string)$wp->post_modified,
            'post_modified_gmt' => (string)$wp->post_modified_gmt,
            'post_status' => (string)$wp->status,
            'post_type' => (string)$wp->post_type,
            'post_name' => (string)$wp->post_name,
            'post_author' => (string)$wp->post_author,
            'post_parent' => (string)$wp->post_parent,
            'menu_order' => (string)$wp->menu_order,
            'comment_status' => (string)$wp->comment_status,
            'ping_status' => (string)$wp->ping_status,
            'post_password' => (string)$wp->post_password,
            'guid' => (string)$item->link,
            'post_mime_type' => (string)$wp->attachment_url ? $this->getMimeTypeFromUrl((string)$wp->attachment_url) : '',
            'comment_count' => 0 // Will be calculated later
        ];
        
        // Only add non-empty posts
        if (!empty($post['ID'])) {
            $this->posts[] = $post;
            
            // Parse post meta
            $this->parsePostMeta($wp->postmeta ?? [], $post['ID']);
            
            // Parse comments
            $this->parseComments($wp->comment ?? [], $post['ID']);
            
            // Parse categories and tags
            $this->parseTerms($item->category ?? []);
        }
    }
    
    protected function parsePostMeta($postmeta, string $postId): void
    {
        if (!$postmeta) {
            return;
        }
        
        // Handle single meta vs multiple meta
        if (!is_array($postmeta) && isset($postmeta->meta_key)) {
            $postmeta = [$postmeta];
        }
        
        foreach ($postmeta as $meta) {
            $metaKey = (string)$meta->meta_key;
            $metaValue = (string)$meta->meta_value;
            
            // Skip empty meta keys
            if (empty($metaKey)) {
                continue;
            }
            
            $this->postmeta[] = [
                'post_id' => $postId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue
            ];
        }
    }
    
    protected function parseComments($comments, string $postId): void
    {
        if (!$comments) {
            return;
        }
        
        // Handle single comment vs multiple comments
        if (!is_array($comments) && isset($comments->comment_id)) {
            $comments = [$comments];
        }
        
        foreach ($comments as $comment) {
            $commentData = [
                'comment_id' => (string)$comment->comment_id,
                'comment_post_ID' => $postId,
                'comment_author' => (string)$comment->comment_author,
                'comment_author_email' => (string)$comment->comment_author_email,
                'comment_author_url' => (string)$comment->comment_author_url,
                'comment_author_IP' => (string)$comment->comment_author_IP,
                'comment_date' => (string)$comment->comment_date,
                'comment_date_gmt' => (string)$comment->comment_date_gmt,
                'comment_content' => (string)$comment->comment_content,
                'comment_approved' => (string)$comment->comment_approved,
                'comment_type' => (string)$comment->comment_type,
                'comment_parent' => (string)$comment->comment_parent,
                'user_id' => (string)$comment->comment_user_id
            ];
            
            if (!empty($commentData['comment_id'])) {
                $this->comments[] = $commentData;
            }
        }
    }
    
    protected function parseTerms($categories): void
    {
        if (!$categories) {
            return;
        }
        
        // Handle single category vs multiple categories
        if (!is_array($categories) && isset($categories['domain'])) {
            $categories = [$categories];
        }
        
        foreach ($categories as $category) {
            $domain = (string)($category['domain'] ?? '');
            $nicename = (string)($category['nicename'] ?? '');
            $name = (string)$category;
            
            if (empty($name)) {
                continue;
            }
            
            $termData = [
                'name' => $name,
                'slug' => $nicename,
                'domain' => $domain
            ];
            
            if ($domain === 'category') {
                $this->categories[] = $termData;
            } elseif ($domain === 'post_tag') {
                $this->tags[] = $termData;
            }
        }
    }
    
    protected function calculateStatistics(): void
    {
        // Post type distribution
        $postTypes = [];
        foreach ($this->posts as $post) {
            $type = $post['post_type'];
            $postTypes[$type] = ($postTypes[$type] ?? 0) + 1;
        }
        
        // Post status distribution
        $postStatuses = [];
        foreach ($this->posts as $post) {
            $status = $post['post_status'];
            $postStatuses[$status] = ($postStatuses[$status] ?? 0) + 1;
        }
        
        // Meta keys frequency
        $metaKeys = [];
        foreach ($this->postmeta as $meta) {
            $key = $meta['meta_key'];
            $metaKeys[$key] = ($metaKeys[$key] ?? 0) + 1;
        }
        
        // Author distribution
        $authors = [];
        foreach ($this->posts as $post) {
            $author = $post['post_author'];
            $authors[$author] = ($authors[$author] ?? 0) + 1;
        }
        
        $this->statistics = [
            'total_posts' => count($this->posts),
            'total_postmeta' => count($this->postmeta),
            'total_comments' => count($this->comments),
            'total_categories' => count($this->categories),
            'total_tags' => count($this->tags),
            'post_types' => $postTypes,
            'post_statuses' => $postStatuses,
            'top_meta_keys' => array_slice(arsort($metaKeys) ? $metaKeys : [], 0, 20, true),
            'authors' => $authors,
            'date_range' => $this->getDateRange(),
        ];
    }
    
    protected function getDateRange(): array
    {
        if (empty($this->posts)) {
            return ['earliest' => null, 'latest' => null];
        }
        
        $dates = array_filter(array_map(fn($post) => $post['post_date'], $this->posts));
        
        if (empty($dates)) {
            return ['earliest' => null, 'latest' => null];
        }
        
        sort($dates);
        
        return [
            'earliest' => reset($dates),
            'latest' => end($dates)
        ];
    }
    
    protected function getMimeTypeFromUrl(string $url): string
    {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            default => 'application/octet-stream'
        };
    }
    
    public function getParsingReport(): array
    {
        return [
            'summary' => [
                'posts_parsed' => count($this->posts),
                'meta_records_parsed' => count($this->postmeta),
                'comments_parsed' => count($this->comments),
                'categories_parsed' => count($this->categories),
                'tags_parsed' => count($this->tags)
            ],
            'post_types_found' => array_keys($this->statistics['post_types'] ?? []),
            'most_common_meta_keys' => array_keys(array_slice($this->statistics['top_meta_keys'] ?? [], 0, 10)),
            'date_range' => $this->statistics['date_range'] ?? [],
            'content_analysis' => $this->analyzeContent()
        ];
    }
    
    protected function analyzeContent(): array
    {
        $analysis = [
            'has_featured_images' => false,
            'has_galleries' => false,
            'has_shortcodes' => false,
            'has_custom_fields' => false,
            'average_content_length' => 0,
            'content_types' => []
        ];
        
        if (empty($this->posts)) {
            return $analysis;
        }
        
        $totalLength = 0;
        $postCount = count($this->posts);
        
        foreach ($this->posts as $post) {
            $content = $post['post_content'];
            $totalLength += strlen($content);
            
            // Check for featured images (meta key)
            if (!$analysis['has_featured_images']) {
                foreach ($this->postmeta as $meta) {
                    if ($meta['post_id'] === $post['ID'] && $meta['meta_key'] === '_thumbnail_id') {
                        $analysis['has_featured_images'] = true;
                        break;
                    }
                }
            }
            
            // Check for galleries
            if (!$analysis['has_galleries'] && (strpos($content, '[gallery') !== false || strpos($content, 'wp-gallery') !== false)) {
                $analysis['has_galleries'] = true;
            }
            
            // Check for shortcodes
            if (!$analysis['has_shortcodes'] && preg_match('/\[[\w\-_]+/', $content)) {
                $analysis['has_shortcodes'] = true;
            }
            
            // Track content types
            $type = $post['post_type'];
            $analysis['content_types'][$type] = ($analysis['content_types'][$type] ?? 0) + 1;
        }
        
        // Check for custom fields
        $analysis['has_custom_fields'] = !empty($this->postmeta);
        
        $analysis['average_content_length'] = $postCount > 0 ? round($totalLength / $postCount) : 0;
        
        return $analysis;
    }
}