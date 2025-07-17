<?php

namespace Crumbls\Importer\Extractors\WordPress;

use Crumbls\Importer\Extractors\AbstractDataExtractor;
use DOMXPath;

class TermExtractor extends AbstractDataExtractor
{
    public function getName(): string
    {
        return 'TermExtractor';
    }
    
    protected function performExtraction(DOMXPath $xpath, array $context = []): array
    {
        $postId = $context['post_id'] ?? null;
        
        if (!$postId) {
            return []; // Cannot extract terms without a post ID
        }
        
        $terms = [];
        
        // Extract categories
        $categoryNodes = $xpath->query('.//category[@domain="category"]');
        foreach ($categoryNodes as $categoryNode) {
            $terms[] = $this->extractTermData($categoryNode, $postId, 'category');
        }
        
        // Extract post tags  
        $tagNodes = $xpath->query('.//category[@domain="post_tag"]');
        foreach ($tagNodes as $tagNode) {
            $terms[] = $this->extractTermData($tagNode, $postId, 'post_tag');
        }
        
        // Extract custom taxonomies
        $taxonomyNodes = $xpath->query('.//category[@domain and @domain!="category" and @domain!="post_tag"]');
        foreach ($taxonomyNodes as $taxonomyNode) {
            $domain = $taxonomyNode->getAttribute('domain');
            $terms[] = $this->extractTermData($taxonomyNode, $postId, $domain);
        }
        
        return array_filter($terms); // Remove any empty results
    }
    
    protected function extractTermData(\DOMElement $termNode, int $postId, string $taxonomy): ?array
    {
        $termSlug = $termNode->getAttribute('nicename');
        $termName = $termNode->nodeValue;
        
        if (empty($termSlug) && empty($termName)) {
            return null; // Skip if no usable term data
        }
        
        // Generate term ID if not available
        $termId = $this->generateTermId($termSlug, $termName, $taxonomy);
        
        return $this->addTimestamps([
            'term_id' => $termId,
            'post_id' => $postId,
            'taxonomy' => $this->sanitizeString($taxonomy, 32),
            'term_slug' => $this->sanitizeSlug($termSlug),
            'term_name' => $this->sanitizeString($termName, 255),
            'term_description' => '', // WordPress XML doesn't typically include descriptions
            'parent_term_id' => 0, // Would need additional parsing for hierarchical terms
            'processed' => false,
        ]);
    }
    
    protected function generateTermId(string $slug, string $name, string $taxonomy): int
    {
        // Create a consistent ID based on term data
        $components = array_filter([$slug, $name, $taxonomy]);
        return $this->generateSecureId(...$components);
    }
    
    protected function sanitizeSlug(?string $slug): string
    {
        if (empty($slug)) {
            return '';
        }
        
        // WordPress slug sanitization
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        return substr($slug, 0, 200); // Reasonable slug length limit
    }
    
    /**
     * Extract term relationships data for bulk processing
     */
    public function extractTermRelationships(DOMXPath $xpath, array $context = []): array
    {
        $postId = $context['post_id'] ?? null;
        
        if (!$postId) {
            return [];
        }
        
        $relationships = [];
        
        // Get all category/term assignments
        $termNodes = $xpath->query('.//category[@domain]');
        
        foreach ($termNodes as $termNode) {
            $taxonomy = $termNode->getAttribute('domain');
            $termSlug = $termNode->getAttribute('nicename');
            $termName = $termNode->nodeValue;
            
            if (!empty($termSlug) || !empty($termName)) {
                $termId = $this->generateTermId($termSlug, $termName, $taxonomy);
                
                $relationships[] = [
                    'post_id' => $postId,
                    'term_id' => $termId,
                    'taxonomy' => $this->sanitizeString($taxonomy, 32),
                    'term_order' => 0, // WordPress doesn't typically use term order
                ];
            }
        }
        
        return $relationships;
    }
    
    /**
     * Extract unique terms for taxonomy management
     */
    public function extractUniqueTerms(DOMXPath $xpath): array
    {
        $uniqueTerms = [];
        $termNodes = $xpath->query('.//category[@domain]');
        
        foreach ($termNodes as $termNode) {
            $taxonomy = $termNode->getAttribute('domain');
            $termSlug = $termNode->getAttribute('nicename');
            $termName = $termNode->nodeValue;
            
            if (!empty($termSlug) || !empty($termName)) {
                $termId = $this->generateTermId($termSlug, $termName, $taxonomy);
                $termKey = $taxonomy . ':' . $termId;
                
                if (!isset($uniqueTerms[$termKey])) {
                    $uniqueTerms[$termKey] = $this->addTimestamps([
                        'term_id' => $termId,
                        'taxonomy' => $this->sanitizeString($taxonomy, 32),
                        'term_slug' => $this->sanitizeSlug($termSlug),
                        'term_name' => $this->sanitizeString($termName, 255),
                        'term_description' => '',
                        'parent_term_id' => 0,
                        'term_count' => 0, // Will be calculated later
                    ]);
                }
            }
        }
        
        return array_values($uniqueTerms);
    }
    
    public function validate(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        
        // For arrays of term items
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $term) {
                if (!$this->validateSingleTerm($term)) {
                    return false;
                }
            }
            return true;
        }
        
        // For single term item
        return $this->validateSingleTerm($data);
    }
    
    protected function validateSingleTerm(array $term): bool
    {
        // A valid term must have an ID, taxonomy, and name or slug
        return isset($term['term_id']) && 
               isset($term['taxonomy']) &&
               $term['term_id'] > 0 &&
               !empty($term['taxonomy']) &&
               (!empty($term['term_name']) || !empty($term['term_slug']));
    }
}