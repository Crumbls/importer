<?php

namespace Crumbls\Importer\Parser;

class StreamingCsvParser
{
    protected $handle;
    protected string $delimiter;
    protected string $enclosure;
    protected string $escape;
    protected bool $hasHeaders;
    protected array $headers = [];
    protected int $currentLine = 0;
    protected int $bufferSize;
    protected array $validationRules = [];
    protected bool $skipInvalidRows = false;
    protected int $maxErrors = 1000;
    protected array $errors = [];
    protected array $validationWarnings = [];
    protected int $bytesRead = 0;
    protected int $totalBytes = 0;
    
    public function __construct(
        string $filePath,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        bool $hasHeaders = true,
        int $bufferSize = 8192
    ) {
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->escape = $escape;
        $this->hasHeaders = $hasHeaders;
        $this->bufferSize = $bufferSize;
        
        $this->handle = fopen($filePath, 'r');
        if (!$this->handle) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }
        
        $this->totalBytes = filesize($filePath);
        
        if ($this->hasHeaders) {
            $this->parseHeaders();
        }
    }
    
    public function __destruct()
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }
    
    public function setValidationRules(array $rules): self
    {
        $this->validationRules = $rules;
        return $this;
    }
    
    public function skipInvalidRows(bool $skip = true): self
    {
        $this->skipInvalidRows = $skip;
        return $this;
    }
    
    public function setMaxErrors(int $maxErrors): self
    {
        $this->maxErrors = $maxErrors;
        return $this;
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public function getValidationWarnings(): array
    {
        return $this->validationWarnings;
    }
    
    public function getCurrentLine(): int
    {
        return $this->currentLine;
    }
    
    public function getProgress(): array
    {
        return [
            'bytes_read' => $this->bytesRead,
            'total_bytes' => $this->totalBytes,
            'percentage' => $this->totalBytes > 0 ? round(($this->bytesRead / $this->totalBytes) * 100, 2) : 0,
            'current_line' => $this->currentLine,
            'errors_count' => count($this->errors)
        ];
    }
    
    public function chunk(int $size, callable $callback, callable $progressCallback = null): array
    {
        $stats = [
            'processed' => 0,
            'imported' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $chunk = [];
        
        $totalRowsRead = 0;
        
        foreach ($this->parseRows() as $row) {
            $totalRowsRead++;
            
            if ($row === null) {
                $stats['failed']++;
                $stats['processed']++; // Count skipped rows as processed
                continue;
            }
            
            $chunk[] = $row;
            $stats['processed']++;
            
            if (count($chunk) >= $size) {
                $result = $callback($chunk, $this->getProgress());
                $stats['imported'] += $result['imported'] ?? count($chunk);
                $stats['failed'] += $result['failed'] ?? 0;
                $stats['errors'] = array_merge($stats['errors'], $result['errors'] ?? []);
                
                $chunk = [];
                
                if ($progressCallback) {
                    $progressCallback($this->getProgress(), $stats);
                }
                
                if (count($this->errors) >= $this->maxErrors) {
                    $stats['errors'][] = "Maximum error limit ({$this->maxErrors}) reached";
                    break;
                }
            }
        }
        
        // Count validation warnings as processed but failed rows
        $validationWarningsCount = count($this->validationWarnings);
        if ($validationWarningsCount > 0 && $this->skipInvalidRows) {
            $stats['failed'] += $validationWarningsCount;
            // Don't double-count processed rows
        }
        
        if (!empty($chunk)) {
            $result = $callback($chunk, $this->getProgress());
            $stats['imported'] += $result['imported'] ?? count($chunk);
            $stats['failed'] += $result['failed'] ?? 0;
            $stats['errors'] = array_merge($stats['errors'], $result['errors'] ?? []);
        }
        
        // Only include fatal errors in the error list if not skipping invalid rows
        // Validation warnings are separate and don't cause failure
        if ($this->skipInvalidRows) {
            $stats['errors'] = array_merge($stats['errors'], $this->errors); // Only fatal errors
            $stats['validation_warnings'] = $this->validationWarnings;
        } else {
            $stats['errors'] = array_merge($stats['errors'], $this->errors, $this->validationWarnings);
        }
        
        return $stats;
    }
    
    public function parseRows(): \Generator
    {
        while (!feof($this->handle)) {
            $startPosition = ftell($this->handle);
            
            try {
                $row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape);
                
                if ($row === false) {
                    break;
                }
                
                $this->currentLine++;
                $this->bytesRead = ftell($this->handle);
                
                if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                    continue;
                }
                
                $processedRow = $this->processRow($row);
                
                if ($processedRow !== null) {
                    yield $processedRow;
                } else {
                    if ($this->skipInvalidRows) {
                        yield null; // Yield null to indicate skipped row
                    } else {
                        throw new \RuntimeException("Invalid row at line {$this->currentLine}");
                    }
                }
                
            } catch (\Exception $e) {
                $this->addError($e->getMessage(), $this->currentLine, $row ?? []);
                
                if (!$this->skipInvalidRows) {
                    throw $e;
                }
                
                yield null;
            }
        }
    }
    
    protected function parseHeaders(): void
    {
        if (!$this->handle) {
            return;
        }
        
        $headers = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape);
        
        if ($headers === false) {
            throw new \RuntimeException("Cannot read headers from CSV file");
        }
        
        $this->headers = array_map('trim', $headers);
        $this->currentLine = 1;
        $this->bytesRead = ftell($this->handle);
    }
    
    protected function processRow(array $row): ?array
    {
        $row = array_map(function($value) {
            return $value === null ? '' : trim($value);
        }, $row);
        
        if ($this->hasHeaders) {
            $headerCount = count($this->headers);
            $rowCount = count($row);
            
            if ($rowCount < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif ($rowCount > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
                if ($this->skipInvalidRows) {
                    $this->addValidationWarning("Row has more columns than headers", $this->currentLine, $row);
                    // Don't return null here - let validation decide
                } else {
                    $this->addError("Row has more columns than headers", $this->currentLine, $row);
                    return null; // Skip row when not in skipInvalidRows mode
                }
            }
        }
        
        if (!$this->validateRow($row)) {
            return null;
        }
        
        return $row;
    }
    
    protected function validateRow(array $row): bool
    {
        if (empty($this->validationRules)) {
            return true;
        }
        
        $hasValidationErrors = false;
        $currentRowErrors = 0;
        
        foreach ($this->validationRules as $columnIndex => $rules) {
            $value = $row[$columnIndex] ?? '';
            
            foreach ($rules as $rule => $parameter) {
                if (!$this->applyValidationRule($value, $rule, $parameter)) {
                    $columnName = $this->headers[$columnIndex] ?? "Column {$columnIndex}";
                    $message = "Validation failed for {$columnName}: {$rule}";
                    
                    if ($this->skipInvalidRows) {
                        $this->addValidationWarning($message, $this->currentLine, $row);
                        $currentRowErrors++;
                    } else {
                        $this->addError($message, $this->currentLine, $row);
                        $hasValidationErrors = true;
                    }
                }
            }
        }
        
        // If skipInvalidRows is true and we have errors, return false to skip this row
        if ($this->skipInvalidRows && $currentRowErrors > 0) {
            return false; // Skip this row but don't fail the import
        }
        
        return !$hasValidationErrors;
    }
    
    protected function applyValidationRule($value, string $rule, $parameter): bool
    {
        return match ($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min_length' => strlen($value) >= $parameter,
            'max_length' => strlen($value) <= $parameter,
            'regex' => preg_match($parameter, $value) === 1,
            'in' => in_array($value, (array) $parameter),
            'not_empty' => trim($value) !== '',
            default => true
        };
    }
    
    protected function addError(string $message, int $line, array $row): void
    {
        $this->errors[] = [
            'message' => $message,
            'line' => $line,
            'row' => $row,
            'timestamp' => time()
        ];
    }
    
    protected function addValidationWarning(string $message, int $line, array $row): void
    {
        $this->validationWarnings[] = [
            'message' => $message,
            'line' => $line,
            'row' => $row,
            'timestamp' => time()
        ];
    }
    
    public function seekToLine(int $lineNumber): bool
    {
        if ($lineNumber <= 0) {
            return false;
        }
        
        rewind($this->handle);
        $this->currentLine = 0;
        $this->bytesRead = 0;
        
        if ($this->hasHeaders && $lineNumber > 1) {
            fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape);
            $this->currentLine = 1;
            $this->bytesRead = ftell($this->handle);
        }
        
        while ($this->currentLine < $lineNumber && !feof($this->handle)) {
            if (fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape) === false) {
                return false;
            }
            $this->currentLine++;
            $this->bytesRead = ftell($this->handle);
        }
        
        return true;
    }
    
    public function isEof(): bool
    {
        return feof($this->handle);
    }
}