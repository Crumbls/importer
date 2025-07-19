<?php

namespace Crumbls\Importer\States;

abstract class PendingState extends AbstractState
{
    public function onEnter(): void
    {
		return;
        // Update the import record when entering pending state
        if ($import = $this->getRecord()) {
	        $import->update([
		        'state' => __CLASS__,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
            ]);
        }
    }
}