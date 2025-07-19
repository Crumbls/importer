<?php

namespace Crumbls\Importer\States;

class CompletedState extends AbstractState
{
    public function onEnter(): void
    {
        if ($import = $this->getRecord()) {
            $import->update([
                'state' => __CLASS__
            ]);
        }
    }
}