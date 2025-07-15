<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\States\XmlDriver\ExtractState as BaseState;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Parsers\WordPressXmlStreamParser;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\Shared\FailedState;
use Exception;
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

    public function onEnter(): void
    {
        // Don't run extraction in onEnter anymore - let the UI handle it
        // This allows the user to see the extraction progress
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
        return $schema->schema([
            Section::make('Processing Status')
                ->description('Extracting content from your WordPress XML file using optimized streaming.')
                ->schema([
                    Placeholder::make('loading_status')
                        ->content('⚡ **Processing WordPress XML file**

⏱️ *Extracting posts, pages, media, and metadata - 3 seconds*

Using our optimized XMLReader streaming parser to efficiently process your WordPress content without memory limits.')
                        ->columnSpanFull(),

                    View::make('auto-submit')
                        ->view('crumbls-importer::filament.forms.components.auto-submit')
                        ->viewData(['delay' => 3000])
                        ->dehydrated(false),
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

    public function handleFilamentFormSave(array $data, $record): void
    {
        // This method is called when the form is auto-submitted
        $this->performExtraction($record);
        $this->transitionToNextState($record);
    }

    public function handleFilamentSaveComplete($page): void
    {
        // The transition already happened in handleFilamentFormSave
        // Just refresh the page to show the new state
        $page->redirect($page->getResourceUrl('step', ['record' => $page->record]));
    }

    private function performExtraction($import): void
    {
        try {
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
            $sourceResolver = new SourceResolverManager();
            if ($import->source_type == 'storage') {
                $sourceResolver->addResolver(new FileSourceResolver($import->source_type, $import->source_detail));
            } else {
                throw new \Exception("Unsupported source type: {$import->source_type}");
            }

            // Create and configure the WordPress XML parser
            $parser = new WordPressXmlStreamParser([
                'batch_size' => 100,
                'extract_meta' => true,
                'extract_comments' => true,
                'extract_terms' => true,
                'extract_users' => true,
                'memory_limit' => '256M',
            ]);
            
            // Parse the XML file
            $stats = $parser->parse($import, $storage, $sourceResolver);

            // Update import with parsing results
            $import->update([
                'metadata' => array_merge($metadata, [
                    'parsing_completed' => true,
                    'parsing_stats' => $stats,
                    'processed_at' => now()->toISOString(),
                ])
            ]);

            Notification::make()
                ->title('Extraction Complete!')
                ->body("Processed {$stats['posts']} posts, {$stats['comments']} comments, and {$stats['terms']} terms.")
                ->success()
                ->send();

        } catch (\Exception $e) {
            $import->update([
                'state' => FailedState::class,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            Notification::make()
                ->title('Extraction Failed')
                ->body('Extraction failed: ' . $e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    protected    function transitionToNextState($record): void
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

    // Contract requirement - keep for compatibility
    public function getFilamentAutoSubmitDelay(): int
    {
        return 0; // Disabled - using polling instead
    }

    // Polling-based workflow for extraction monitoring
    public function getFilamentPollingInterval(): ?int
    {
        return 2000; // Poll every 2 seconds during extraction
    }

    public function shouldAutoTransition(ImportContract $record): bool
    {
        // Check if extraction has completed
        $metadata = $record->metadata ?? [];
        return isset($metadata['extraction_completed']) && $metadata['extraction_completed'];
    }

    public function onFilamentRefresh(ImportContract $record): void
    {
        // Update extraction progress in the UI during polling
        $metadata = $record->metadata ?? [];
        if (isset($metadata['extraction_progress'])) {
            // You can dispatch events here to update progress bars
            // or show current extraction status
        }
    }
}