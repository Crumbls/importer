<?php

namespace Crumbls\Importer\Adapters\Traits;

trait HasFileValidation
{
    protected function validateFile(string $path): bool
    {
        return $this->isReadableFile($path) && $this->hasValidSize($path);
    }
    
    protected function isReadableFile(string $path): bool
    {
        return file_exists($path) && is_readable($path) && is_file($path);
    }
    
    public function getFileInfo(string $path): array
    {
        if (!$this->isReadableFile($path)) {
            return [
                'exists' => file_exists($path),
                'readable' => false,
                'size' => 0,
                'formatted_size' => '0 B',
                'extension' => '',
                'mime_type' => null,
                'last_modified' => null
            ];
        }
        
        $size = filesize($path);
        $pathInfo = pathinfo($path);
        
        return [
            'exists' => true,
            'readable' => true,
            'size' => $size,
            'formatted_size' => $this->formatBytes($size),
            'extension' => $pathInfo['extension'] ?? '',
            'mime_type' => $this->getMimeType($path),
            'last_modified' => filemtime($path)
        ];
    }
    
    protected function ensureDirectoryExists(string $path): bool
    {
        $directory = dirname($path);
        
        if (!is_dir($directory)) {
            return mkdir($directory, 0755, true);
        }
        
        return is_writable($directory);
    }
    
    protected function generateSafePath(string $basePath, string $filename): string
    {
        // Remove any dangerous characters from filename
        $safeFilename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $filename);
        
        // Prevent directory traversal
        $safeFilename = basename($safeFilename);
        
        return rtrim($basePath, '/') . '/' . $safeFilename;
    }
    
    protected function validateFileExtension(string $path, array $allowedExtensions): bool
    {
        $pathInfo = pathinfo($path);
        $extension = strtolower($pathInfo['extension'] ?? '');
        
        $normalizedExtensions = array_map('strtolower', $allowedExtensions);
        
        return in_array($extension, $normalizedExtensions);
    }
    
    protected function hasValidSize(string $path, int $maxSize = null): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        
        $size = filesize($path);
        
        // Check if file is empty
        if ($size === 0) {
            return false;
        }
        
        // Check against max size if provided
        if ($maxSize !== null && $size > $maxSize) {
            return false;
        }
        
        return true;
    }
    
    protected function getMimeType(string $path): ?string
    {
        if (!$this->isReadableFile($path)) {
            return null;
        }
        
        if (function_exists('mime_content_type')) {
            return mime_content_type($path);
        }
        
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $path);
            finfo_close($finfo);
            return $mimeType ?: null;
        }
        
        // Fallback to extension-based detection
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'csv' => 'text/csv',
            'xml' => 'application/xml',
            'json' => 'application/json',
            'txt' => 'text/plain',
            default => 'application/octet-stream'
        };
    }
    
    protected function isFileLocked(string $path): bool
    {
        if (!$this->isReadableFile($path)) {
            return false;
        }
        
        $handle = fopen($path, 'r');
        if (!$handle) {
            return true; // Can't open, assume locked
        }
        
        $locked = !flock($handle, LOCK_EX | LOCK_NB);
        
        if (!$locked) {
            flock($handle, LOCK_UN); // Release the lock if we got it
        }
        
        fclose($handle);
        
        return $locked;
    }
    
    // This method should be implemented by classes using this trait
    // or use the HasPerformanceMonitoring trait
    protected function formatBytes(int $bytes): string
    {
        if (method_exists($this, 'formatBytes') && get_called_class() !== __CLASS__) {
            return $this->formatBytes($bytes);
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}