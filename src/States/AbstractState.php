<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\Contracts\ImportStateContract;
use Crumbls\StateMachine\State;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

abstract class AbstractState extends State implements ImportStateContract, HasActions, HasForms, HasInfolists
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithInfolists;

    // Basic state properties
    protected static ?string $title = null;
    protected static ?string $description = null;
    protected ?string $heading = null;
    protected ?string $subheading = null;
    
    // Filament form configuration
    public static string|Alignment $formActionsAlignment = Alignment::Start;
    public static bool $formActionsAreSticky = false;
    public static bool $hasInlineLabels = false;

    // =========================================================================
    // PAGE DELEGATION SYSTEM
    // =========================================================================

    /**
     * What type of page should render this state?
     * States can override this to use specialized page classes.
     */
    public function getRecommendedPageClass(): string
    {
        // Default to generic form page - can be overridden
        return \Crumbls\Importer\Filament\Pages\GenericFormPage::class;
    }

    /**
     * What data/context does the page need?
     */
    public function getPageContext(ImportContract $record): array
    {
        return [
            'record' => $record,
            'state' => $this,
        ];
    }

    /**
     * What capabilities should the page have?
     * Helps the page know what methods to call on this state.
     */
    public function getPageCapabilities(): array
    {
        $capabilities = [];
        
        if ($this->hasFilamentForm()) {
            $capabilities[] = 'form';
        }
        
        if ($this->hasFilamentInfolist()) {
            $capabilities[] = 'infolist';
        }
        
        if ($this->hasFilamentTable()) {
            $capabilities[] = 'table';
        }
        
        if ($this->hasFilamentWidgets()) {
            $capabilities[] = 'widgets';
        }

        return $capabilities;
    }

    // =========================================================================
    // IMPORT CONTRACT ACCESS
    // =========================================================================

    /**
     * Get the import record from the state machine context
     */
    public function getImport(): ImportContract
    {
        $context = $this->getContext();

        if (!is_array($context) || !array_key_exists('model', $context)) {
            // Try to get the import from the state machine itself
            if (isset($this->stateMachine) && method_exists($this->stateMachine, 'getContext')) {
                $context = $this->stateMachine->getContext();
                if (is_array($context) && array_key_exists('model', $context)) {
                    return $context['model'];
                }
            }
            
            
            throw new \RuntimeException('Import contract not available in state context. Context: ' . json_encode($context));
        }

        return $context['model'];
    }
    
    /**
     * Get state data from the import metadata
     */
    public function getStateData(string $key)
    {
        $import = $this->getImport();
        return $import->metadata[$key] ?? null;
    }
    
    /**
     * Set state data in the import metadata
     */
    public function setStateData(string $key, $value): void
    {
        $import = $this->getImport();
        $metadata = $import->metadata ?? [];
        $metadata[$key] = $value;
        $import->update(['metadata' => $metadata]);
    }

    // =========================================================================
    // UI CONTENT METHODS (for page delegation)
    // =========================================================================

    /**
     * Get the title for this state
     */
    public function getTitle(ImportContract $record): string|Htmlable
    {
        return static::$title ?? (string) str(class_basename(static::class))
            ->kebab()
            ->replace('-', ' ')
            ->title();
    }

    /**
     * Get the heading for this state (can be different from title)
     */
    public function getHeading(ImportContract $record): string|Htmlable
    {
        return $this->heading ?? $this->getTitle($record);
    }

    /**
     * Get the subheading for this state
     */
    public function getSubheading(ImportContract $record): string|Htmlable|null
    {
        return $this->subheading ?? static::$description;
    }

    // =========================================================================
    // FORM DELEGATION METHODS
    // =========================================================================

    /**
     * Build the form schema for this state
     * States should override this if they want forms
     */
    public function buildForm(Schema $schema, ImportContract $record): Schema
    {
        return $schema->schema([
            // Default empty schema - states should override
        ]);
    }
    
    /**
     * Build the infolist schema for this state
     * States should override this if they want infolists
     */
    public function buildInfolist(Schema $schema, ImportContract $record): Schema
    {
        return $schema->components([
            // Default empty schema - states should override
        ]);
    }

    /**
     * Get default data for the form
     */
    public function getFormDefaultData(ImportContract $record): array
    {
        return [];
    }

    /**
     * Handle form save
     */
    public function handleSave(array $data, ImportContract $record): void
    {
        // Default implementation - states can override
        // Update the record with form data
        $record->update($data);
        
        // Check if we should auto-transition
        if ($this->shouldAutoTransition($record)) {
            $this->transitionToNextState($record);
        }
    }

    // =========================================================================
    // CAPABILITY DETECTION
    // =========================================================================

    /**
     * Does this state have a form?
     */
    public function hasFilamentForm(): bool
    {
        return false; // States should override if they have forms
    }

    /**
     * Does this state have an infolist?
     */
    public function hasFilamentInfolist(): bool
    {
        return false; // States should override if they have infolists
    }

    /**
     * Does this state have a table?
     */
    public function hasFilamentTable(): bool
    {
        return false; // States should override if they have tables
    }

    /**
     * Does this state have widgets?
     */
    public function hasFilamentWidgets(): bool
    {
        return false; // States should override if they have widgets
    }

    // =========================================================================
    // ACTIONS AND INTERACTIONS
    // =========================================================================

    /**
     * Get header actions for this state
     */
    public function getHeaderActions(ImportContract $record): array
    {
        return [
            // Default actions - states can override
            $this->getCancelAction(),
        ];
    }

    /**
     * Get form actions for this state
     */
    public function getFormActions(ImportContract $record): array
    {
        $actions = [];
        
        if ($this->hasFilamentForm()) {
            $actions[] = $this->getSaveAction();
        }
        
        $actions[] = $this->getCancelAction();
        
        return $actions;
    }

    /**
     * Helper method for states that need a save action
     */
    protected function getSaveAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('save')
            ->label('Save & Continue')
            ->icon('heroicon-o-check')
            ->color('primary')
            ->submit('form');
    }

    /**
     * Helper method for states that need a cancel action
     */
    protected function getCancelAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->url(\Crumbls\Importer\Filament\Resources\ImportResource::getUrl('index'));
    }

    // =========================================================================
    // STATE TRANSITIONS
    // =========================================================================

    /**
     * Should this state automatically transition to the next state?
     */
    public function shouldAutoTransition(ImportContract $record): bool
    {
        return false; // States should override if they auto-transition
    }

    /**
     * Transition to the next state based on driver configuration
     */
    protected function transitionToNextState(ImportContract $record): void
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
                $stateMachine->transitionTo($nextState, $this->getContext());

                // Update the record with new state
                $record->update(['state' => $nextState]);

                Notification::make()
                    ->title('Step Completed')
                    ->body('Proceeding to next step.')
                    ->success()
                    ->send();
            } else {
                throw new \Exception('No preferred transition found from ' . class_basename(static::class));
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Transition Failed')
                ->body('Failed to proceed to next state: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    // =========================================================================
    // REAL-TIME FUNCTIONALITY
    // =========================================================================

    /**
     * Polling interval for real-time updates
     */
    public function getPollingInterval(): ?int
    {
        return null;
    }

    /**
     * Events to dispatch for real-time updates
     */
    public function getDispatchEvents(): array
    {
        return []; // No events by default
    }

    /**
     * Handle polling refresh
     */
    public function onRefresh(ImportContract $record): void
    {
        // Override in states that need polling refresh logic
    }

    /**
     * Handle dispatched events
     */
    public function onDispatch(string $event, array $data, ImportContract $record): void
    {
        // Override in states that need dispatch handling
    }

    // =========================================================================
    // FORM CONFIGURATION
    // =========================================================================

    public function getFormActionsAlignment(): string|Alignment
    {
        return static::$formActionsAlignment;
    }

    public function areFormActionsSticky(): bool
    {
        return static::$formActionsAreSticky;
    }

    public function hasInlineLabels(): bool
    {
        return static::$hasInlineLabels;
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    protected function halt(bool $shouldRollbackDatabaseTransaction = false): void
    {
        throw (new Halt)->rollBackDatabaseTransaction($shouldRollbackDatabaseTransaction);
    }

    protected function onValidationError(ValidationException $exception): void
    {
        // States can override for custom validation error handling
    }

    // =========================================================================
    // BACKWARD COMPATIBILITY (with existing pattern)
    // =========================================================================

    /**
     * @deprecated Use getTitle() instead
     */
    public function getFilamentTitle(ImportContract $record): string
    {
        return $this->getTitle($record);
    }

    /**
     * @deprecated Use getHeading() instead
     */
    public function getFilamentHeading(ImportContract $record): string
    {
        return $this->getHeading($record);
    }

    /**
     * @deprecated Use getSubheading() instead
     */
    public function getFilamentSubheading(ImportContract $record): ?string
    {
        return $this->getSubheading($record);
    }

    /**
     * @deprecated Use buildForm() instead
     */
    public function getFilamentForm(Schema $schema, ImportContract $record): Schema
    {
        return $this->buildForm($schema, $record);
    }

    /**
     * @deprecated Use getHeaderActions() instead
     */
    public function getFilamentHeaderActions(ImportContract $record): array
    {
        return $this->getHeaderActions($record);
    }
}