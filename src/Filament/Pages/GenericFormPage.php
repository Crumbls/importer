<?php

namespace Crumbls\Importer\Filament\Pages;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;

class GenericFormPage extends Page
{
    public string $view = 'importer::filament.pages.generic-form';
    
    public ImportContract $record;
    protected AbstractState $state;
    
    /**
     * What capabilities does this page support?
     */
    public static function getSupportedCapabilities(): array
    {
        return ['form', 'actions'];
    }
    
    /**
     * Mount the page with record and state
     */
    public function mount($record, $state): void
    {
        $this->record = $record;
        $this->state = $state;
        
        // Authorization
        static::authorizeResourceAccess();
        
        // Let state handle any mount logic
        if (method_exists($this->state, 'onPageMount')) {
            $this->state->onPageMount($this, $this->record);
        }
        
        // Fill form with default data if state provides it
        if ($this->state->hasFilamentForm()) {
            $defaultData = $this->state->getFormDefaultData($this->record);
            if (!empty($defaultData)) {
                $this->form->fill($defaultData);
            }
        }
    }
    
    /**
     * Build the form schema using the state
     */
    public function form(Schema $schema): Schema
    {
        if ($this->state->hasFilamentForm()) {
            return $this->state->buildForm($schema, $this->record);
        }
        
        // Fallback: show state info
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('State Information')
                ->description('Current step: ' . class_basename($this->state))
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('state_info')
                        ->content('This state (' . class_basename($this->state) . ') does not require form input.')
                        ->columnSpanFull(),
                ]),
        ]);
    }
    
    /**
     * Get the page title from the state
     */
    public function getTitle(): string|Htmlable
    {
        return $this->state->getTitle($this->record);
    }
    
    /**
     * Get the page heading from the state
     */
    public function getHeading(): string|Htmlable
    {
        return $this->state->getHeading($this->record);
    }
    
    /**
     * Get the page subheading from the state
     */
    public function getSubheading(): string|Htmlable|null
    {
        return $this->state->getSubheading($this->record);
    }
    
    /**
     * Get header actions from the state
     */
    protected function getHeaderActions(): array
    {
        return $this->state->getHeaderActions($this->record);
    }
    
    /**
     * Get form actions from the state
     */
    public function getFormActions(): array
    {
        if ($this->state && $this->state->hasFilamentForm()) {
            return $this->state->getFormActions($this->record);
        }
        
        // Default actions for non-form states
        return [
            Action::make('continue')
                ->label('Continue')
                ->icon('heroicon-o-arrow-right')
                ->color('primary')
                ->action('continue'),
                
            Action::make('back')
                ->label('Back to List')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(\Crumbls\Importer\Filament\Resources\ImportResource::getUrl('index')),
        ];
    }
    
    /**
     * Get cached form actions (required by view)
     */
    public function getCachedFormActions(): array
    {
        return $this->getFormActions();
    }
    
    /**
     * Handle continue action
     */
    public function continue(): void
    {
        if ($this->state && $this->state->shouldAutoTransition($this->record)) {
            $this->handleStateTransition();
        } else {
            $this->redirect($this->getSimpleResourceUrl('continue', ['record' => $this->record]));
        }
    }
    
    /**
     * Handle form save
     */
    public function save(): void
    {
        try {
            // $this->authorize('update', $this->record);
            
            if ($this->state->hasFilamentForm()) {
                $data = $this->form->getState();
                $this->state->handleSave($data, $this->record);
            }
            
            // Check if state changed after save
            $this->record->refresh();
            $this->handleStateTransition();
            
        } catch (Halt $exception) {
            // Form validation failed or other halt
            return;
        }
    }
    
    /**
     * Handle state transitions and redirects
     */
    protected function handleStateTransition(): void
    {
        $originalState = $this->state;
        $this->record->refresh();
        
        // Get fresh state from state machine
        $newState = $this->record->getStateMachine()->getCurrentState();
        
        // If state changed, redirect to show new state
        if ($newState !== $originalState) {
            $this->redirect(static::getResourceUrl('continue', ['record' => $this->record]));
            return;
        }
        
        // State didn't change - show success and stay on page
        $this->getSavedNotification()?->send();
    }
    
    /**
     * Get saved notification from state or default
     */
    protected function getSavedNotification(): ?Notification
    {
        if (method_exists($this->state, 'getSavedNotification')) {
            return $this->state->getSavedNotification($this->record);
        }
        
        return Notification::make()
            ->success()
            ->title('Step Completed')
            ->body('Import step has been processed successfully.');
    }
    
    /**
     * Get the resource URL - override parent to use ImportResource
     */
    public function getResourceUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?\Illuminate\Database\Eloquent\Model $tenant = null, bool $shouldGuessMissingParameters = true): string
    {
        return \Crumbls\Importer\Filament\Resources\ImportResource::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }
    
    /**
     * Helper method with simpler signature
     */
    public function getSimpleResourceUrl(string $name, array $parameters = []): string
    {
        return \Crumbls\Importer\Filament\Resources\ImportResource::getUrl($name, $parameters);
    }
    
    /**
     * Authorization check
     */
    public static function authorizeResourceAccess(): void
    {
        // Implement your authorization logic here
        // For now, just check if user is authenticated
        if (!auth()->check()) {
            abort(403);
        }
    }
    
    // =========================================================================
    // POLLING AND REAL-TIME FUNCTIONALITY
    // =========================================================================
    
    /**
     * Get polling interval from state
     */
    public function getPollingInterval(): ?string
    {
        $interval = $this->state->getPollingInterval();
        return $interval ? $interval . 'ms' : null;
    }
    
    /**
     * Handle polling refresh
     */
    public function refresh(): void
    {
        $this->state->onRefresh($this->record);
        
        // Check if state changed during refresh
        $this->record->refresh();
        $currentState = $this->record->getStateMachine()->getCurrentState();
        
        if ($currentState !== $this->state) {
            $this->redirect(static::getResourceUrl('continue', ['record' => $this->record]));
        }
    }
    
    /**
     * Dispatch events from state
     */
    public function getListeners(): array
    {
        $events = $this->state->getDispatchEvents();
        $listeners = [];
        
        foreach ($events as $event) {
            $listeners[$event] = 'handleDispatch';
        }
        
        return $listeners;
    }
    
    /**
     * Handle dispatched events
     */
    public function handleDispatch(string $event, array $data = []): void
    {
        $this->state->onDispatch($event, $data, $this->record);
    }
}