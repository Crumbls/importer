<?php

namespace Crumbls\Importer\States\Concerns;

use Crumbls\Importer\Models\Contracts\ImportContract;

trait OptimizedStateTransitions
{
	protected function transitionToNextState(ImportContract $record): void
	{
		// Batch state updates to reduce database calls
		$updates = [
			'state' => $this->getNextState(),
			'progress' => $this->calculateProgress(),
			'updated_at' => now(),
		];

		// Only update metadata if it changed
		$newMetadata = $this->getUpdatedMetadata($record);
		if ($newMetadata !== $record->metadata) {
			$updates['metadata'] = $newMetadata;
		}

		$record->update($updates);
	}
}