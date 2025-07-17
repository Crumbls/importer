<?php

namespace Crumbls\Importer\Filament\Resources\ImportResource\Pages;

use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Crumbls\Importer\Filament\Resources\ImportResource;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Services\PageResolver;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\Contracts\ImportStateContract;
use Crumbls\StateMachine\StateMachine;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ImportStep extends Page implements HasInfolists
{
    use InteractsWithRecord;
    use InteractsWithInfolists;

    protected static string $resource = ImportResource::class;
    
    protected string $view = 'importer::filament.pages.import-step';
    
//    public ImportContract $record;
    protected ?AbstractState $currentState = null;
    protected ?Page $delegatedPage = null;
    
    /**
     * Mount the page and set up delegation
     */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

		if (!$this->record instanceof ImportContract) {
			throw new \Exception("Record must be an instance of " . ImportContract::class);
		}

        // Authorize access
        static::authorizeResourceAccess();
        
        // Get current state
	    $state = $this->getCurrentState();
	    
	    if (!$state) {
	        throw new \Exception("No state found for import {$this->record->id}");
	    }

        // Infolist will be initialized automatically when needed

        // Let the state handle any mount logic
        if ($state && method_exists($state, 'onPageMount')) {
            $state->onPageMount($this, $this->record);
        }
    }
    
    // =========================================================================
    // DELEGATION METHODS - Forward to appropriate page
    // =========================================================================
    
    /**
     * Forward form building to delegated page
     */
    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
		$delegatedPage = $this->getDelegatedPage();

        if ($delegatedPage && method_exists($delegatedPage, 'form')) {
            return $delegatedPage->form($schema);
        }
        
        // Fallback: ask state directly
        $state = $this->getCurrentState();

        if ($state) {
            return $state->buildForm($schema, $this->record);
        }
        
        // Last resort fallback
        return $schema->schema([]);
    }
    
    /**
     * Forward infolist building to delegated page
     */
    public function infolist(Schema $schema): Schema
    {
        $delegatedPage = $this->getDelegatedPage();

        if ($delegatedPage && method_exists($delegatedPage, 'infolist')) {
            return $delegatedPage->infolist($schema);
        }
        
        // Fallback: ask state directly
        $state = $this->getCurrentState();

        if ($state) {
            return $state->buildInfolist($schema, $this->record);
        }
        
        // Last resort fallback
        return $schema->components([]);
    }
    
    
    /**
     * Forward table building to delegated page (if applicable)
     */
    public function table(Table $table): Table
    {
		$delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'table')) {
            return $delegatedPage->table($table);
        }
        
        throw new \BadMethodCallException('Current state does not support table view');
    }
    
    /**
     * Forward save handling to delegated page
     */
    public function save(): void
    {
	    $delegatedPage = $this->getDelegatedPage();

		if ($delegatedPage && method_exists($delegatedPage, 'save')) {
            $delegatedPage->save();
            return;
        }

		$state = $this->getCurrentState();

        // Fallback: direct state handling
        if ($state && $state->hasFilamentForm()) {
            $data = $this->form->getState();
            $state->handleSave($data, $this->record);
            $this->handleStateTransition();
        }
    }
    
    // =========================================================================
    // UI PROPERTIES - Delegate to current state/page
    // =========================================================================
    
    public function getTitle(): string
    {

	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'getTitle')) {
            return $delegatedPage->getTitle();
        }

		$state = $this->getCurrentState();
		if ($state) {
            return $state->getTitle($this->record);
        }

        return 'Import Step';
    }
    
    public function getHeading(): string
    {
	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'getHeading')) {
            return $delegatedPage->getHeading();
        }

		$state = $this->getCurrentState();
		if ($state) {
            return $state->getHeading($this->record);
        }

        return 'Import Step';
    }
    
    public function getSubheading(): ?string
    {
	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'getSubheading')) {
            return $delegatedPage->getSubheading();
        }

		$state = $this->getCurrentState();
		if ($state) {
            return $state->getSubheading($this->record);
        }

        return null;
    }
    
    protected function getHeaderActions(): array
    {
        if ($this->delegatedPage && method_exists($this->delegatedPage, 'getHeaderActions')) {
            return $this->delegatedPage->getHeaderActions();
        }

		$state = $this->getCurrentState();
		if ($state) {
            return $state->getHeaderActions($this->record);
        }

        return [];
    }
    
    protected function getFormActions(): array
    {
	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'getFormActions')) {
            return $delegatedPage->getFormActions();
        }

		$state = $this->getCurrentState();
		if ($state) {
            return $state->getFormActions($this->record);
        }

        return [];
    }
    
    /**
     * Get cached form actions (required by view)
     */
    public function getCachedFormActions(): array
    {
        return $this->getFormActions();
    }
    
    // =========================================================================
    // VIEW AND RENDERING
    // =========================================================================
    
    public function getView(): string
    {
        // For now, always use the import-step view which handles delegation
        return 'importer::filament.pages.import-step';
    }
    
    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), [
            'record' => $this->record,
            'currentState' => $this->getCurrentState(),
            'delegatedPage' => $this->getDelegatedPage(),
        ]);
    }
    
    // =========================================================================
    // STATE TRANSITION HANDLING
    // =========================================================================
    
    protected function handleStateTransition(): void
    {
        $originalState = $this->getCurrentState();
        $this->record->refresh();
        
        // Clear cached state and get fresh state
        $this->currentState = null;
        $newState = $this->getCurrentState();
        
        // If state changed, redirect to show new state
        if ($newState !== $originalState) {
            $this->redirect(static::getUrl(['record' => $this->record]));
            return;
        }

	    $delegatedPage = $this->getDelegatedPage();
        // State didn't change - handle completion via delegated page
        if (method_exists($delegatedPage, 'handleStateTransition')) {
	        $delegatedPage->handleStateTransition();
        }
    }
    
    // =========================================================================
    // POLLING AND REAL-TIME FUNCTIONALITY
    // =========================================================================
    
    public function getPollingInterval(): ?string
    {
	    $state = $this->getCurrentState();
	    if ($state) {
            $interval = $state->getPollingInterval();
            return $interval ? $interval . 'ms' : null;
        }

	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'getPollingInterval')) {
            return $delegatedPage->getPollingInterval();
        }

        return null;
    }
    
    public function refresh(): void
    {
	    $state = $this->getCurrentState();
	    if ($state) {
            $state->onRefresh($this->record);
            
            // Check if state changed during refresh
            $this->record->refresh();
            
            // Clear cached state and get fresh state
            $originalState = $this->currentState;
            $this->currentState = null;
            $newState = $this->getCurrentState();
            
            if ($newState !== $originalState) {
                $this->redirect(static::getUrl(['record' => $this->record]));
            }
            return;
        }

	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'refresh')) {
            $delegatedPage->refresh();
        }
    }
    
    public function getListeners(): array
    {

	    $delegatedPage = $this->getDelegatedPage();
        if ($delegatedPage && method_exists($delegatedPage, 'getListeners')) {
            return $delegatedPage->getListeners();
        }
        
        return [];
    }
    
    // =========================================================================
    // UTILITY METHODS
    // =========================================================================
    
    /**
     * Get the URL for this step page
     */
    public static function dis_getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null): string
    {
        return static::getResource()::getUrl('step', $parameters, $isAbsolute, $panel);
    }
    
    /**
     * Get URL for a specific record
     */
    public static function getUrlForRecord($record): string
    {
        return static::getUrl(['record' => $record]);
    }
    
    /**
     * Authorization check
     */
    public static function authorizeResourceAccess(): void
    {
		return;
        // Use Filament's built-in authorization
        static::getResource()::authorizeAccess();
    }
    
    /**
     * Get the resource URL with fallback
     */

	/**
	 * Get or state machine
	 */

	protected function getStateMachine(): ?StateMachine
	{
		if (isset($this->stateMachine)) {
			return $this->stateMachine;
		}

		$record = $this->getRecord();

		if (!$record) {
			return null;
		}

		$this->stateMachine = $record->getStateMachine();
		return $this->stateMachine;
	}

	/**
	 * Get the current state for this import
	 */
	protected function getCurrentState(): ?ImportStateContract
	{
		if (isset($this->currentState)) {
			return $this->currentState;
		}

		$stateMachine = $this->getStateMachine();

		if (!$stateMachine) {
			return null;
		}

		$this->currentState = $stateMachine->getCurrentState();
		return $this->currentState;
	}

	/**
	 * Get page
	 */
	public function getDelegatedPage() {
		if (isset($this->delegatedPage)) {
			return $this->delegatedPage;
		}
		$state = $this->getCurrentState();

		// Resolve the appropriate page to handle this state
		try {
			$pageResolver = app(PageResolver::class);
			$this->delegatedPage = $pageResolver->resolvePage($state, $this->getRecord());
		} catch (\Exception $e) {
			logger()->error('PageResolver failed to resolve page', [
				'state' => get_class($state),
				'recommended_page' => $state->getRecommendedPageClass(),
				'error' => $e->getMessage(),
				'record_id' => $this->record->id,
			]);

			// Create a fallback GenericFormPage directly
			$this->delegatedPage = app(GenericFormPage::class);
			$this->delegatedPage->mount($this->record, $state);
		}
		return $this->delegatedPage;
	}
}