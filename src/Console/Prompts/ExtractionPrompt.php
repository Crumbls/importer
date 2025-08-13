<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Console\Command;

class ExtractionPrompt extends AbstractPrompt
{
	public function render()
	{
		$this->clearScreen();

		$metadata = $this->record->metadata ?? [];
		$extractionStatus = $metadata['extraction_status'] ?? null;
		
		$this->displayExtractionStatus($extractionStatus, $metadata);
		
		return $this->record;
	}
	
	protected function displayExtractionStatus(?string $status, array $metadata): void
	{

		$this->command->state($this->record->state);

		switch ($status) {
			case 'waiting_for_workers':
				$this->displayWaitingForWorkers($metadata);
				break;
				
			case 'workers_detected':
				$this->displayWorkersDetected($metadata);
				break;
				
			case 'dispatching':
				$this->displayDispatching($metadata);
				break;
				
			case 'queued':
				$this->displayQueued($metadata);
				break;
				
			case 'processing':
				$this->displayProcessing($metadata);
				break;
				
			case 'completed':
				$this->displayCompleted($metadata);
				break;
				
			case 'failed':
				$this->displayFailed($metadata);
				break;
				
			default:
				$this->displayStarting($metadata);
				break;
		}
	}
	
	protected function displayWaitingForWorkers(array $metadata): void
	{
		$queueCheck = $metadata['queue_check'] ?? [];
		$queueName = $queueCheck['queue'] ?? $this->getDefaultQueue();
		
		$this->command->error("No queue workers detected!");
		$this->command->info("");
		$this->command->warn("Please start queue workers:");
		$this->command->line("   php artisan queue:work --queue={$queueName}");
		$this->command->info("");
		$this->command->info("Waiting for workers to come online...");
	}
	
	protected function displayWorkersDetected(array $metadata): void
	{
		$queueCheck = $metadata['queue_check'] ?? [];
		$workerCount = $queueCheck['worker_count'] ?? 'unknown';
		
		$this->command->info("Queue workers detected ({$workerCount} workers)");
		$this->command->info("Starting extraction...");
	}
	
	protected function displayDispatching(array $metadata): void
	{
		$queueCheck = $metadata['queue_check'] ?? [];
		$workerCount = $queueCheck['worker_count'] ?? 'unknown';
		
		$this->command->info("Queue workers active ({$workerCount} workers)");
		
		if (isset($metadata['file_size']) && $metadata['file_size'] > 0) {
			$fileSize = $this->formatFileSize($metadata['file_size']);
			$this->command->info("Queuing extraction job for {$fileSize} source...");
		} else {
			$this->command->info("Queuing extraction job...");
		}
		
		$this->command->info("Monitoring job progress...");
	}
	
	protected function displayQueued(array $metadata): void
	{
		$this->command->info("Job queued and waiting to be processed...");
		$this->command->info("Checking job status...");
		dump($metadata);
		$this->command->info(time());
	}
	
	protected function displayProcessing(array $metadata): void
	{
		$progress = $metadata['extraction_progress'] ?? 0;
		$message = $metadata['extraction_message'] ?? 'Processing data...';
		
		$this->command->info($message);
		$this->command->info("Progress: {$progress}%");
		
		if ($progress > 0) {
			$this->displayProgressBar($progress);
		}
	}
	
	protected function displayCompleted(array $metadata): void
	{
		$this->command->info("Extraction completed successfully!");
		$this->command->info("Moving to next step...");
	}
	
	protected function displayFailed(array $metadata): void
	{
		$error = $metadata['extraction_error'] ?? 'Unknown error';
		
		$this->command->error("Extraction failed!");
		$this->command->error("Error: {$error}");
		
		// Show job failure details if available
		if (isset($metadata['job_failure_details'])) {
			$jobFailure = $metadata['job_failure_details'];
			$this->command->info("");
			$this->command->warn("Job Failure Details:");
			$this->command->line("  Queue: " . ($jobFailure['queue'] ?? 'unknown'));
			$this->command->line("  Failed At: " . ($jobFailure['failed_at'] ?? 'unknown'));
			
			if (isset($jobFailure['exception'])) {
				$this->command->info("");
				$this->command->warn("Exception Details:");
				// Show first few lines of the exception
				$exceptionLines = explode("\n", $jobFailure['exception']);
				foreach (array_slice($exceptionLines, 0, 5) as $line) {
					$this->command->line("  " . trim($line));
				}
				if (count($exceptionLines) > 5) {
					$this->command->line("  ... (exception truncated)");
				}
			}
		}
		
		$this->command->info("");
		$this->command->line("Check the logs for more detailed error information.");
	}
	
	protected function displayStarting(array $metadata): void
	{
	}
	
	protected function displayProgressBar(float $progress): void
	{
		$width = 50;
		$filled = (int)($width * ($progress / 100));
		$empty = $width - $filled;
		
		$bar = str_repeat('=', $filled) . str_repeat('-', $empty);
		$this->command->line("   [{$bar}] {$progress}%");
	}
	
	protected function formatFileSize(int $bytes): string
	{
		if ($bytes === 0) return 'Unknown size';
		
		$units = ['B', 'KB', 'MB', 'GB'];
		$power = floor(log($bytes, 1024));
		return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
	}
	
	protected function getDefaultQueue(): string
	{
		return config('importer.queue.queue', config('queue.default', 'default'));
	}
}