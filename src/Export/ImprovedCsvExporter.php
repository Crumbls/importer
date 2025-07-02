<?php

declare(strict_types=1);

namespace Crumbls\Importer\Export;

use Crumbls\Importer\Contracts\ExportResult as ExportResultContract;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Adapters\Traits\HasFileValidation;
use Crumbls\Importer\Types\SchemaTypes;

/**
 * Improved CSV Exporter with PHPStan Level 4 compliance
 * 
 * @phpstan-import-type TransformerCallback from SchemaTypes
 * @phpstan-import-type ChunkCallback from SchemaTypes
 */
class ImprovedCsvExporter
{
    use HasFileValidation;
    
    private string $delimiter = ',';
    private string $enclosure = '"';
    private string $escape = '\\';
    private bool $includeHeaders = true;
    
    /** @var list<string> */
    private array $headers = [];
    
    /** @var array<string, string> */
    private array $columnMapping = [];
    
    private int $chunkSize = 1000;
    
    /** @var TransformerCallback|null */
    private $transformer = null;
    
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
    
    /**
     * @param list<string>|null $headers
     */
    public function withHeaders(?array $headers = null): self
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
    
    /**
     * @param array<string, string> $mapping
     */
    public function mapColumns(array $mapping): self
    {
        $this->columnMapping = $mapping;
        return $this;
    }
    
    public function chunkSize(int $size): self
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be greater than 0');
        }
        
        $this->chunkSize = $size;
        return $this;
    }
    
    /**
     * @param TransformerCallback $transformer
     */
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
                throw new \RuntimeException(
                    "Cannot create destination directory: " . dirname($destination)
                );
            }
            
            $handle = fopen($destination, 'w');
            if ($handle === false) {
                throw new \RuntimeException(
                    "Cannot open destination file for writing: {$destination}"
                );
            }
            
            // Write headers if enabled
            if ($this->includeHeaders) {
                $headers = $this->getHeaders($reader);
                if (!empty($headers)) {
                    $headerResult = fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->escape);
                    if ($headerResult === false) {
                        throw new \RuntimeException('Failed to write CSV headers');
                    }
                }
            }
            
            // Export data in chunks
            $reader->chunk($this->chunkSize, function (array $rows) use ($handle, &$exported, &$failed, &$errors): void {
                foreach ($rows as $row) {
                    try {
                        $processedRow = $this->processRow($row);
                        if ($processedRow !== null) {
                            $writeResult = fputcsv($handle, $processedRow, $this->delimiter, $this->enclosure, $this->escape);
                            if ($writeResult === false) {
                                throw new \RuntimeException('Failed to write CSV row');
                            }
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
                    'file_size' => filesize($destination) ?: 0,
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
    
    /**
     * @param list<array<string, mixed>> $data
     */
    public function exportFromArray(array $data, string $destination): ExportResultContract
    {
        $startTime = microtime(true);
        $exported = 0;
        $failed = 0;
        $errors = [];
        
        try {
            if (!$this->ensureDirectoryExists($destination)) {
                throw new \RuntimeException(
                    "Cannot create destination directory: " . dirname($destination)
                );
            }
            
            $handle = fopen($destination, 'w');
            if ($handle === false) {
                throw new \RuntimeException(
                    "Cannot open destination file for writing: {$destination}"
                );
            }
            
            // Write headers if enabled and data is not empty
            if ($this->includeHeaders && !empty($data)) {
                $headers = $this->headers ?: array_keys($data[0]);
                $headerResult = fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->escape);
                if ($headerResult === false) {
                    throw new \RuntimeException('Failed to write CSV headers');
                }
            }
            
            // Export data
            foreach ($data as $row) {
                try {
                    $processedRow = $this->processRow($row);
                    if ($processedRow !== null) {
                        $writeResult = fputcsv($handle, $processedRow, $this->delimiter, $this->enclosure, $this->escape);
                        if ($writeResult === false) {
                            throw new \RuntimeException('Failed to write CSV row');
                        }
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
                    'file_size' => filesize($destination) ?: 0,
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
    
    /**
     * @return list<string>
     */
    private function getHeaders(StorageReader $reader): array
    {
        if (!empty($this->headers)) {
            return $this->headers;
        }
        
        // Get headers from storage reader
        return $reader->getHeaders() ?? [];
    }
    
    /**
     * @param array<string, mixed> $row
     * @return list<mixed>|null
     */
    private function processRow(array $row): ?array
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
        if ($this->transformer !== null) {
            $transformedRow = ($this->transformer)($row);
            
            // If transformer returns null, skip this row
            if ($transformedRow === null) {
                return null;
            }
            
            $row = $transformedRow;
        }
        
        // Ensure row is an indexed array for CSV writing
        return array_values($row);
    }
}