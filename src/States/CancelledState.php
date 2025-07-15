<?php

namespace Crumbls\Importer\States;

class CancelledState extends AbstractState
{
    public function onEnter(): void
    {
        if ($import = $this->getImport()) {
            $import->update([
				'state' => __CLASS__,
                'failed_at' => now(),
            ]);
        }
    }
}