<?php

namespace Crumbls\Importer\Console;

use Illuminate\Console\Command;
use Crumbls\Importer\ImporterManager;
use Crumbls\Importer\Support\ConfigurationPresets;
use Crumbls\Importer\Support\QueuedImporter;

class ImportCommand extends Command
{
    protected $signature = 'import:file 
                           {file : The file path to import}
                           {--driver=csv : The driver to use (csv, xml, wpxml)}
                           {--preset= : Configuration preset to use}
                           {--queue : Run the import in background queue}
                           {--user= : User ID for tracking}
                           {--chunk-size=1000 : Chunk size for processing}
                           {--max-errors=100 : Maximum errors before stopping}
                           {--skip-invalid : Skip invalid rows instead of stopping}
                           {--preview=10 : Preview mode - show first N rows without importing}
                           {--dry-run : Validate without importing}
                           {--config= : JSON configuration string}';
                           
    protected $description = 'Import data from a file using the Crumbls Importer';
    
    protected ImporterManager $importer;
    
    public function __construct(ImporterManager $importer)
    {
        parent::__construct();
        $this->importer = $importer;
    }
    
    public function handle(): int
    {
        $file = $this->argument('file');
        $driver = $this->option('driver');
        
        // Validate file exists
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }
        
        $this->info("🚀 Starting import of: " . basename($file));
        $this->line("Driver: {$driver}");
        $this->line("File size: " . $this->formatFileSize(filesize($file)));
        
        try {
            if ($this->option('queue')) {
                return $this->handleQueuedImport($file, $driver);
            } else {
                return $this->handleDirectImport($file, $driver);
            }
        } catch (\Exception $e) {
            $this->error("Import failed: " . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }
            
            return 1;
        }
    }
    
    protected function handleDirectImport(string $file, string $driver): int
    {
        $driverInstance = $this->importer->driver($driver);
        
        // Apply configuration
        $this->configureDriver($driverInstance);
        
        // Handle preview mode
        if ($preview = $this->option('preview')) {
            return $this->handlePreview($driverInstance, $file, (int) $preview);
        }
        
        // Handle dry run
        if ($this->option('dry-run')) {
            return $this->handleDryRun($driverInstance, $file);
        }
        
        // Start the import with progress bar
        $this->info("📊 Starting import...");
        $startTime = microtime(true);
        
        $result = $driverInstance->import($file);
        
        $duration = microtime(true) - $startTime;
        
        // Display results
        $this->displayResults($result, $duration);
        
        return $result->hasErrors() ? 1 : 0;
    }
    
    protected function handleQueuedImport(string $file, string $driver): int
    {
        $queued = $this->importer->queued()
            ->driver($driver);
        
        // Apply preset if specified
        if ($preset = $this->option('preset')) {
            $queued->preset($preset);
        }
        
        // Apply configuration
        $config = $this->buildConfiguration();
        if (!empty($config)) {
            $queued->config($config);
        }
        
        // Set user if specified
        if ($user = $this->option('user')) {
            $queued->forUser($user);
        }
        
        // Dispatch the job
        $dispatch = $queued->dispatch($file);
        
        $this->info("✅ Import job dispatched successfully!");
        $this->line("Import ID: " . $dispatch->job->importId ?? 'unknown');
        $this->line("You can check the status with: php artisan import:status {import_id}");
        
        return 0;
    }
    
    protected function handlePreview($driver, string $file, int $limit): int
    {
        $this->info("👀 Previewing first {$limit} rows...");
        
        $preview = $driver->preview($file, $limit);
        
        if (isset($preview['error'])) {
            $this->error("Preview failed: " . $preview['error']);
            return 1;
        }
        
        // Display headers
        if (isset($preview['headers'])) {
            $this->line("📝 Headers:");
            foreach ($preview['headers'] as $index => $header) {
                $this->line("  {$index}: {$header}");
            }
            $this->newLine();
        }
        
        // Display sample rows
        if (isset($preview['rows'])) {
            $this->line("📋 Sample data:");
            $headers = $preview['headers'] ?? [];
            
            foreach ($preview['rows'] as $rowIndex => $row) {
                $this->line("Row " . ($rowIndex + 1) . ":");
                foreach ($row as $colIndex => $value) {
                    $header = $headers[$colIndex] ?? "Column {$colIndex}";
                    $this->line("  {$header}: {$value}");
                }
                $this->newLine();
            }
        }
        
        return 0;
    }
    
    protected function handleDryRun($driver, string $file): int
    {
        $this->info("🔍 Running validation (dry run)...");
        
        if (!$driver->validate($file)) {
            $this->error("❌ File validation failed");
            return 1;
        }
        
        $this->info("✅ File validation passed");
        
        // Get file info
        if (method_exists($driver, 'getFileInfo')) {
            $fileInfo = $driver->getFileInfo($file);
            $this->line("File size: " . ($fileInfo['formatted_size'] ?? 'Unknown'));
            $this->line("MIME type: " . ($fileInfo['mime_type'] ?? 'Unknown'));
        }
        
        return 0;
    }
    
    protected function configureDriver($driver): void
    {
        // Apply preset first
        if ($preset = $this->option('preset')) {
            $driver->preset($preset);
            $this->line("Applied preset: {$preset}");
        }
        
        // Apply individual options
        if ($chunkSize = $this->option('chunk-size')) {
            $driver->chunkSize((int) $chunkSize);
        }
        
        if ($maxErrors = $this->option('max-errors')) {
            $driver->maxErrors((int) $maxErrors);
        }
        
        if ($this->option('skip-invalid')) {
            $driver->skipInvalidRows(true);
        }
        
        // Apply JSON configuration
        if ($configJson = $this->option('config')) {
            $config = json_decode($configJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $driver->setConfig($config);
            } else {
                $this->warn("Invalid JSON configuration provided");
            }
        }
    }
    
    protected function buildConfiguration(): array
    {
        $config = [];
        
        if ($chunkSize = $this->option('chunk-size')) {
            $config['chunk_size'] = (int) $chunkSize;
        }
        
        if ($maxErrors = $this->option('max-errors')) {
            $config['max_errors'] = (int) $maxErrors;
        }
        
        if ($this->option('skip-invalid')) {
            $config['skip_invalid_rows'] = true;
        }
        
        if ($configJson = $this->option('config')) {
            $jsonConfig = json_decode($configJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $config = array_merge($config, $jsonConfig);
            }
        }
        
        return $config;
    }
    
    protected function displayResults($result, float $duration): void
    {
        $this->newLine();
        $this->line("📈 Import Results:");
        $this->line("==================");
        $this->line("✅ Processed: " . number_format($result->getProcessed()));
        $this->line("📥 Imported: " . number_format($result->getImported()));
        $this->line("❌ Failed: " . number_format($result->getFailed()));
        $this->line("⏱️  Duration: " . round($duration, 2) . " seconds");
        
        if ($result->getProcessed() > 0) {
            $rate = $result->getProcessed() / $duration;
            $this->line("🚀 Rate: " . number_format($rate, 0) . " records/second");
        }
        
        if ($result->hasErrors()) {
            $this->newLine();
            $this->warn("⚠️  Errors occurred during import:");
            foreach ($result->getErrors() as $error) {
                $this->line("  • " . (is_string($error) ? $error : json_encode($error)));
            }
        } else {
            $this->newLine();
            $this->info("🎉 Import completed successfully!");
        }
    }
    
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        
        return sprintf("%.1f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}