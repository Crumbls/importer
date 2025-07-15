<?php

namespace Crumbls\Importer\States;

class CompletedState extends AbstractState
{
    public function onEnter(): void
    {
        if ($import = $this->getImport()) {
            $import->update([
                'state' => __CLASS__
            ]);
        }
    }
}