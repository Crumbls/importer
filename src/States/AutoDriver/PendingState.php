<?php

namespace Crumbls\Importer\States\AutoDriver;

use Crumbls\Importer\Console\Prompts\AutoDriver\PendingStatePrompt;
use Crumbls\Importer\Filament\Resources\ImportResource;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\PendingState as BaseState;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Crumbls\Importer\Support\StateMachineRunner;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class PendingState extends BaseState
{
    use AutoTransitionsTrait;
    
    // Auto-transition configuration
    protected int $autoTransitionPollingInterval = 1000; // 1 second
    protected int $autoTransitionDelay = 2; // 2 seconds
    
    /**
     * Enable auto-transitions for this state
     */
    protected function hasAutoTransition(): bool
    {
        return true;
    }
    
    // Filament UI Implementation
    public function getTitle(ImportContract $record): string
    {
        return 'Import Ready';
    }

    public function getHeading(ImportContract $record): string
    {
        return 'Starting Import Process';
    }

    public function getSubheading(ImportContract $record): ?string
    {
        return 'Your import is ready to begin. Analysis will start automatically...';
    }

    public function hasFilamentForm(): bool
    {
        return true;
    }

    public function buildForm(Schema $schema, ImportContract $record): Schema
    {
	    return $schema->schema([]);
	    return $schema->schema([
            Section::make('Import Status')
                ->description('We have access to the source.')
                ->schema([
	                Placeholder::make('loading_status')
		                ->hiddenLabel()
		                ->content('Analyzing source to determine the correct driver, if one exists.')
		                ->columnSpanFull()
		                ->html(),

	                Placeholder::make('test')
		                ->hiddenLabel()
		                ->content(function() {
							srand();
							return rand(1,10000);
		                })
		                ->columnSpanFull()
		                ->html(),

                ])
                ->collapsible(false),

        ]);
    }

    public function getHeaderActions(ImportContract $record): array
    {
		return [];
    }

    public function handleSave(array $data, ImportContract $record): void
    {
        $this->transitionToNextState($record);
    }

    public function handleFilamentSaveComplete($page): void
    {
        // The transition already happened in handleFilamentFormSave
        // Just refresh the page to show the new state
        $page->redirect($page->getResourceUrl('step', ['record' => $page->record]));
    }


	public static function getCommandPrompt() : string {
		return PendingStatePrompt::class;
	}

	public function onEnter() : void {
	}

	public function execute() : bool {
		$record = $this->getRecord();
		$this->transitionToNextState($record);
		return true;
	}

	public function onExit() : void {

	}

}