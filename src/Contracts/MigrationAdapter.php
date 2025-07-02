<?php

namespace Crumbls\Importer\Contracts;

interface MigrationAdapter
{
    /**
     * Analyze extracted data and create a migration plan
     */
    public function plan(array $extractedData): MigrationPlan;
    
    /**
     * Validate the migration plan for conflicts and issues
     */
    public function validate(MigrationPlan $plan): ValidationResult;
    
    /**
     * Execute a dry run to show what would happen without making changes
     */
    public function dryRun(MigrationPlan $plan): DryRunResult;
    
    /**
     * Execute the actual migration
     */
    public function migrate(MigrationPlan $plan, array $options = []): MigrationResult;
    
    /**
     * Rollback a migration by its ID
     */
    public function rollback(string $migrationId): bool;
    
    /**
     * Get the adapter's configuration object
     */
    public function getConfiguration(): AdapterConfiguration;
    
    /**
     * Set configuration for the adapter
     */
    public function setConfiguration(AdapterConfiguration $configuration): self;
    
    /**
     * Get the adapter's configuration as array (backwards compatibility)
     */
    public function getConfig(): array;
    
    /**
     * Set configuration from array (backwards compatibility)
     */
    public function setConfig(array $config): self;
}