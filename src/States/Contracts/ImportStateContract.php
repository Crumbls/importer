<?php

namespace Crumbls\Importer\States\Contracts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Filament\Infolists\Infolist;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

interface ImportStateContract
{

    // =========================================================================
    // STATE TRANSITIONS
    // =========================================================================

	public function onEnter() : void;

    /**
     * Execute the main processing logic for this state
     */
    public function execute(): bool;

	public function onExit() : void;

	/**
     * Should this state automatically transition to the next state?
     */
    public function shouldAutoTransition(ImportContract $record): bool;

	public function getPromptClass() : string;

}