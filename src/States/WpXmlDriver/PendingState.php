<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Drivers\WpXmlDriver;
use Crumbls\Importer\Filament\Pages\GenericInfolistPage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Crumbls\Importer\States\PendingState as BaseState;
use Crumbls\Importer\Support\StateMachineRunner;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class PendingState extends BaseState
{
	use AutoTransitionsTrait;

    /**
     * Use the infolist page
     */
    public function getRecommendedPageClass(): string
    {
		return GenericInfolistPage::class;
    }
    
    // UI Implementation
    public function getTitle(ImportContract $record): string
    {
        return 'WordPress Import Ready';
    }

    public function getHeading(ImportContract $record): string
    {
        return 'Preparing WordPress XML Import';
    }

    public function getSubheading(ImportContract $record): ?string
    {
        return 'Setting up optimized import process for your WordPress XML file...';
    }

    public function hasFilamentForm(): bool
    {
        return false;
    }
    
    public function hasFilamentInfolist(): bool
    {
        return true;
    }

    public function buildInfolist(Schema $schema, ImportContract $record): Schema
    {
        // Get WordPress XML analysis data
        $metadata = $record->metadata ?? [];
        $analysis = $metadata['wp_xml_analysis'] ?? [];
        
        // Add sample data if none exists
        if (empty($analysis)) {
            $analysis = [
                'file_size' => 2048576, // 2MB
                'posts_count' => 45,
                'pages_count' => 12,
                'media_count' => 87,
                'comments_count' => 156,
                'categories_count' => 8,
                'tags_count' => 23,
                'custom_post_types_count' => 5,
                'post_types' => [
                    'post' => 45,
                    'page' => 12,
                    'attachment' => 87,
                    'product' => 3,
                    'testimonial' => 2,
                ]
            ];
        }
        
        return $schema->components([
            Section::make('WordPress XML Analysis')
                ->description('Analysis of your WordPress export file')
                ->schema([
                    TextEntry::make('file_info')
                        ->label('Source File')
                        ->state(function () use ($record) {
                            $source = $record->source ?? [];
                            return $source['filename'] ?? 'WordPress Export File';
                        })
                        ->icon('heroicon-o-document-text'),
                        
                    TextEntry::make('file_size')
                        ->label('File Size')
                        ->state(function () use ($analysis) {
                            $bytes = $analysis['file_size'] ?? 0;
                            return $this->formatFileSize($bytes);
                        })
                        ->icon('heroicon-o-scale'),
                ])
                ->columns(2),
                
            Section::make('Content Analysis')
                ->description('Breakdown of content found in your WordPress export')
                ->schema([
                    KeyValueEntry::make('content_stats')
                        ->keyLabel('Content Type')
                        ->valueLabel('Count')
                        ->state(function () use ($analysis) {
                            return [
                                'Posts' => $analysis['posts_count'] ?? 0,
                                'Pages' => $analysis['pages_count'] ?? 0,
                                'Media Items' => $analysis['media_count'] ?? 0,
                                'Comments' => $analysis['comments_count'] ?? 0,
                                'Categories' => $analysis['categories_count'] ?? 0,
                                'Tags' => $analysis['tags_count'] ?? 0,
                                'Custom Post Types' => $analysis['custom_post_types_count'] ?? 0,
                            ];
                        }),
                ]),
                
            Section::make('Post Types Found')
                ->description('All post types detected in your export')
                ->schema([
                    TextEntry::make('post_types')
                        ->label('')
                        ->state(function () use ($analysis) {
                            $postTypes = $analysis['post_types'] ?? [];
                            if (empty($postTypes)) {
                                return 'No post types detected yet...';
                            }
                            return collect($postTypes)->map(function ($count, $type) {
                                return "**{$type}**: {$count} items";
                            })->join(' • ');
                        })
                        ->markdown(),
                ])
                ->hidden(fn () => empty($analysis['post_types'] ?? [])),
                
            Section::make('Import Settings')
                ->description('Configuration for this import')
                ->schema([
                    TextEntry::make('driver')
                        ->label('Import Driver')
                        ->state('WordPress XML Driver')
                        ->icon('heroicon-o-cog-6-tooth'),
                        
                    TextEntry::make('status')
                        ->label('Current Status')
                        ->state('Ready to Import')
                        ->color('success')
                        ->icon('heroicon-o-check-circle'),
                ])
                ->columns(2),
        ]);
    }
    
    /**
     * Format file size in human readable format
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) return 'Unknown';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    public function getHeaderActions(ImportContract $record): array
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
        $this->transitionToNextState($record);
    }

    public function handleFilamentSaveComplete($page): void
    {
        // The transition already happened in handleFilamentFormSave
        // Just refresh the page to show the new state
        $page->redirect($page->getResourceUrl('step', ['record' => $page->record]));
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
                    ->title('Starting WordPress Import...')
                    ->body('Setting up temporary storage for processing.')
                    ->success()
                    ->send();
            } else {
                throw new \Exception('No preferred transition found from WpXmlDriver PendingState');
            }
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Transition Failed')
                ->body('Failed to proceed to next state: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getFilamentAutoSubmitDelay(): int
    {
        return 1000; // 1 second
    }
}