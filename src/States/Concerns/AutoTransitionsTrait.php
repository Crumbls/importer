<?php

namespace Crumbls\Importer\States\Concerns;

use Crumbls\Importer\Models\Contracts\ImportContract;

trait AutoTransitionsTrait
{
    /**
     * How long to poll for auto-transitions (in milliseconds)
     * States can override this property
     */
    protected int $autoTransitionPollingInterval = 1000;
    
    /**
     * How long to wait before auto-transitioning (in seconds)
     * States can override this property
     */
    protected int $autoTransitionDelay = 2;
    
    /**
     * Enable polling for auto-transitions
     */
    public function getPollingInterval(): ?int
    {
        if ($this->hasAutoTransition()) {
            return $this->autoTransitionPollingInterval;
        }
        
        return null;
    }
    
    /**
     * Handle polling refresh - check for auto-transition
     */
    public function onRefresh(ImportContract $record): void
    {
        if ($this->hasAutoTransition() && $this->shouldAutoTransition($record)) {
            logger()->info('Auto-transitioning ' . class_basename(static::class));
            $this->transitionToNextState($record);
        }
        
        // Call any additional refresh logic
        $this->onAutoTransitionRefresh($record);
    }
    
    /**
     * Check if this state should auto-transition
     */
    public function shouldAutoTransition(ImportContract $record): bool
    {
        if (!$this->hasAutoTransition()) {
            return false;
        }
        
        $createdAt = $record->created_at ?? now();
        $elapsed = $createdAt->diffInSeconds(now());
        
        return $elapsed >= $this->autoTransitionDelay;
    }
    
    /**
     * Does this state support auto-transitions?
     * States can override this to enable/disable auto-transitions
     */
    protected function hasAutoTransition(): bool
    {
        return true;
	}
    
    /**
     * Additional refresh logic for states to override
     */
    protected function onAutoTransitionRefresh(ImportContract $record): void
    {

		$this->autoTransitionPollingInterval = 1000; // 1 second
	    $this->autoTransitionDelay = 1; // 1 second

    }
}