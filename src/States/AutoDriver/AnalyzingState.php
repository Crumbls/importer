<?php

namespace Crumbls\Importer\States\AutoDriver;

use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class AnalyzingState extends AbstractState
{
    use AutoTransitionsTrait;
    
    /**
     * Enable auto-transitions for this state
     */
    protected function hasAutoTransition(): bool
    {
        return true;
    }
    
    /**
     * Configure auto-transition settings
     */
    protected function onAutoTransitionRefresh(ImportContract $record): void
    {
        // Override the default timing
        $this->autoTransitionPollingInterval = 1000; // 1 second
        $this->autoTransitionDelay = 1; // 1 second
    }
    
    /**
     * Recommend a page class that supports infolists
     */
    public function getRecommendedPageClass(): string
    {
        return GeneralInfolistPage::class;
    }
    
    // UI Implementation
    public function getTitle(ImportContract $record): string
    {
        return 'Analyzing Import File';
    }

    public function getHeading(ImportContract $record): string
    {
        return 'File Analysis in Progress';
    }

    public function getSubheading(ImportContract $record): ?string
    {
        return 'Analyzing your file to determine the best import method...';
    }
    
    public function hasFilamentForm(): bool
    {
        return false; // This state uses infolist, not forms
    }
    
    public function hasFilamentInfolist(): bool
    {
        return true;
    }

    public function buildInfolist(Schema $schema, ImportContract $record): Schema
    {
        return $schema->components([
            Section::make('Analysis Progress')
                ->description('Determining the best import driver for your file')
                ->schema([
                    TextEntry::make('file_info')
                        ->label('Source File')
                        ->state(function () use ($record) {
                            $source = $record->source ?? [];
                            return $source['filename'] ?? 'Import File';
                        })
                        ->icon('heroicon-o-document-text'),
                        
                    TextEntry::make('driver_status')
                        ->label('Driver Detection')
                        ->state('Scanning available drivers...')
                        ->color('warning')
                        ->icon('heroicon-o-magnifying-glass'),
                ])
                ->columns(2),
                
            Section::make('Available Drivers')
                ->description('Checking compatibility with these import drivers')
                ->schema([
                    KeyValueEntry::make('drivers')
                        ->keyLabel('Driver')
                        ->valueLabel('Status')
                        ->state([
                            'WordPress XML Driver' => 'Checking...',
                            'CSV Driver' => 'Checking...',
                            'JSON Driver' => 'Checking...',
                            'Custom Driver' => 'Checking...',
                        ]),
                ]),
                
            Section::make('Next Steps')
                ->description('What happens after analysis')
                ->schema([
                    TextEntry::make('next_step')
                        ->label('After Analysis')
                        ->state('Will automatically switch to the best compatible driver')
                        ->icon('heroicon-o-arrow-right')
                        ->markdown(),
                ]),
        ]);
    }

    public function getHeaderActions(ImportContract $record): array
    {
        return [];
    }

	public function onEnter(): void
	{
		$import = $this->getImport();

		$metadata = $import->metadata ?? [];

		$availableDrivers = Importer::getAvailableDrivers();

		$driver = Arr::first($availableDrivers, function($driverName) use ($import) {
			$driverClass = Importer::driver($driverName);
			return $driverClass::canHandle($import);
		});

		if (!$driver) {
			$import->update([
				'state' => FailedState::class
			]);
			throw new CompatibleDriverNotFoundException();
		}

		$driverClass = Importer::driver($driver);

		$state = $driverClass::config()->getDefaultState();

		// Update the import with the new driver
		$import->update([
			'driver' => $driverClass,
			'state' => $state
		]);

		$import->clearDriver();
	}
}