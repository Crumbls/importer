<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Console\Prompts\StateInformerPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\Contracts\ImportStateContract;
use Crumbls\StateMachine\State;
use Exception;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

abstract class AbstractState extends State implements ImportStateContract
{

    // =========================================================================
    // IMPORT CONTRACT ACCESS
    // =========================================================================

    /**
     * Get the import record from the state machine context
     */
    public function getRecord(): ImportContract
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
            
            // Last resort: try to get from global context or find by state
            // This is a fallback for CLI contexts where the state machine context is lost
            $currentStateClass = static::class;
            
            // Try to find the import model by looking for recent imports in the current state
            $importModel = \Crumbls\Importer\Resolvers\ModelResolver::import();
            $import = $importModel::where('state', $currentStateClass)
                ->orderBy('updated_at', 'desc')
                ->first();
                
            if ($import) {
                return $import;
            }
            
            throw new \RuntimeException('Import contract not available in state context. Context: ' . json_encode($context));
        }

        return $context['model'];
    }
    
    /**
     * Get state data from the import metadata
     */
    public function getStateData(string $key) : ?array
    {
        try {
            $import = $this->getRecord();
            return $import->metadata[$key] ?? null;
        } catch (\RuntimeException $e) {
            // If we can't get the import from context, try to get it from the most recent import
            $importModel = \Crumbls\Importer\Resolvers\ModelResolver::import();
            $import = $importModel::orderBy('updated_at', 'desc')->first();
            
            if ($import) {
                return $import->metadata[$key] ?? null;
            }
            
            // If we still can't find it, return null
            return null;
        }
    }
    
    /**
     * Set state data in the import metadata
     */
    public function setStateData(string $key, $value): void
    {
        try {
            $import = $this->getRecord();
            $metadata = $import->metadata ?? [];
            $metadata[$key] = $value;
            $import->update(['metadata' => $metadata]);
        } catch (\RuntimeException $e) {
            // If we can't get the import from context, try to get it from the most recent import
            $importModel = \Crumbls\Importer\Resolvers\ModelResolver::import();
            $import = $importModel::orderBy('updated_at', 'desc')->first();
            
            if ($import) {
                $metadata = $import->metadata ?? [];
                $metadata[$key] = $value;
                $import->update(['metadata' => $metadata]);
            }
        }
    }

    // =========================================================================
    // UI CONTENT METHODS (for page delegation)
    // =========================================================================

    /**
     * Get the prompt class that should handle displaying this state
     * States can override this to provide state-specific prompts
     * 
     * @return string Class name of the prompt to use for viewing this state
     */
	/*
    public function getPromptClass(): string
    {
        return StateInformerPrompt::class; // Default to generic state informer
    }
*/

    // =========================================================================
    // STATE TRANSITIONS
    // =========================================================================

	public function onEnter() : void {
		dump(get_called_class() . '::' . __FUNCTION__);
	}

    /**
     * Execute the main processing logic for this state
     */
    public function execute(): bool
    {
        return true;
    }

	public function onExit() : void {
	}


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
            // Get the driver and its preferred transitions
            $driver = $record->getDriver();
            $config = $driver->config();

            // Get the next preferred state from current state
            $nextState = $config->getPreferredTransition(static::class);

            if ($nextState) {
                $stateMachine = $record->getStateMachine();
                $stateMachine->transitionTo($nextState, $this->getContext());
				$record->update(['state' => $nextState]);
            } else {
                throw new Exception('No preferred transition found from ' . class_basename(static::class));
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
/*
	public static function getCommandPrompt() : string {
		return StateInformerPrompt::class;
	}
*/
}