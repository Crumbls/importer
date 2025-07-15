<?php

namespace Crumbls\Importer\States\Execution;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProcessingState extends AbstractState
{
    protected static ?string $title = 'Processing Import';
    protected static ?string $description = 'Your import is currently being processed';

    /**
     * This state shows progress, no form needed
     */
    public function getRecommendedPageClass(): string
    {
        return \Crumbls\Importer\Filament\Pages\GenericFormPage::class;
    }

    /**
     * This state doesn't have a form - it shows progress
     */
    public function hasFilamentForm(): bool
    {
        return false;
    }

    /**
     * Build progress display instead of form
     */
    public function buildForm(Schema $schema, ImportContract $record): Schema
    {
        $execution = $record->executions()->latest()->first();
        $progress = $this->calculateProgress($execution);

        return $schema->schema([
            Section::make('Import Progress')
                ->description('Your import is being processed in the background')
                ->schema([
                    Placeholder::make('progress_indicator')
                        ->content(function () use ($progress) {
                            $progressBar = $this->renderProgressBar($progress['percentage']);
                            return "
                                <div class='space-y-4'>
                                    <div class='text-lg font-semibold text-gray-900 dark:text-white'>
                                        Processing: {$progress['percentage']}% Complete
                                    </div>
                                    
                                    {$progressBar}
                                    
                                    <div class='grid grid-cols-2 gap-4 text-sm'>
                                        <div>
                                            <span class='font-medium'>Records Processed:</span>
                                            <span class='text-green-600'>{$progress['processed']}</span>
                                        </div>
                                        <div>
                                            <span class='font-medium'>Total Records:</span>
                                            <span class='text-gray-600'>{$progress['total']}</span>
                                        </div>
                                        <div>
                                            <span class='font-medium'>Successful:</span>
                                            <span class='text-green-600'>{$progress['successful']}</span>
                                        </div>
                                        <div>
                                            <span class='font-medium'>Failed:</span>
                                            <span class='text-red-600'>{$progress['failed']}</span>
                                        </div>
                                    </div>
                                </div>
                            ";
                        })
                        ->columnSpanFull(),

                    Placeholder::make('estimated_completion')
                        ->content(function () use ($progress) {
                            if ($progress['percentage'] > 0 && $progress['percentage'] < 100) {
                                $estimated = $this->estimateCompletion($progress);
                                return "
                                    <div class='rounded-md bg-blue-50 p-4 dark:bg-blue-900/50'>
                                        <div class='text-sm text-blue-700 dark:text-blue-300'>
                                            <strong>Estimated completion:</strong> {$estimated}
                                        </div>
                                    </div>
                                ";
                            }
                            return '';
                        })
                        ->columnSpanFull(),
                ]),

            Section::make('Processing Details')
                ->schema([
                    Placeholder::make('current_batch')
                        ->content(fn() => "**Current Batch:** Processing records " . 
                            ($execution->current_batch_start ?? 1) . " - " . 
                            ($execution->current_batch_end ?? 100))
                        ->columnSpanFull(),

                    Placeholder::make('processing_speed')
                        ->content(function () use ($execution) {
                            $speed = $this->calculateProcessingSpeed($execution);
                            return "**Processing Speed:** {$speed} records/minute";
                        })
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    /**
     * Get header actions for processing state
     */
    public function getHeaderActions(ImportContract $record): array
    {
        return [
            Action::make('pause')
                ->label('Pause Import')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->action(function () use ($record) {
                    $this->pauseImport($record);
                })
                ->visible(fn() => $record->execution_state === 'running'),

            Action::make('cancel')
                ->label('Cancel Import')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () use ($record) {
                    $this->cancelImport($record);
                }),

            Action::make('view_logs')
                ->label('View Logs')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn() => route('imports.logs', $record)),
        ];
    }

    /**
     * Enable polling for real-time updates
     */
    public function getPollingInterval(): ?int
    {
        return 2000; // Poll every 2 seconds
    }

    /**
     * Handle polling refresh to update progress
     */
    public function onRefresh(ImportContract $record): void
    {
        $record->refresh();
        
        // Check if import completed
        if (in_array($record->execution_state, ['completed', 'failed', 'cancelled'])) {
            // Import finished - notification will be sent and page will redirect
            $this->handleImportCompletion($record);
        }
    }

    /**
     * Calculate current progress
     */
    protected function calculateProgress($execution): array
    {
        if (!$execution) {
            return [
                'percentage' => 0,
                'processed' => 0,
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
            ];
        }

        $total = $execution->total_records ?? 0;
        $processed = $execution->records_processed ?? 0;
        $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

        return [
            'percentage' => $percentage,
            'processed' => $processed,
            'total' => $total,
            'successful' => $execution->records_created ?? 0,
            'failed' => $execution->records_failed ?? 0,
        ];
    }

    /**
     * Render progress bar HTML
     */
    protected function renderProgressBar(float $percentage): string
    {
        return "
            <div class='w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700'>
                <div 
                    class='bg-primary-600 h-2.5 rounded-full transition-all duration-300' 
                    style='width: {$percentage}%'
                ></div>
            </div>
        ";
    }

    /**
     * Estimate completion time
     */
    protected function estimateCompletion(array $progress): string
    {
        // Simple estimation based on current progress
        // In real implementation, you'd use more sophisticated calculations
        $remainingPercent = 100 - $progress['percentage'];
        $estimatedMinutes = ($remainingPercent / $progress['percentage']) * 5; // Rough estimate
        
        if ($estimatedMinutes < 1) {
            return 'Less than 1 minute';
        } elseif ($estimatedMinutes < 60) {
            return round($estimatedMinutes) . ' minutes';
        } else {
            $hours = round($estimatedMinutes / 60, 1);
            return $hours . ' hours';
        }
    }

    /**
     * Calculate processing speed
     */
    protected function calculateProcessingSpeed($execution): string
    {
        if (!$execution || !$execution->started_at) {
            return 'Calculating...';
        }

        $elapsedMinutes = now()->diffInMinutes($execution->started_at);
        if ($elapsedMinutes < 1) {
            return 'Calculating...';
        }

        $recordsPerMinute = round(($execution->records_processed ?? 0) / $elapsedMinutes);
        return number_format($recordsPerMinute);
    }

    /**
     * Pause the import
     */
    protected function pauseImport(ImportContract $record): void
    {
        $record->update(['execution_state' => 'paused']);
        
        Notification::make()
            ->title('Import Paused')
            ->body('The import has been paused and can be resumed later.')
            ->warning()
            ->send();
    }

    /**
     * Cancel the import
     */
    protected function cancelImport(ImportContract $record): void
    {
        $record->update(['execution_state' => 'cancelled']);
        
        Notification::make()
            ->title('Import Cancelled')
            ->body('The import has been cancelled.')
            ->danger()
            ->send();
    }

    /**
     * Handle import completion
     */
    protected function handleImportCompletion(ImportContract $record): void
    {
        $execution = $record->executions()->latest()->first();
        
        if ($record->execution_state === 'completed') {
            Notification::make()
                ->title('Import Completed!')
                ->body("Successfully processed {$execution->records_created} records.")
                ->success()
                ->send();
        } elseif ($record->execution_state === 'failed') {
            Notification::make()
                ->title('Import Failed')
                ->body('The import encountered errors. Check the logs for details.')
                ->danger()
                ->send();
        }
    }
}