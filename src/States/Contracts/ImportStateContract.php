<?php

namespace Crumbls\Importer\States\Contracts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Filament\Infolists\Infolist;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

interface ImportStateContract
{
    // =========================================================================
    // PAGE DELEGATION SYSTEM
    // =========================================================================

    /**
     * What type of page should render this state?
     */
    public function getRecommendedPageClass(): string;

    /**
     * What data/context does the page need?
     */
    public function getPageContext(ImportContract $record): array;

    /**
     * What capabilities should the page have?
     */
    public function getPageCapabilities(): array;

    // =========================================================================
    // UI CONTENT METHODS
    // =========================================================================

    /**
     * Get the title for this state
     */
    public function getTitle(ImportContract $record): string|Htmlable;

    /**
     * Get the heading for this state
     */
    public function getHeading(ImportContract $record): string|Htmlable;

    /**
     * Get the subheading for this state
     */
    public function getSubheading(ImportContract $record): string|Htmlable|null;

    /**
     * Get header actions for this state
     */
    public function getHeaderActions(ImportContract $record): array;

    /**
     * Get form actions for this state
     */
    public function getFormActions(ImportContract $record): array;

    // =========================================================================
    // CAPABILITY DETECTION
    // =========================================================================

    /**
     * Does this state have a form?
     */
    public function hasFilamentForm(): bool;

    /**
     * Does this state have an infolist?
     */
    public function hasFilamentInfolist(): bool;

    /**
     * Does this state have a table?
     */
    public function hasFilamentTable(): bool;

    /**
     * Does this state have widgets?
     */
    public function hasFilamentWidgets(): bool;

    // =========================================================================
    // FORM HANDLING
    // =========================================================================

    /**
     * Build the form schema for this state
     */
    public function buildForm(Schema $schema, ImportContract $record): Schema;

    /**
     * Get default data for the form
     */
    public function getFormDefaultData(ImportContract $record): array;

    /**
     * Handle form save
     */
    public function handleSave(array $data, ImportContract $record): void;

    // =========================================================================
    // STATE TRANSITIONS
    // =========================================================================

    /**
     * Should this state automatically transition to the next state?
     */
    public function shouldAutoTransition(ImportContract $record): bool;

    // =========================================================================
    // REAL-TIME FUNCTIONALITY
    // =========================================================================

    /**
     * Polling interval for real-time updates
     */
    public function getPollingInterval(): ?int;

    /**
     * Events to dispatch for real-time updates
     */
    public function getDispatchEvents(): array;

    /**
     * Handle polling refresh
     */
    public function onRefresh(ImportContract $record): void;

    /**
     * Handle dispatched events
     */
    public function onDispatch(string $event, array $data, ImportContract $record): void;

    // =========================================================================
    // BACKWARD COMPATIBILITY
    // =========================================================================

    /**
     * @deprecated Use getTitle() instead
     */
    public function getFilamentTitle(ImportContract $record): string;

    /**
     * @deprecated Use getHeading() instead
     */
    public function getFilamentHeading(ImportContract $record): string;

    /**
     * @deprecated Use getSubheading() instead
     */
    public function getFilamentSubheading(ImportContract $record): ?string;

    /**
     * @deprecated Use buildForm() instead
     */
    public function getFilamentForm(Schema $schema, ImportContract $record): Schema;

    /**
     * @deprecated Use getHeaderActions() instead
     */
    public function getFilamentHeaderActions(ImportContract $record): array;
}