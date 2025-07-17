<?php

namespace Crumbls\Importer\Extractors\WordPress;

use Crumbls\Importer\Extractors\AbstractDataExtractor;
use DOMXPath;

class CommentExtractor extends AbstractDataExtractor
{
    public function getName(): string
    {
        return 'CommentExtractor';
    }
    
    protected function performExtraction(DOMXPath $xpath, array $context = []): array
    {
        $postId = $context['post_id'] ?? null;
        
        if (!$postId) {
            return []; // Cannot extract comments without a post ID
        }
        
        $comments = [];
        $commentNodes = $xpath->query('.//wp:comment');
        
        foreach ($commentNodes as $commentNode) {
            $commentXpath = new DOMXPath($commentNode->ownerDocument);
            $commentXpath->setContextNode($commentNode);
            
            $commentId = $this->extractCommentId($commentXpath);
            
            if ($commentId) {
                $comments[] = $this->addTimestamps([
                    'comment_id' => $commentId,
                    'post_id' => $postId,
                    'comment_author' => $this->sanitizeString(
                        $this->getXPathValue($commentXpath, './/wp:comment_author'), 255
                    ),
                    'comment_author_email' => $this->sanitizeEmail(
                        $this->getXPathValue($commentXpath, './/wp:comment_author_email')
                    ),
                    'comment_author_url' => $this->sanitizeUrl(
                        $this->getXPathValue($commentXpath, './/wp:comment_author_url')
                    ),
                    'comment_author_ip' => $this->sanitizeIpAddress(
                        $this->getXPathValue($commentXpath, './/wp:comment_author_IP')
                    ),
                    'comment_date' => $this->convertWordPressDate(
                        $this->getXPathValue($commentXpath, './/wp:comment_date')
                    ),
                    'comment_date_gmt' => $this->convertWordPressDate(
                        $this->getXPathValue($commentXpath, './/wp:comment_date_gmt')
                    ),
                    'comment_content' => $this->sanitizeString(
                        $this->getXPathValue($commentXpath, './/wp:comment_content')
                    ),
                    'comment_approved' => $this->normalizeApprovalStatus(
                        $this->getXPathValue($commentXpath, './/wp:comment_approved')
                    ),
                    'comment_type' => $this->sanitizeString(
                        $this->getXPathValue($commentXpath, './/wp:comment_type'), 20
                    ),
                    'comment_parent' => $this->sanitizeInteger(
                        $this->getXPathValue($commentXpath, './/wp:comment_parent')
                    ),
                    'user_id' => $this->sanitizeInteger(
                        $this->getXPathValue($commentXpath, './/wp:comment_user_id')
                    ),
                    'processed' => false,
                ]);
            }
        }
        
        return $comments;
    }
    
    protected function extractCommentId(DOMXPath $xpath): ?int
    {
        $commentId = $this->getXPathValue($xpath, './/wp:comment_id');
        
        if ($commentId && is_numeric($commentId)) {
            return (int) $commentId;
        }
        
        // Generate fallback ID if none exists
        $author = $this->getXPathValue($xpath, './/wp:comment_author');
        $date = $this->getXPathValue($xpath, './/wp:comment_date');
        $content = $this->getXPathValue($xpath, './/wp:comment_content');
        
        if ($author || $date || $content) {
            return $this->generateSecureId($author, $date, substr($content, 0, 100));
        }
        
        return null;
    }
    
    protected function sanitizeIpAddress(?string $ip): string
    {
        if (empty($ip)) {
            return '';
        }
        
        // Validate IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        
        // Validate IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        
        // Invalid IP, return empty string
        return '';
    }
    
    protected function normalizeApprovalStatus(?string $status): string
    {
        if (empty($status)) {
            return '0'; // Default to not approved
        }
        
        // Normalize different approval status formats
        $status = strtolower(trim($status));
        
        switch ($status) {
            case '1':
            case 'approve':
            case 'approved':
            case 'true':
                return '1';
                
            case 'spam':
                return 'spam';
                
            case 'trash':
                return 'trash';
                
            case '0':
            case 'hold':
            case 'pending':
            case 'false':
            default:
                return '0';
        }
    }
    
    public function validate(array $data): bool
    {
        // Comments data can be an array of comment items
        if (empty($data)) {
            return false;
        }
        
        // For arrays of comment items
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $comment) {
                if (!$this->validateSingleComment($comment)) {
                    return false;
                }
            }
            return true;
        }
        
        // For single comment item
        return $this->validateSingleComment($data);
    }
    
    protected function validateSingleComment(array $comment): bool
    {
        // A valid comment must have an ID, post_id, and either author or content
        return isset($comment['comment_id']) && 
               isset($comment['post_id']) &&
               $comment['comment_id'] > 0 &&
               $comment['post_id'] > 0 &&
               (!empty($comment['comment_author']) || !empty($comment['comment_content']));
    }
}