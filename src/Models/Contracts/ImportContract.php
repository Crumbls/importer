<?php

namespace Crumbls\Importer\Models\Contracts;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\StateMachine\StateMachine;

interface ImportContract
{
    /**
     * Get the driver instance for this import
     */
    public function getDriver(): DriverContract;
    
    /**
     * Clear the cached driver instance
     */
    public function clearDriver(): void;
    
    /**
     * Get the state machine instance
     */
    public function getStateMachine(): StateMachine;
    
    /**
     * Clear the cached state machine instance
     */
    public function clearStateMachine(): void;
    
    /**
     * Sync the database state with the state machine current state
     */
    public function syncState(): void;
    
    /**
     * Get the state machine class for this import
     */
    public function getStateMachineClass(): string;
    
    /**
     * Scope query by driver
     */
    public function scopeByDriver($query, string $driver);
    
    /**
     * Scope query by state
     */
    public function scopeByState($query, string $state);
    
    /**
     * Set the importer/driver instance
     */
    public function setImporter(DriverContract $importer): static;
}