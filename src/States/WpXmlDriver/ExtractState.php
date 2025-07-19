<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\States\XmlDriver\ExtractState as BaseState;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Jobs\ExtractWordPressXmlJob;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Exception;
use Illuminate\Support\Facades\Log;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class ExtractState extends BaseState
{
    use AutoTransitionsTrait;


	/**
	 * Additional refresh logic for states to override
	 */
	protected function onAutoTransitionRefresh(ImportContract $record): void
	{

		$this->autoTransitionPollingInterval = 2000; // 1 second
		$this->autoTransitionDelay = 0; // 1 second

		// Update extraction progress in the UI during polling
		$metadata = $record->metadata ?? [];
		if (isset($metadata['extraction_progress'])) {
			// You can dispatch events here to update progress bars
			// or show current extraction status
		}

	}

    // Auto-transition configuration
    public function onEnter(): void
    {
        // Get import from context using the standard method
        $import = $this->getRecord();
        
        if (!$import) {
            Log::error('No import found in ExtractState context');
            return;
        }
        
        // Dispatch background job for extraction
        try {
            ExtractWordPressXmlJob::dispatch($import)
                ->delay(now()->addSeconds(2)); // Small delay for UI feedback
                
            // Mark job as dispatched
            $metadata = $import->metadata ?? [];
            $metadata['extraction_job_dispatched'] = true;
            $metadata['extraction_status'] = 'queued';
            $metadata['extraction_dispatched_at'] = now()->toISOString();
            
            $import->update(['metadata' => $metadata]);
            
        } catch (\Exception $e) {
            // Fallback to synchronous processing if job dispatch fails
            Log::warning('Failed to dispatch extraction job, falling back to sync processing', [
                'import_id' => $import->getKey(),
                'error' => $e->getMessage()
            ]);
            
            $this->performExtractionSync($import);
        }
    }

    // Filament UI Implementation
    public function getFilamentTitle(ImportContract $record): string
    {
        return 'Processing WordPress Data';
    }

    public function getFilamentHeading(ImportContract $record): string
    {
        return 'Extracting WordPress XML Content';
    }

    public function getFilamentSubheading(ImportContract $record): ?string
    {
        return 'Processing your WordPress XML file and extracting all content...';
    }

    public function hasFilamentForm(): bool
    {
        return true; // This state has a form
    }

    public function getFilamentForm(Schema $schema, ImportContract $record): Schema
    {
        $metadata = $record->metadata ?? [];
        $status = $metadata['extraction_status'] ?? 'starting';
        $progress = $metadata['extraction_progress'] ?? 0;
        $current = $metadata['extraction_current'] ?? 0;
        $total = $metadata['extraction_total'] ?? 0;
        
        return $schema->schema([
            Section::make('Background Processing')
                ->description('Your WordPress XML file is being processed in the background using optimized streaming.')
                ->schema([
                    Placeholder::make('job_status')
                        ->content($this->getJobStatusMessage($status, $progress, $current, $total))
                        ->columnSpanFull(),
                ])
                ->collapsible(false),

            Section::make('What\'s Being Processed?')
                ->description('Content types being extracted from your WordPress XML')
                ->schema([
                    Placeholder::make('process_info')
                        ->content('**WordPress Content Extraction:**

• 📝 **Posts & Pages** - All your blog posts and static pages
• 🏷️ **Categories & Tags** - Complete taxonomy structure
• 💬 **Comments** - All comments and comment metadata
• 👤 **Authors** - User accounts and profiles
• 📎 **Attachments** - Media references and metadata
• 🔧 **Custom Fields** - All post meta and custom data

The extraction process uses memory-efficient streaming to handle files of any size safely.')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public function getFilamentHeaderActions(ImportContract $record): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(fn() => route('filament.admin.resources.imports.index')),
        ];
    }

    // No form save handling needed for UI - extraction runs via background job
    // But provide synchronous extraction for command-line usage

    public function handleFilamentFormSave(array $data, $record): void
    {
        // This method is called by the command's executeStateProcessing
        // Run extraction synchronously for command-line usage
        $this->performExtractionSync($record, true);
    }

    protected function getJobStatusMessage(string $status, float $progress, int $current, int $total): string
    {
        switch($status) {
            case 'queued':
                return '⏳ **Queued for Processing**

Your WordPress XML import has been queued for background processing. Large files are processed efficiently using memory-optimized streaming.';
            
            case 'initializing':
                return '🔄 **Initializing Extraction**

Setting up optimized streaming parser and database connections...';
            
            case 'processing':
                return "⚡ **Processing WordPress XML**

**Progress:** {$progress}% (" . number_format($current) . " / " . number_format($total) . " items processed)

🎯 Using memory-efficient streaming to handle files of any size safely. Your import will complete automatically and advance to the next step.";
            
            case 'completed':
                return '✅ **Extraction Complete**

WordPress XML processing completed successfully! Moving to analysis phase...';
            
            case 'failed':
                return '❌ **Extraction Failed**

There was an error processing your WordPress XML file. Please check the logs for details.';
            
            default:
                return '🚀 **Starting Background Processing**

Preparing to process your WordPress XML file using optimized streaming technology...';
        }
    }

    protected function determineQueue(ImportContract $import): string
    {
        // Estimate processing load based on file size or metadata
        $metadata = $import->metadata ?? [];
        
        if (isset($metadata['file_size'])) {
            $fileSizeMB = $metadata['file_size'] / (1024 * 1024);
            
            if ($fileSizeMB > 100) {
                return 'heavy-imports'; // Large files
            } elseif ($fileSizeMB > 10) {
                return 'medium-imports'; // Medium files
            }
        }
        
        return 'imports'; // Default queue
    }

    protected function performExtractionSync(ImportContract $import, bool $allowSync = false): void
    {
        if (!$allowSync) {
            // UI fallback - don't run sync extraction for large files
            try {
                $import->update([
                    'metadata' => array_merge($import->metadata ?? [], [
                        'extraction_status' => 'failed',
                        'extraction_message' => 'Synchronous extraction not recommended for large files. Please ensure your queue workers are running.'
                    ])
                ]);
                
                throw new \Exception('Synchronous extraction not recommended for large files. Please ensure your queue workers are running.');
                
            } catch (\Exception $e) {
                $import->update([
                    'state' => FailedState::class,
                    'error_message' => 'Extraction failed: ' . $e->getMessage(),
                    'failed_at' => now(),
                ]);
            }
            return;
        }
        
        // Command-line synchronous extraction
        try {
            $import->update([
                'metadata' => array_merge($import->metadata ?? [], [
                    'extraction_status' => 'processing',
                    'extraction_message' => 'Running synchronous extraction for command-line...'
                ])
            ]);
            
            $metadata = $import->metadata ?? [];
            
            // Reconfigure the database connection since it's lost between requests
            if (isset($metadata['storage_connection']) && isset($metadata['storage_path'])) {
                $connectionName = $metadata['storage_connection'];
                $sqliteDbPath = $metadata['storage_path'];
                
                // Re-add SQLite connection to Laravel's database config
                config([
                    "database.connections.{$connectionName}" => [
                        'driver' => 'sqlite',
                        'database' => $sqliteDbPath,
                        'prefix' => '',
                        'foreign_key_constraints' => true,
                    ]
                ]);
            }
            
            // Get the storage driver from metadata
            $storage = Storage::driver($metadata['storage_driver'])
                ->configureFromMetadata($metadata);

            // Set up the source resolver
            $sourceResolver = new \Crumbls\Importer\Support\SourceResolverManager();
            if ($import->source_type == 'storage') {
                $sourceResolver->addResolver(new \Crumbls\Importer\Resolvers\FileSourceResolver(
                    $import->source_type,
                    $import->source_detail
                ));
            } else {
                throw new \Exception("Unsupported source type: {$import->source_type}");
            }

            // Create and configure the WordPress XML parser for command-line use
            $parser = new \Crumbls\Importer\Parsers\WordPressXmlStreamParser([
                'batch_size' => 50, // Smaller batches for command-line
                'extract_meta' => true,
                'extract_comments' => true,
                'extract_terms' => true,
                'extract_users' => true,
                'memory_limit' => '512M',
            ]);
            
            // Parse the XML file
            $stats = $parser->parse($import, $storage, $sourceResolver);

            // Update import with parsing results
            $import->update([
                'metadata' => array_merge($metadata, [
                    'extraction_completed' => true,
                    'parsing_completed' => true,
                    'parsing_stats' => $stats,
                    'processed_at' => now()->toISOString(),
                    'extraction_status' => 'completed',
                ])
            ]);

            Log::info('Synchronous extraction completed successfully', [
                'import_id' => $import->getKey(),
                'posts_processed' => $stats['posts'] ?? 0,
                'total_items' => ($stats['posts'] ?? 0) + ($stats['comments'] ?? 0) + ($stats['terms'] ?? 0)
            ]);

        } catch (\Exception $e) {
            $import->update([
                'state' => FailedState::class,
                'error_message' => 'Extraction failed: ' . $e->getMessage(),
                'failed_at' => now(),
                'metadata' => array_merge($import->metadata ?? [], [
                    'extraction_status' => 'failed',
                    'extraction_error' => $e->getMessage(),
                ])
            ]);

            Log::error('Synchronous extraction failed', [
                'import_id' => $import->getKey(),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    protected function transitionToNextState($record): void
    {
        try {
            // Get the driver and its preferred transitions
            $driver = $record->getDriver();
            $config = $driver->config();
            
            // Get the next preferred state from current state
            $nextState = $config->getPreferredTransition(static::class);
            
            if ($nextState) {
                // Get the state machine and transition
                $stateMachine = $record->getStateMachine();
                $stateMachine->transitionTo($nextState);
                
                // Update the record with new state
                $record->update(['state' => $nextState]);
                
                Notification::make()
                    ->title('Proceeding to Next Step...')
                    ->body('Content extraction complete. Moving to final processing.')
                    ->success()
                    ->send();
            } else {
                // If no next state, this might be the final state
                $record->update([
                    'completed_at' => now(),
                    'progress' => 100
                ]);
                
                Notification::make()
                    ->title('Import Complete!')
                    ->body('WordPress XML import has been completed successfully.')
                    ->success()
                    ->send();
            }
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Transition Failed')
                ->body('Failed to proceed to next state: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    // Contract requirement - disabled for completion-based flow
    public function getFilamentAutoSubmitDelay(): int
    {
        return 0; // Disabled - using polling and shouldAutoTransition instead
    }

    // Polling-based workflow for extraction monitoring
    public function getFilamentPollingInterval(): ?int
    {
        return $this->getPollingInterval();
    }

    public function shouldAutoTransition(ImportContract $record): bool
    {
        // Check if extraction has completed - this triggers auto-transition
        $metadata = $record->metadata ?? [];

        // If extraction hasn't started yet, start it
        if (!isset($metadata['extraction_started'])) {
            $this->startExtractionAsync($record);
            return false;
        }

        // Check if extraction has completed successfully
        $isCompleted = isset($metadata['extraction_completed']) && $metadata['extraction_completed'];
        $status = $metadata['extraction_status'] ?? 'unknown';

        // Only auto-transition if extraction is complete and successful
        return $isCompleted && $status === 'completed';
    }

    public function onFilamentRefresh(ImportContract $record): void
    {
        // Use the trait's auto-transition polling logic
        $this->onRefresh($record);
    }

    private function startExtractionAsync(ImportContract $record): void
    {
        try {
            // Mark extraction as started to prevent multiple starts
            $metadata = $record->metadata ?? [];
            $metadata['extraction_started'] = true;
            $metadata['extraction_started_at'] = now()->toISOString();
            
            $record->update(['metadata' => $metadata]);
            
            // Dispatch the background job for extraction
            ExtractWordPressXmlJob::dispatch($record)
                ->delay(now()->addSeconds(2)); // Small delay for UI feedback
                
            // Update metadata to show job was dispatched
            $metadata['extraction_job_dispatched'] = true;
            $metadata['extraction_status'] = 'queued';
            $metadata['extraction_dispatched_at'] = now()->toISOString();
            
            $record->update(['metadata' => $metadata]);
            
        } catch (\Exception $e) {
            // Mark as failed if extraction can't start
            $record->update([
                'state' => FailedState::class,
                'error_message' => 'Failed to start extraction: ' . $e->getMessage(),
                'failed_at' => now(),
            ]);
        }
    }
}