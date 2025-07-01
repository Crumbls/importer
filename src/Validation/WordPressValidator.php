<?php

namespace Crumbls\Importer\Validation;

use Crumbls\Importer\Contracts\DataValidator;
use Crumbls\Importer\Contracts\ValidationResult;
use Crumbls\Importer\Exceptions\ValidationException;

class WordPressValidator implements DataValidator
{
    protected array $config;
    protected array $customRules = [];
    
    protected array $defaultRules = [
        'posts' => [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['string'],
            'post_type' => ['required', 'string', 'max:20'],
            'status' => ['required', 'in:publish,draft,private,pending,trash'],
            'post_date' => ['date'],
            'guid' => ['url'],
            'post_parent' => ['integer', 'min:0'],
            'menu_order' => ['integer'],
        ],
        'postmeta' => [
            'post_id' => ['required', 'integer', 'min:1'],
            'meta_key' => ['required', 'string', 'max:255'],
            'meta_value' => ['string'],
        ],
        'users' => [
            'author_login' => ['required', 'string', 'max:60', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'author_email' => ['required', 'email', 'max:100'],
            'author_display_name' => ['string', 'max:250'],
        ],
        'comments' => [
            'comment_id' => ['integer', 'min:1'],
            'comment_post_id' => ['required', 'integer', 'min:1'],
            'author' => ['string', 'max:245'],
            'author_email' => ['email', 'max:100'],
            'author_url' => ['url', 'max:200'],
            'content' => ['required', 'string'],
            'approved' => ['in:0,1,spam,trash'],
            'comment_date' => ['date'],
        ]
    ];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'strict_mode' => false,
            'max_content_length' => 65535,
            'allow_html' => true,
            'sanitize_data' => true,
            'check_encoding' => true,
            'validate_relationships' => true
        ], $config);
    }
    
    public function validateRecord(array $record, string $entityType): ValidationResult
    {
        $errors = [];
        $warnings = [];
        
        $rules = $this->getRules($entityType);
        
        foreach ($rules as $field => $fieldRules) {
            $value = $record[$field] ?? null;
            $fieldErrors = $this->validateField($field, $value, $fieldRules, $record);
            
            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }
        
        // Custom validation for specific entity types
        $customValidation = $this->performCustomValidation($record, $entityType);
        if (!empty($customValidation['errors'])) {
            $errors = array_merge($errors, $customValidation['errors']);
        }
        if (!empty($customValidation['warnings'])) {
            $warnings = array_merge($warnings, $customValidation['warnings']);
        }
        
        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors,
            warnings: $warnings
        );
    }
    
    public function validateBatch(array $records, string $entityType): ValidationResult
    {
        $allErrors = [];
        $allWarnings = [];
        $validCount = 0;
        
        foreach ($records as $index => $record) {
            $result = $this->validateRecord($record, $entityType);
            
            if ($result->isValid()) {
                $validCount++;
            } else {
                $allErrors["record_{$index}"] = $result->getErrors();
            }
            
            if (!empty($result->getWarnings())) {
                $allWarnings["record_{$index}"] = $result->getWarnings();
            }
        }
        
        return new ValidationResult(
            isValid: empty($allErrors),
            errors: $allErrors,
            warnings: $allWarnings,
            suggestions: [
                "validation_summary" => [
                    'total_records' => count($records),
                    'valid_records' => $validCount,
                    'invalid_records' => count($allErrors),
                    'records_with_warnings' => count($allWarnings)
                ]
            ]
        );
    }
    
    public function validateRelationships(array $data): ValidationResult
    {
        $errors = [];
        $warnings = [];
        
        if (!$this->config['validate_relationships']) {
            return new ValidationResult(true);
        }
        
        // Validate post-user relationships
        if (isset($data['posts']) && isset($data['users'])) {
            $userLogins = array_column($data['users'], 'author_login');
            
            foreach ($data['posts'] as $index => $post) {
                $author = $post['author'] ?? null;
                if ($author && !in_array($author, $userLogins)) {
                    $warnings["posts.{$index}"] = "Post references unknown user: {$author}";
                }
            }
        }
        
        // Validate post-postmeta relationships
        if (isset($data['posts']) && isset($data['postmeta'])) {
            $postIds = array_column($data['posts'], 'post_id');
            
            foreach ($data['postmeta'] as $index => $meta) {
                $postId = $meta['post_id'] ?? null;
                if ($postId && !in_array($postId, $postIds)) {
                    $warnings["postmeta.{$index}"] = "Meta references unknown post ID: {$postId}";
                }
            }
        }
        
        // Validate comment-post relationships
        if (isset($data['comments']) && isset($data['posts'])) {
            $postIds = array_column($data['posts'], 'post_id');
            
            foreach ($data['comments'] as $index => $comment) {
                $postId = $comment['comment_post_id'] ?? null;
                if ($postId && !in_array($postId, $postIds)) {
                    $warnings["comments.{$index}"] = "Comment references unknown post ID: {$postId}";
                }
            }
        }
        
        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors,
            warnings: $warnings
        );
    }
    
    public function validateIntegrity(array $data): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $suggestions = [];
        
        // Check for duplicate records
        foreach ($data as $entityType => $records) {
            $duplicates = $this->findDuplicates($records, $entityType);
            if (!empty($duplicates)) {
                $warnings[$entityType] = "Found {$duplicates['count']} duplicate records";
                $suggestions[$entityType] = 'Consider deduplication before migration';
            }
        }
        
        // Check data consistency
        if (isset($data['posts'])) {
            $postTypes = array_count_values(array_column($data['posts'], 'post_type'));
            if (isset($postTypes['']) || isset($postTypes[null])) {
                $warnings['posts'] = 'Some posts have empty post_type';
            }
        }
        
        // Check encoding issues
        if ($this->config['check_encoding']) {
            foreach ($data as $entityType => $records) {
                $encodingIssues = $this->checkEncoding($records);
                if ($encodingIssues > 0) {
                    $warnings[$entityType] = "Found {$encodingIssues} records with encoding issues";
                }
            }
        }
        
        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors,
            warnings: $warnings,
            suggestions: $suggestions
        );
    }
    
    public function getRules(string $entityType): array
    {
        $rules = $this->defaultRules[$entityType] ?? [];
        
        if (isset($this->customRules[$entityType])) {
            $rules = array_merge($rules, $this->customRules[$entityType]);
        }
        
        return $rules;
    }
    
    public function addRule(string $entityType, string $field, callable $rule): self
    {
        if (!isset($this->customRules[$entityType])) {
            $this->customRules[$entityType] = [];
        }
        
        $this->customRules[$entityType][$field][] = $rule;
        
        return $this;
    }
    
    public function getConfig(): array
    {
        return $this->config;
    }
    
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }
    
    protected function validateField(string $field, $value, array $rules, array $record): array
    {
        $errors = [];
        
        foreach ($rules as $rule) {
            if (is_callable($rule) && !is_string($rule)) {
                $result = $rule($value, $field, $record);
                if ($result !== true && is_string($result)) {
                    $errors[] = $result;
                }
                continue;
            }
            
            if (is_string($rule)) {
                $error = $this->applyRule($rule, $field, $value);
                if ($error) {
                    $errors[] = $error;
                }
            }
        }
        
        return $errors;
    }
    
    protected function applyRule(string $rule, string $field, $value): ?string
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $ruleValue = $parts[1] ?? null;
        
        return match ($ruleName) {
            'required' => empty($value) ? "{$field} is required" : null,
            'string' => !is_string($value) && $value !== null ? "{$field} must be a string" : null,
            'integer' => !is_numeric($value) && $value !== null ? "{$field} must be an integer" : null,
            'email' => $value && !filter_var($value, FILTER_VALIDATE_EMAIL) ? "{$field} must be a valid email" : null,
            'url' => $value && !filter_var($value, FILTER_VALIDATE_URL) ? "{$field} must be a valid URL" : null,
            'date' => $value && !strtotime($value) ? "{$field} must be a valid date" : null,
            'max' => strlen((string)$value) > (int)$ruleValue ? "{$field} cannot exceed {$ruleValue} characters" : null,
            'min' => (int)$value < (int)$ruleValue ? "{$field} must be at least {$ruleValue}" : null,
            'in' => $value && !in_array($value, explode(',', $ruleValue)) ? "{$field} must be one of: {$ruleValue}" : null,
            'regex' => $value && !preg_match($ruleValue, $value) ? "{$field} format is invalid" : null,
            default => null
        };
    }
    
    protected function performCustomValidation(array $record, string $entityType): array
    {
        $errors = [];
        $warnings = [];
        
        switch ($entityType) {
            case 'posts':
                // Check for dangerous content
                if ($this->config['allow_html'] === false) {
                    $content = $record['content'] ?? '';
                    if (strip_tags($content) !== $content) {
                        $warnings['content'] = 'HTML content detected but HTML is not allowed';
                    }
                }
                
                // Check content length
                $contentLength = strlen($record['content'] ?? '');
                if ($contentLength > $this->config['max_content_length']) {
                    $errors['content'] = "Content too long ({$contentLength} chars, max {$this->config['max_content_length']})";
                }
                
                break;
                
            case 'users':
                // Check for reserved usernames
                $reservedUsernames = ['admin', 'administrator', 'root', 'test'];
                $username = strtolower($record['author_login'] ?? '');
                if (in_array($username, $reservedUsernames)) {
                    $warnings['author_login'] = 'Username may conflict with system accounts';
                }
                
                break;
        }
        
        return ['errors' => $errors, 'warnings' => $warnings];
    }
    
    protected function findDuplicates(array $records, string $entityType): array
    {
        $uniqueKeys = match ($entityType) {
            'posts' => ['guid'],
            'users' => ['author_login', 'author_email'],
            'comments' => ['comment_id'],
            'postmeta' => ['post_id', 'meta_key'],
            default => []
        };
        
        if (empty($uniqueKeys)) {
            return [];
        }
        
        $seen = [];
        $duplicates = 0;
        
        foreach ($records as $record) {
            $key = '';
            foreach ($uniqueKeys as $field) {
                $key .= ($record[$field] ?? '') . '|';
            }
            
            if (isset($seen[$key])) {
                $duplicates++;
            } else {
                $seen[$key] = true;
            }
        }
        
        return ['count' => $duplicates, 'unique_keys' => $uniqueKeys];
    }
    
    protected function checkEncoding(array $records): int
    {
        $issues = 0;
        
        foreach ($records as $record) {
            foreach ($record as $value) {
                if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $issues++;
                    break; // Only count once per record
                }
            }
        }
        
        return $issues;
    }
}