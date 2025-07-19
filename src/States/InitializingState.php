<?php

namespace Crumbls\Importer\States;

class InitializingState extends AbstractState
{
    public function onEnter(): void
    {
        if ($import = $this->getRecord()) {
            $import->update([
                'state' => __CLASS__,
                'started_at' => now(),
            ]);
        }
    }
}