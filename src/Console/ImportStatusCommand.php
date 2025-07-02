<?php

namespace Crumbls\Importer\Console;

use Illuminate\Console\Command;
use Crumbls\Importer\Support\QueuedImporter;

class ImportStatusCommand extends Command
{
    protected $signature = 'import:status 
                           {import_id : The import ID to check}
                           {--watch : Watch the import status in real-time}
                           {--interval=5 : Polling interval in seconds for watch mode}';
                           
    protected $description = 'Check the status of a queued import';
    
    public function handle(): int
    {
        $importId = $this->argument('import_id');
        
        if ($this->option('watch')) {
            return $this->watchImport($importId);
        }
        
        return $this->checkStatus($importId);
    }
    
    protected function checkStatus(string $importId): int
    {
        $result = QueuedImporter::getResult($importId);
        
        if (!$result) {
            $this->error("❌ Import not found: {$importId}");
            $this->line("The import may have expired or the ID is incorrect.");
            return 1;
        }
        
        $this->displayImportInfo($result);
        
        return 0;
    }
    
    protected function watchImport(string $importId): int
    {
        $interval = (int) $this->option('interval');
        
        $this->info("👀 Watching import: {$importId}");
        $this->line("Press Ctrl+C to stop watching...");
        $this->newLine();
        
        while (true) {
            $result = QueuedImporter::getResult($importId);
            
            if (!$result) {
                $this->error("❌ Import not found or expired: {$importId}");
                return 1;
            }
            
            // Clear the screen
            $this->output->write("\033[2J\033[H");
            
            $this->line("🔄 Import Status (Updates every {$interval}s)");
            $this->line("Time: " . now()->format('Y-m-d H:i:s'));
            $this->line(str_repeat('=', 50));
            
            $this->displayImportInfo($result);
            
            // Check if completed
            if (in_array($result['status'], ['completed', 'failed'])) {
                $this->newLine();
                $this->info("✅ Import finished. Final status: " . $result['status']);
                break;
            }
            
            sleep($interval);
        }
        
        return 0;
    }
    
    protected function displayImportInfo(array $result): void
    {
        $status = $result['status'];
        $emoji = match ($status) {
            'completed' => '✅',
            'failed' => '❌',
            'processing' => '🔄',
            default => '⏳'
        };
        
        $this->line("📋 Import ID: " . $result['import_id']);
        $this->line("{$emoji} Status: " . ucfirst($status));
        
        if (isset($result['user_id'])) {
            $this->line("👤 User ID: " . $result['user_id']);
        }
        
        if (isset($result['metadata']) && !empty($result['metadata'])) {
            $this->line("📝 Metadata:");
            foreach ($result['metadata'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }
        
        // Show results if completed
        if ($status === 'completed' && isset($result['result'])) {
            $this->newLine();
            $this->line("📊 Results:");
            $this->line("  Processed: " . number_format($result['result']['processed']));
            $this->line("  Imported: " . number_format($result['result']['imported']));
            $this->line("  Failed: " . number_format($result['result']['failed']));
            
            if (isset($result['duration'])) {
                $this->line("  Duration: " . round($result['duration'], 2) . " seconds");
                
                if ($result['result']['processed'] > 0) {
                    $rate = $result['result']['processed'] / $result['duration'];
                    $this->line("  Rate: " . number_format($rate, 0) . " records/second");
                }
            }
            
            if (!empty($result['result']['errors'])) {
                $this->newLine();
                $this->warn("⚠️  Errors:");
                foreach (array_slice($result['result']['errors'], 0, 5) as $error) {
                    $errorMsg = is_string($error) ? $error : (is_array($error) ? json_encode($error) : 'Unknown error');
                    $this->line("  • " . $errorMsg);
                }
                
                if (count($result['result']['errors']) > 5) {
                    $remaining = count($result['result']['errors']) - 5;
                    $this->line("  ... and {$remaining} more errors");
                }
            }
        }
        
        // Show error if failed
        if ($status === 'failed' && isset($result['error'])) {
            $this->newLine();
            $this->error("❌ Error Details:");
            $this->line("  Message: " . $result['error']['message']);
            $this->line("  Type: " . $result['error']['type']);
            
            if (isset($result['attempts'])) {
                $this->line("  Attempts: " . $result['attempts']);
            }
        }
        
        // Show timestamps
        $this->newLine();
        $this->line("🕐 Timestamps:");
        
        if (isset($result['started_at'])) {
            $this->line("  Started: " . $result['started_at']);
        }
        
        if (isset($result['completed_at'])) {
            $this->line("  Completed: " . $result['completed_at']);
        }
        
        if (isset($result['failed_at'])) {
            $this->line("  Failed: " . $result['failed_at']);
        }
    }
}