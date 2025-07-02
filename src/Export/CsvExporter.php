<?php

namespace Crumbls\Importer\Export;

use Crumbls\Importer\Contracts\ExportResult as ExportResultContract;
use Crumbls\Importer\Export\ExportResult;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Adapters\Traits\HasFileValidation;

class CsvExporter
{
    use HasFileValidation;
    
    protected string $delimiter = ',';
    protected string $enclosure = '"';
    protected string $escape = '\\';
    protected bool $includeHeaders = true;
    protected array $headers = [];
    protected array $columnMapping = [];
    protected int $chunkSize = 1000;
    protected $transformer = null;
    
    public function delimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;
        return $this;
    }
    
    public function enclosure(string $enclosure): self
    {
        $this->enclosure = $enclosure;
        return $this;
    }
    
    public function escape(string $escape): self
    {
        $this->escape = $escape;
        return $this;
    }
    
    public function withHeaders(array $headers = null): self
    {
        $this->includeHeaders = true;
        if ($headers !== null) {
            $this->headers = $headers;
        }
        return $this;
    }
    
    public function withoutHeaders(): self
    {
        $this->includeHeaders = false;
        return $this;
    }
    
    public function mapColumns(array $mapping): self
    {
        $this->columnMapping = $mapping;
        return $this;
    }
    
    public function chunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }
    
    public function transform(callable $transformer): self
    {
        $this->transformer = $transformer;
        return $this;
    }
    
    public function exportFromStorage(StorageReader $reader, string $destination): ExportResultContract
    {
        $startTime = microtime(true);
        $exported = 0;
        $failed = 0;
        $errors = [];
        
        try {
            // Ensure destination directory exists
            if (!$this->ensureDirectoryExists($destination)) {
                throw new \RuntimeException("Cannot create destination directory: " . dirname($destination));
            }
            
            $handle = fopen($destination, 'w');
            if (!$handle) {
                throw new \RuntimeException("Cannot open destination file for writing: {$destination}");
            }
            
            // Write headers if enabled
            if ($this->includeHeaders) {
                $headers = $this->getHeaders($reader);
                if (!empty($headers)) {
                    fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->escape);
                }
            }
            
            // Export data in chunks
            $reader->chunk($this->chunkSize, function ($rows) use ($handle, &$exported, &$failed, &$errors) {
                foreach ($rows as $row) {
                    try {
                        $processedRow = $this->processRow($row);
                        if ($processedRow !== null) {
                            fputcsv($handle, $processedRow, $this->delimiter, $this->enclosure, $this->escape);
                            $exported++;
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        $errors[] = "Row {$exported}: " . $e->getMessage();
                    }
                }
            });
            
            fclose($handle);
            
            $duration = microtime(true) - $startTime;
            
            return new ExportResult(
                exported: $exported,
                failed: $failed,
                errors: $errors,
                destination: $destination,
                format: 'csv',
                stats: [
                    'file_size' => filesize($destination),
                    'delimiter' => $this->delimiter,
                    'include_headers' => $this->includeHeaders
                ],
                duration: $duration
            );
            
        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            
            return new ExportResult(
                exported: $exported,
                failed: $failed + 1,
                errors: array_merge($errors, ['Export failed: ' . $e->getMessage()]),
                destination: $destination,
                format: 'csv',
                duration: microtime(true) - $startTime
            );
        }
    }
    
    public function exportFromArray(array $data, string $destination): ExportResultContract
    {
        $startTime = microtime(true);
        $exported = 0;
        $failed = 0;
        $errors = [];
        
        try {
            if (!$this->ensureDirectoryExists($destination)) {
                throw new \RuntimeException("Cannot create destination directory: " . dirname($destination));
            }
            
            $handle = fopen($destination, 'w');
            if (!$handle) {
                throw new \RuntimeException("Cannot open destination file for writing: {$destination}");
            }
            
            // Write headers if enabled and data is not empty
            if ($this->includeHeaders && !empty($data)) {
                $headers = $this->headers ?: array_keys($data[0]);
                fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->escape);
            }
            
            // Export data
            foreach ($data as $row) {
                try {
                    $processedRow = $this->processRow($row);
                    if ($processedRow !== null) {
                        fputcsv($handle, $processedRow, $this->delimiter, $this->enclosure, $this->escape);
                        $exported++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Row {$exported}: " . $e->getMessage();
                }
            }
            
            fclose($handle);
            
            $duration = microtime(true) - $startTime;
            
            return new ExportResult(
                exported: $exported,
                failed: $failed,
                errors: $errors,
                destination: $destination,
                format: 'csv',
                stats: [
                    'file_size' => filesize($destination),
                    'delimiter' => $this->delimiter,
                    'include_headers' => $this->includeHeaders
                ],
                duration: $duration
            );
            
        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            
            return new ExportResult(
                exported: $exported,
                failed: $failed + 1,
                errors: array_merge($errors, ['Export failed: ' . $e->getMessage()]),
                destination: $destination,
                format: 'csv',
                duration: microtime(true) - $startTime
            );
        }
    }
    
    protected function getHeaders(StorageReader $reader): array
    {
        if (!empty($this->headers)) {
            return $this->headers;
        }
        
        // Get headers from storage reader
        return $reader->getHeaders() ?? [];
    }
    
    protected function processRow(array $row): ?array
    {
        // Apply column mapping if configured
        if (!empty($this->columnMapping)) {
            $mappedRow = [];
            foreach ($this->columnMapping as $oldKey => $newKey) {
                $mappedRow[$newKey] = $row[$oldKey] ?? '';
            }
            $row = $mappedRow;
        }
        
        // Apply transformation if configured
        if ($this->transformer) {
            $row = call_user_func($this->transformer, $row);
            
            // If transformer returns null, skip this row
            if ($row === null) {
                return null;
            }
        }
        
        // Ensure row is an indexed array for CSV writing
        return array_values($row);
    }
}