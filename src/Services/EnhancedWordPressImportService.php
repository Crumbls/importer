<?php

namespace Crumbls\Importer\Services;

use Crumbls\Importer\Support\ImportDriverManager;
use Illuminate\Support\Facades\DB;

/**
 * Enhanced WordPress XML import service with media and tags support
 */
class EnhancedWordPressImportService
{
    protected ImportDriverManager $driverManager;
    protected array $config;
    protected array $importStats = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'media_driver' => 'auto',
            'tags_driver' => 'auto',
            'download_media' => true,
            'import_categories' => true,
            'import_tags' => true,
            'import_attachments' => true,
            'import_comments' => false, // For future implementation
            'batch_size' => 50,
            'timeout' => 300,
        ], $config);

        $this->driverManager = new ImportDriverManager($this->config);
    }

    public function importFromXml(string $xmlPath): array
    {
        if (!file_exists($xmlPath)) {
            throw new \InvalidArgumentException("XML file not found: {$xmlPath}");
        }

        $this->initializeStats();

        DB::beginTransaction();
        
        try {
            // Load and parse XML
            $xml = simplexml_load_file($xmlPath);
            if (!$xml) {
                throw new \RuntimeException("Failed to parse XML file");
            }

            $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
            $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

            // Import in logical order
            $this->importAuthors($xml);
            
            // Extract and import categories/tags from post items inline
            if ($this->config['import_categories'] || $this->config['import_tags']) {
                $this->extractTaxonomiesFromPosts($xml);
            }
            
            $this->importPosts($xml);
            $this->importPages($xml);
            
            // Import attachments after posts so we can associate them
            if ($this->config['import_attachments']) {
                $this->importAttachments($xml);
            }

            DB::commit();
            
            return $this->getImportStats();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function extractTaxonomiesFromPosts(\SimpleXMLElement $xml): void
    {
        $posts = $xml->xpath('//item[wp:post_type="post"]');
        $tagsDriver = $this->driverManager->tagsDriver();
        $processedCategories = [];
        $processedTags = [];

        foreach ($posts as $post) {
            // Extract categories from this post
            if ($this->config['import_categories']) {
                $categories = $post->xpath('category[@domain="category"]');
                foreach ($categories as $category) {
                    $name = (string)$category;
                    $slug = (string)$category['nicename'] ?? null;
                    
                    if (!in_array($name, $processedCategories)) {
                        try {
                            $categoryData = [
                                'name' => $name,
                                'slug' => $slug,
                                'description' => '',
                            ];
                            
                            $tagsDriver->importCategory($categoryData);
                            $this->importStats['categories']['imported']++;
                            $processedCategories[] = $name;
                        } catch (\Exception $e) {
                            $this->importStats['categories']['failed']++;
                            $this->importStats['errors'][] = "Category import failed: " . $e->getMessage();
                        }
                    }
                }
            }
            
            // Extract tags from this post
            if ($this->config['import_tags']) {
                $tags = $post->xpath('category[@domain="post_tag"]');
                foreach ($tags as $tag) {
                    $name = (string)$tag;
                    $slug = (string)$tag['nicename'] ?? null;
                    
                    if (!in_array($name, $processedTags)) {
                        try {
                            $tagData = [
                                'name' => $name,
                                'slug' => $slug,
                                'description' => '',
                            ];
                            
                            $tagsDriver->importTag($tagData);
                            $this->importStats['tags']['imported']++;
                            $processedTags[] = $name;
                        } catch (\Exception $e) {
                            $this->importStats['tags']['failed']++;
                            $this->importStats['errors'][] = "Tag import failed: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }

    // This method is now replaced by extractTaxonomiesFromPosts()

    protected function importAttachments(\SimpleXMLElement $xml): void
    {
        $attachments = $xml->xpath('//item[wp:post_type="attachment"]');
        $mediaDriver = $this->driverManager->mediaDriver();

        foreach ($attachments as $attachment) {
            try {
                $attachmentData = $this->extractAttachmentData($attachment);
                $downloadUrl = $this->getAttachmentUrl($attachment);

                $media = $mediaDriver->importAttachment($attachmentData, $downloadUrl);
                $this->importStats['attachments']['imported']++;

            } catch (\Exception $e) {
                $this->importStats['attachments']['failed']++;
                $this->importStats['errors'][] = "Attachment import failed: " . $e->getMessage();
            }
        }
    }

    protected function importAuthors(\SimpleXMLElement $xml): void
    {
        $authors = $xml->xpath('//wp:author');

        foreach ($authors as $author) {
            try {
                $authorData = [
                    'wp_user_id' => (int)(string)$author->{'wp:author_id'},
                    'name' => (string)$author->{'wp:author_display_name'},
                    'email' => (string)$author->{'wp:author_email'},
                    'wp_username' => (string)$author->{'wp:author_login'},
                    'first_name' => (string)$author->{'wp:author_first_name'},
                    'last_name' => (string)$author->{'wp:author_last_name'},
                ];

                // Check for existing user
                $userClass = $this->getUserModelClass();
                $user = $userClass::where('email', $authorData['email'])
                    ->orWhere('wp_user_id', $authorData['wp_user_id'])
                    ->first();

                if (!$user) {
                    $user = $userClass::create(array_merge($authorData, [
                        'password' => bcrypt('password'),
                        'email_verified_at' => now(),
                    ]));
                    $this->importStats['authors']['imported']++;
                } else {
                    $user->update($authorData);
                    $this->importStats['authors']['updated']++;
                }

            } catch (\Exception $e) {
                $this->importStats['authors']['failed']++;
                $this->importStats['errors'][] = "Author import failed: " . $e->getMessage();
            }
        }
    }

    protected function importPosts(\SimpleXMLElement $xml): void
    {
        $posts = $xml->xpath('//item[wp:post_type="post"]');
        $tagsDriver = $this->driverManager->tagsDriver();
        $mediaDriver = $this->driverManager->mediaDriver();

        foreach ($posts as $post) {
            try {
                $postData = $this->extractPostData($post);
                
                // Check for existing post
                $postClass = $this->getPostModelClass();
                $existingPost = $postClass::where('wp_post_id', $postData['wp_post_id'])->first();

                if (!$existingPost) {
                    // Find author
                    $author = $this->findAuthor((string)$post->{'dc:creator'});
                    if ($author) {
                        $postData['user_id'] = $author->id;
                    }

                    $newPost = $postClass::create($postData);
                    
                    // Import tags and categories for this post
                    $this->attachPostTaxonomies($post, $newPost, $tagsDriver);
                    
                    // Set featured image if available
                    $this->setPostFeaturedImage($post, $newPost, $mediaDriver);
                    
                    $this->importStats['posts']['imported']++;
                } else {
                    $existingPost->update($postData);
                    $this->importStats['posts']['updated']++;
                }

            } catch (\Exception $e) {
                $this->importStats['posts']['failed']++;
                $this->importStats['errors'][] = "Post import failed: " . $e->getMessage();
            }
        }
    }

    protected function importPages(\SimpleXMLElement $xml): void
    {
        $pages = $xml->xpath('//item[wp:post_type="page"]');

        foreach ($pages as $page) {
            try {
                $pageData = $this->extractPostData($page, 'page');
                
                // Check for existing page
                $pageClass = $this->getPageModelClass();
                $existingPage = $pageClass::where('wp_post_id', $pageData['wp_post_id'])->first();

                if (!$existingPage) {
                    // Find author
                    $author = $this->findAuthor((string)$page->{'dc:creator'});
                    if ($author) {
                        $pageData['author_id'] = $author->id;
                    }

                    $newPage = $pageClass::create($pageData);
                    $this->importStats['pages']['imported']++;
                } else {
                    $existingPage->update($pageData);
                    $this->importStats['pages']['updated']++;
                }

            } catch (\Exception $e) {
                $this->importStats['pages']['failed']++;
                $this->importStats['errors'][] = "Page import failed: " . $e->getMessage();
            }
        }
    }

    protected function attachPostTaxonomies(\SimpleXMLElement $post, $postModel, $tagsDriver): void
    {
        // Get all categories for this post
        $categories = $post->xpath('category[@domain="category"]');
        $categoryNames = array_map(function($cat) {
            return (string)$cat;
        }, $categories);

        if (!empty($categoryNames)) {
            $tagsDriver->attachToModel($postModel, $categoryNames, 'category');
        }

        // Get all tags for this post
        $tags = $post->xpath('category[@domain="post_tag"]');
        $tagNames = array_map(function($tag) {
            return (string)$tag;
        }, $tags);

        if (!empty($tagNames)) {
            $tagsDriver->attachToModel($postModel, $tagNames, 'tag');
        }
    }

    protected function setPostFeaturedImage(\SimpleXMLElement $post, $postModel, $mediaDriver): void
    {
        // Look for featured image in post meta
        $metaItems = $post->xpath('wp:postmeta[wp:meta_key="_thumbnail_id"]');
        
        if (!empty($metaItems)) {
            $thumbnailId = (int)(string)$metaItems[0]->{'wp:meta_value'};
            
            if ($thumbnailId > 0) {
                try {
                    $mediaDriver->setFeaturedImage($postModel, $thumbnailId);
                } catch (\Exception $e) {
                    // Silently fail for now - featured image is not critical
                }
            }
        }
    }

    protected function extractAttachmentData(\SimpleXMLElement $attachment): array
    {
        return [
            'wp_id' => (int)(string)$attachment->{'wp:post_id'},
            'title' => (string)$attachment->title,
            'filename' => (string)$attachment->{'wp:post_name'},
            'description' => (string)$attachment->{'content:encoded'},
            'mime_type' => $this->getAttachmentMimeType($attachment),
            'size' => $this->getAttachmentSize($attachment),
            'alt_text' => $this->getAttachmentAltText($attachment),
        ];
    }

    protected function getAttachmentUrl(\SimpleXMLElement $attachment): ?string
    {
        return (string)$attachment->{'wp:attachment_url'} ?: null;
    }

    protected function getAttachmentMimeType(\SimpleXMLElement $attachment): string
    {
        $metaItems = $attachment->xpath('wp:postmeta[wp:meta_key="_wp_attached_file"]');
        if (!empty($metaItems)) {
            $filename = (string)$metaItems[0]->{'wp:meta_value'};
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            
            $mimeTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'pdf' => 'application/pdf',
                'mp4' => 'video/mp4',
                'zip' => 'application/zip',
            ];
            
            return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
        }
        
        return 'application/octet-stream';
    }

    protected function getAttachmentSize(\SimpleXMLElement $attachment): int
    {
        $metaItems = $attachment->xpath('wp:postmeta[wp:meta_key="_wp_attachment_metadata"]');
        if (!empty($metaItems)) {
            $metadata = unserialize((string)$metaItems[0]->{'wp:meta_value'});
            return $metadata['filesize'] ?? 0;
        }
        
        return 0;
    }

    protected function getAttachmentAltText(\SimpleXMLElement $attachment): string
    {
        $metaItems = $attachment->xpath('wp:postmeta[wp:meta_key="_wp_attachment_image_alt"]');
        if (!empty($metaItems)) {
            return (string)$metaItems[0]->{'wp:meta_value'};
        }
        
        return '';
    }

    protected function extractPostData(\SimpleXMLElement $item, string $type = 'post'): array
    {
        $content = (string)$item->{'content:encoded'};
        $content = $this->cleanWordPressContent($content);

        return [
            'wp_post_id' => (int)(string)$item->{'wp:post_id'},
            'title' => (string)$item->title,
            'content' => $content,
            'excerpt' => (string)$item->{'excerpt:encoded'},
            'status' => (string)$item->{'wp:status'},
            'slug' => (string)$item->{'wp:post_name'},
            'published_at' => $this->parseDate((string)$item->{'wp:post_date'}),
            'created_at' => $this->parseDate((string)$item->{'wp:post_date'}),
            'updated_at' => $this->parseDate((string)$item->{'wp:post_modified'}),
        ];
    }

    protected function cleanWordPressContent(string $content): string
    {
        // Remove WordPress block comments
        $content = preg_replace('/<!-- wp:.*? -->/s', '', $content);
        $content = preg_replace('/<!-- \/wp:.*? -->/s', '', $content);
        
        // Clean up extra whitespace
        $content = preg_replace('/\n\n+/', "\n\n", $content);
        $content = trim($content);
        
        return $content;
    }

    protected function parseDate(string $date): ?\Carbon\Carbon
    {
        if (empty($date) || $date === '0000-00-00 00:00:00') {
            return null;
        }
        
        try {
            return \Carbon\Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function findAuthor(string $creatorName)
    {
        $userClass = $this->getUserModelClass();
        return $userClass::where('name', $creatorName)->first();
    }

    protected function getUserModelClass(): string
    {
        return $this->config['user_model'] ?? '\App\Models\User';
    }

    protected function getPostModelClass(): string
    {
        return $this->config['post_model'] ?? '\App\Models\Post';
    }

    protected function getPageModelClass(): string
    {
        return $this->config['page_model'] ?? '\App\Models\Page';
    }

    protected function initializeStats(): void
    {
        $this->importStats = [
            'authors' => ['imported' => 0, 'updated' => 0, 'failed' => 0],
            'categories' => ['imported' => 0, 'updated' => 0, 'failed' => 0],
            'tags' => ['imported' => 0, 'updated' => 0, 'failed' => 0],
            'attachments' => ['imported' => 0, 'updated' => 0, 'failed' => 0],
            'posts' => ['imported' => 0, 'updated' => 0, 'failed' => 0],
            'pages' => ['imported' => 0, 'updated' => 0, 'failed' => 0],
            'errors' => [],
        ];
    }

    public function getImportStats(): array
    {
        return $this->importStats;
    }

    public function getAvailableDrivers(): array
    {
        return [
            'media' => $this->driverManager->getAvailableMediaDrivers(),
            'tags' => $this->driverManager->getAvailableTagsDrivers(),
        ];
    }
}