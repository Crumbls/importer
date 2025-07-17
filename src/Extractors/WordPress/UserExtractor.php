<?php

namespace Crumbls\Importer\Extractors\WordPress;

use Crumbls\Importer\Extractors\AbstractDataExtractor;
use DOMXPath;

class UserExtractor extends AbstractDataExtractor
{
    protected array $extractedUsers = []; // Cache to avoid duplicates
    
    public function getName(): string
    {
        return 'UserExtractor';
    }
    
    protected function performExtraction(DOMXPath $xpath, array $context = []): array
    {
        // Extract author from post context
        $authorLogin = $this->getXPathValue($xpath, './/dc:creator');
        
        if (empty($authorLogin)) {
            return [];
        }
        
        // Check if we already extracted this user
        if (isset($this->extractedUsers[$authorLogin])) {
            return []; // Already processed this user
        }
        
        // Generate user data
        $userData = $this->extractUserData($xpath, $authorLogin);
        
        if (!empty($userData)) {
            $this->extractedUsers[$authorLogin] = true;
            return $userData;
        }
        
        return [];
    }
    
    protected function extractUserData(DOMXPath $xpath, string $authorLogin): array
    {
        // Try to find additional user data in the XML
        $userEmail = $this->extractUserEmail($xpath, $authorLogin);
        $displayName = $this->extractDisplayName($xpath, $authorLogin);
        
        return $this->addTimestamps([
            'user_id' => $this->generateUserId($authorLogin),
            'user_login' => $this->sanitizeUsername($authorLogin),
            'user_email' => $userEmail,
            'user_nicename' => $this->sanitizeSlug($authorLogin),
            'display_name' => $displayName ?: $this->sanitizeString($authorLogin, 250),
            'user_registered' => date('Y-m-d H:i:s'),
            'user_status' => 0,
            'processed' => false,
        ]);
    }
    
    protected function extractUserEmail(DOMXPath $xpath, string $authorLogin): string
    {
        // WordPress XML might have user email in various places
        $possiblePaths = [
            ".//wp:author[wp:author_login='{$authorLogin}']/wp:author_email",
            ".//wp:author_email",
        ];
        
        foreach ($possiblePaths as $path) {
            $email = $this->getXPathValue($xpath, $path);
            if (!empty($email)) {
                return $this->sanitizeEmail($email);
            }
        }
        
        // Generate a placeholder email if none found
        return $this->generatePlaceholderEmail($authorLogin);
    }
    
    protected function extractDisplayName(DOMXPath $xpath, string $authorLogin): string
    {
        // Try to find display name
        $possiblePaths = [
            ".//wp:author[wp:author_login='{$authorLogin}']/wp:author_display_name",
            ".//wp:author_display_name",
        ];
        
        foreach ($possiblePaths as $path) {
            $displayName = $this->getXPathValue($xpath, $path);
            if (!empty($displayName)) {
                return $this->sanitizeString($displayName, 250);
            }
        }
        
        return '';
    }
    
    protected function generateUserId(string $authorLogin): int
    {
        return $this->generateSecureId('user', $authorLogin);
    }
    
    protected function sanitizeUsername(?string $username): string
    {
        if (empty($username)) {
            return '';
        }
        
        // WordPress username sanitization
        $username = trim($username);
        $username = preg_replace('/[^a-zA-Z0-9._\-@]/', '', $username);
        
        return substr($username, 0, 60); // WordPress username length limit
    }
    
    protected function generatePlaceholderEmail(string $username): string
    {
        // Create a deterministic but unique email
        $hash = substr(md5($username), 0, 8);
        $sanitizedUsername = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        
        return strtolower($sanitizedUsername . '.' . $hash . '@imported.local');
    }
    
    /**
     * Extract all unique users from the document
     */
    public function extractAllUsers(\DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $users = [];
        $processedLogins = [];
        
        // Find all dc:creator elements (WordPress authors)
        $authorNodes = $xpath->query('//dc:creator');
        
        foreach ($authorNodes as $authorNode) {
            $authorLogin = trim($authorNode->nodeValue);
            
            if (!empty($authorLogin) && !isset($processedLogins[$authorLogin])) {
                $userData = $this->extractUserData($xpath, $authorLogin);
                
                if (!empty($userData)) {
                    $users[] = $userData;
                    $processedLogins[$authorLogin] = true;
                }
            }
        }
        
        // Also check for wp:author sections if they exist
        $authorSections = $xpath->query('//wp:author');
        
        foreach ($authorSections as $authorSection) {
            $authorXpath = new DOMXPath($document);
            $authorXpath->setContextNode($authorSection);
            
            $authorLogin = $this->getXPathValue($authorXpath, './/wp:author_login');
            
            if (!empty($authorLogin) && !isset($processedLogins[$authorLogin])) {
                $userData = $this->extractDetailedUserData($authorXpath, $authorLogin);
                
                if (!empty($userData)) {
                    $users[] = $userData;
                    $processedLogins[$authorLogin] = true;
                }
            }
        }
        
        return $users;
    }
    
    protected function extractDetailedUserData(DOMXPath $xpath, string $authorLogin): array
    {
        return $this->addTimestamps([
            'user_id' => $this->generateUserId($authorLogin),
            'user_login' => $this->sanitizeUsername($authorLogin),
            'user_email' => $this->sanitizeEmail($this->getXPathValue($xpath, './/wp:author_email')),
            'user_nicename' => $this->sanitizeSlug($authorLogin),
            'display_name' => $this->sanitizeString(
                $this->getXPathValue($xpath, './/wp:author_display_name') ?: $authorLogin, 
                250
            ),
            'first_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:author_first_name'), 100),
            'last_name' => $this->sanitizeString($this->getXPathValue($xpath, './/wp:author_last_name'), 100),
            'user_registered' => date('Y-m-d H:i:s'),
            'user_status' => 0,
            'processed' => false,
        ]);
    }
    
    public function validate(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        
        // For arrays of user items
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $user) {
                if (!$this->validateSingleUser($user)) {
                    return false;
                }
            }
            return true;
        }
        
        // For single user item
        return $this->validateSingleUser($data);
    }
    
    protected function validateSingleUser(array $user): bool
    {
        // A valid user must have an ID and login
        return isset($user['user_id']) && 
               isset($user['user_login']) &&
               $user['user_id'] > 0 &&
               !empty($user['user_login']);
    }
    
    public function clearCache(): void
    {
        $this->extractedUsers = [];
    }
}