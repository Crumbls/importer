<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\AutoDriver\PendingStatePrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ViewImportPrompt - Entry point that redirects to state-specific prompts
 * 
 * This prompt gets the import record and immediately redirects to the 
 * prompt class specified by the import's current state.
 */
class ViewImportPrompt extends AbstractPrompt implements MigrationPrompt
{
    public function __construct(Command $command)
    {
        parent::__construct($command);
        $this->redirectToStatePrompt();
    }

    /**
     * Redirect to the state-specific prompt immediately
     */
    protected function redirectToStatePrompt(): void
    {
        $import = $this->command->getRecord();
        
        if (!$import instanceof ImportContract) {
            // No import record available, go back to list
            $this->command->setPrompt(ListImportsPrompt::class);
            return;
        }

        // Get the current state instance
        $stateMachine = $import->getStateMachine();
        $currentState = $stateMachine->getCurrentState();
        
        if ($currentState instanceof AbstractState) {
            $promptClass = $currentState->getPromptClass();

            if (class_exists($promptClass)) {
                $this->command->setPrompt($promptClass);
                return;
            }
        }


	    Log::info($currentState);
	    exit;
        // Fallback to generic state informer if no specific prompt
        $this->command->setPrompt(StateInformerPrompt::class);
    }

    public function render(): ?ImportContract
    {
        // This should never be called since we redirect in constructor
        return null;
    }

    public function tui(): array
    {
		Log::info(__LINE__);
        // This should never be called since we redirect in constructor
        return [];
    }

    public static function getTabTitle(): string
    {
        return 'View Import';
    }


	public static function breadcrumbs() : array{
		$base = ListImportsPrompt::breadcrumbs();
		$base[ViewImportPrompt::class] = new NavItem(ViewImportPrompt::class, static::getTabTitle());
		return $base;
	}
}