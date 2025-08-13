<?php

namespace Crumbls\Importer\Parsers;

use Crumbls\Importer\Exceptions\ParsingException;
use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Support\Facades\Log;
use Crumbls\Importer\Support\SchemaDefinition;

class CsvStreamParser
{
    protected StorageDriverContract $storage;
    protected array $config;
    protected array $batches = [];
    protected int $batchSize;
    protected int $originalBatchSize;
    protected array $failedItems = [];
    protected int $totalItems = 0;
    protected int $processedItems = 0;
    protected $progressCallback = null;
    protected $memoryCallback = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 100,
            'delimiter' => ',', 
            'enclosure' => '"',
            'escape' => '\\',
            'newline' => "\n",
            'progress_callback' => null,
            'memory_callback' => null,
        ], $config);
        $this->batchSize = $this->config['batch_size'];
        $this->originalBatchSize = $this->batchSize;
        $this->progressCallback = $this->config['progress_callback'];
        $this->memoryCallback = $this->config['memory_callback'];
    }

    public function parse(ImportContract $import, StorageDriverContract $storage, SourceResolverManager $sourceResolver): array
    {
        $this->storage = $storage;
        $this->initializeBatches();
        
        // Get configuration from import metadata
        $metadata = $import->metadata ?? [];
        $hasHeader = $metadata['headers_first_row'] ?? false;
        $headers = $metadata['headers'] ?? [];
        
        $stats = [
            'rows' => 0,
            'failed' => 0,
            'columns' => $headers,
            'bytes_processed' => 0,
            'memory_peak' => 0,
            'source_type' => $import->source_type,
            'source_detail' => $import->source_detail,
        ];

        // Use SourceResolverManager to resolve the source file path
        $filePath = $sourceResolver->resolve($import->source_type, $import->source_detail);

        // Pre-scan to estimate total rows for progress tracking
        $this->totalItems = $this->estimateRowCount($filePath, $hasHeader);
        $this->processedItems = 0;
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, 0, $this->totalItems, 'rows');
        }

        $delimiter = $this->config['delimiter'];
        $enclosure = $this->config['enclosure'];
        $escape = $this->config['escape'];
        $rowCount = 0;
        $batch = [];
        $tableName = $metadata['target_table'] ?? 'csv_imports';
        $tableCreated = false;
        $isFirstRow = true;

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw ParsingException::fileNotReadable($filePath);
        }

        while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            // Skip the header row if it's the first row and has_header is true
            if ($isFirstRow && $hasHeader) {
                $isFirstRow = false;
                
                // Create table on first data read (after skipping header)
                if (!$tableCreated && !empty($headers)) {
                    $this->createTable($tableName, $headers);
                    $tableCreated = true;
                }
                continue; // Skip the header row
            }
            
            $isFirstRow = false;
            
            // Create table if not created yet (for files without headers)
            if (!$tableCreated) {
                // Use provided headers or generate column names
                $columnsForTable = !empty($headers) ? $headers : $this->generateColumnNames(count($row));
                $this->createTable($tableName, $columnsForTable);
                $tableCreated = true;
            }

            // Convert row to associative array if we have headers
            if (!empty($headers)) {
                // Ensure we have the right number of values, pad or trim as needed
                $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                $row = array_combine($headers, $row);
            }

            $batch[] = $row;
            $rowCount++;
            $this->processedItems++;
            $stats['bytes_processed'] += strlen(implode($delimiter, (array)$row));

            // Progress callback every 10 rows or every 1%
            if ($this->progressCallback && ($this->processedItems % 10 == 0 || $this->processedItems % max(1, intval($this->totalItems / 100)) == 0)) {
                call_user_func($this->progressCallback, $this->processedItems, $this->totalItems, 'rows');
            }

            // Dynamic memory and batch management
            $this->manageBatchSizeAndMemory();

            if (count($batch) >= $this->batchSize) {
                $this->processBatch($batch, $tableName, $headers);
                $batch = [];
            }
            $stats['memory_peak'] = max($stats['memory_peak'], memory_get_peak_usage(true));
        }
        
        // Process final batch
        if (!empty($batch)) {
            $this->processBatch($batch, $tableName, $headers);
        }
        
        fclose($handle);

        // Flush remaining batches if needed
        $this->flushAllBatches();

        $stats['rows'] = $rowCount;
        $stats['failed'] = count($this->failedItems);
        return $stats;
    }

    protected function estimateRowCount(string $filePath, bool $hasHeader = false): int
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) return 0;
        
        $count = 0;
        while (fgets($handle) !== false) {
            $count++;
        }
        fclose($handle);
        
        // Subtract 1 if the first row is a header
        return $hasHeader ? max(0, $count - 1) : $count;
    }

    protected function initializeBatches(): void
    {
        $this->batches = [];
        $this->failedItems = [];
        $this->totalItems = 0;
        $this->processedItems = 0;
    }

    protected function createTable(string $tableName, array $columns): void
    {
        // Use SchemaDefinition builder pattern, all columns as TEXT for now
        $schema = new SchemaDefinition($tableName);
        foreach ($columns as $col) {
            $schema->text($col, ['nullable' => true]);
        }

        // If table exists, compare schema and append missing columns
        if ($this->storage->tableExists($tableName)) {
            $existingCols = $this->storage->getColumns($tableName);
            $missing = array_diff($columns, $existingCols);
            foreach ($missing as $col) {
                // Add missing columns as TEXT
                $this->storage->addColumn($tableName, $col, ['type' => 'text', 'nullable' => true]);
            }
            return;
        }

        // Create new table
        $this->storage->createTableFromSchema($tableName, $schema->toArray());
    }

    protected function generateColumnNames(int $count): array
    {
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = 'column_' . ($i + 1);
        }
        return $names;
    }

    protected function processBatch(array $batch, string $tableName, array $headers): void
    {
        // Insert batch into the table
        foreach ($batch as $row) {
            try {
                // Ensure associative array for insert
                if (!empty($headers) && array_values($row) === $row) {
                    // Row is indexed array, convert to associative
                    $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                    $row = array_combine($headers, $row);
                }
                
                $this->storage->insert($tableName, $row);
            } catch (\Exception $e) {
                $this->failedItems[] = [
                    'row' => $row,
                    'error' => $e->getMessage()
                ];
                Log::warning('Failed to insert CSV row', [
                    'error' => $e->getMessage(),
                    'row' => $row
                ]);
            }
        }
    }

    protected function manageBatchSizeAndMemory(): void
    {
        // Implement dynamic batch size and memory management logic
        // For now, just keep the original batch size
        $this->batchSize = $this->originalBatchSize;
    }

    protected function flushAllBatches(): void
    {
        // Implement logic to flush remaining batches if needed
        // For now, just do nothing
    }

    public function getBatches(): array
    {
        return $this->batches;
    }

    public function getFailedItems(): array
    {
        return $this->failedItems;
    }
}
