<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\StateMachine\StateMachine;

class StateMachineRunner
{
    protected ImportContract $import;
    protected StateMachine $stateMachine;
    protected bool $requiresUserInteraction = false;
    protected array $interactiveStates = [];

    public function __construct(ImportContract $import)
    {
        $this->import = $import;
        $this->stateMachine = $import->getStateMachine();
    }

    public function setInteractiveStates(array $states): self
    {
        $this->interactiveStates = $states;
        return $this;
    }

    public function runToCompletion(): array
    {
        $log = [];
        $maxIterations = 50; // Prevent infinite loops
        $iterations = 0;

        while (!$this->isTerminalState() && $iterations < $maxIterations) {
            $currentState = $this->stateMachine->getCurrentState();
            $currentStateClass = get_class($currentState);
            
            $log[] = [
                'state' => $currentStateClass,
                'timestamp' => now()->toISOString(),
                'requires_interaction' => $this->requiresUserInteraction($currentStateClass),
            ];

            // Check if this state requires user interaction
            if ($this->requiresUserInteraction($currentStateClass)) {
                $log[] = [
                    'message' => 'State requires user interaction',
                    'state' => $currentStateClass,
                    'paused' => true,
                ];
                break;
            }

            // Try to find next transition
            $nextState = $this->getNextState($currentStateClass);
            
            if (!$nextState) {
                $log[] = [
                    'error' => 'No valid transition found',
                    'current_state' => $currentStateClass,
                ];
                break;
            }

            // Perform transition
            if ($this->stateMachine->canTransitionTo($nextState)) {
                $this->stateMachine->transitionTo($nextState);
                $log[] = [
                    'transition' => "from {$currentStateClass} to {$nextState}",
                    'success' => true,
                ];
            } else {
                $log[] = [
                    'error' => "Cannot transition from {$currentStateClass} to {$nextState}",
                ];
                break;
            }

            $iterations++;
        }

        return $log;
    }

    public function runSingleStep(): array
    {
        $currentState = $this->stateMachine->getCurrentState();
        $currentStateClass = get_class($currentState);
        
        $nextState = $this->getNextState($currentStateClass);
        
        if (!$nextState) {
            return [
                'error' => 'No valid next state found',
                'current_state' => $currentStateClass,
            ];
        }

        if ($this->stateMachine->canTransitionTo($nextState)) {
            $this->stateMachine->transitionTo($nextState);
            return [
                'success' => true,
                'transition' => "from {$currentStateClass} to {$nextState}",
                'next_state' => $nextState,
            ];
        }

        return [
            'error' => "Cannot transition from {$currentStateClass} to {$nextState}",
        ];
    }

    protected function getNextState(string $currentStateClass): ?string
    {
        $config = $this->stateMachine->getConfig();
        $allowedTransitions = $config->getAllowedTransitions();
        
        // Get possible next states for current state
        $possibleStates = $allowedTransitions[$currentStateClass] ?? [];
        
        if (empty($possibleStates)) {
            return null;
        }

        // If only one option, return it
        if (count($possibleStates) === 1) {
            return $possibleStates[0];
        }

        // Multiple transitions available - use priority logic
        return $this->selectOptimalTransition($currentStateClass, $possibleStates);
    }

    protected function selectOptimalTransition(string $currentStateClass, array $possibleStates): ?string
    {
        // 1. First priority: Check for preferred transition
        $config = $this->stateMachine->getConfig();
        if (method_exists($config, 'getPreferredTransition')) {
            $preferredTransition = $config->getPreferredTransition($currentStateClass);
            
            // If preferred transition exists and is valid, use it
            if ($preferredTransition && in_array($preferredTransition, $possibleStates)) {
                // But only if there's no error condition that should force failure
                if (!$this->hasErrorCondition($currentStateClass) || !str_contains($preferredTransition, 'FailedState')) {
                    return $preferredTransition;
                }
            }
        }

        // 2. Second priority: Handle error conditions
        if ($this->hasErrorCondition($currentStateClass)) {
            // If there's an error, prefer FailedState
            $failedStates = array_filter($possibleStates, function($state) {
                return str_contains($state, 'FailedState');
            });
            
            if (!empty($failedStates)) {
                return $failedStates[0];
            }
        }

        // 3. Third priority: Use general state priorities
        $statePriorities = [
            // High priority (success path)
            'ProcessingState' => 100,
            'CompletedState' => 90,
            'AnalyzingState' => 80,
            'CreateStorageState' => 70,
            'InitializingState' => 60,
            
            // Medium priority (neutral states)
            'PendingState' => 50,
            'CancelledState' => 40,
            
            // Low priority (error states - only if forced)
            'FailedState' => 10,
        ];

        // Filter out failed states unless it's the only option
        $nonFailedStates = array_filter($possibleStates, function($state) {
            return !str_contains($state, 'FailedState');
        });

        // Use non-failed states if available
        $candidateStates = !empty($nonFailedStates) ? $nonFailedStates : $possibleStates;

        // Sort by priority and return the highest
        usort($candidateStates, function($a, $b) use ($statePriorities) {
            $priorityA = $this->getStatePriority($a, $statePriorities);
            $priorityB = $this->getStatePriority($b, $statePriorities);
            
            return $priorityB <=> $priorityA; // Descending order
        });

        return $candidateStates[0] ?? null;
    }

    protected function getStatePriority(string $stateClass, array $priorities): int
    {
        // Extract class name from full namespace
        $className = class_basename($stateClass);
        
        return $priorities[$className] ?? 30; // Default medium priority
    }

    protected function hasErrorCondition(string $currentStateClass): bool
    {
        // Check if current state or import has error conditions
        $import = $this->import;
        
        // Check for error message in import
        if (!empty($import->error_message)) {
            return true;
        }

        // Check if state itself indicates an error
        if (str_contains($currentStateClass, 'Failed') || str_contains($currentStateClass, 'Error')) {
            return true;
        }

        // Add custom error condition checks here
        // For example, check file validity, permissions, etc.
        
        return false;
    }

    public function getAvailableTransitions(): array
    {
        $currentStateClass = get_class($this->stateMachine->getCurrentState());
        $config = $this->stateMachine->getConfig();
        $allowedTransitions = $config->getAllowedTransitions();
        
        return $allowedTransitions[$currentStateClass] ?? [];
    }

    public function canTransitionTo(string $targetState): bool
    {
        return $this->stateMachine->canTransitionTo($targetState);
    }

    public function forceTransitionTo(string $targetState): bool
    {
        if ($this->canTransitionTo($targetState)) {
            $this->stateMachine->transitionTo($targetState);
            return true;
        }
        
        return false;
    }

    protected function isTerminalState(): bool
    {
        $currentStateClass = get_class($this->stateMachine->getCurrentState());
        
        return in_array($currentStateClass, [
            CompletedState::class,
            FailedState::class,
        ]);
    }

    protected function requiresUserInteraction(string $stateClass): bool
    {
        return in_array($stateClass, $this->interactiveStates);
    }

    public function getStateMachine(): StateMachine
    {
        return $this->stateMachine;
    }

    public function getCurrentState(): string
    {
        return get_class($this->stateMachine->getCurrentState());
    }

    public function isComplete(): bool
    {
        return $this->getCurrentState() === CompletedState::class;
    }

    public function isFailed(): bool
    {
        return $this->getCurrentState() === FailedState::class;
    }
}