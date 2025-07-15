<?php

namespace Crumbls\Importer\States;

abstract class PendingState extends AbstractState
{
    public function onEnter(): void
    {
        // Update the import record when entering pending state
        if ($import = $this->getImport()) {
	        $import->update([
		        'state' => __CLASS__,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
            ]);
        }
    }
}