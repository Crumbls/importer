<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Console\Prompts\Shared\GenericAutoPrompt;

class CancelledState extends AbstractState
{
    public function getPromptClass(): string
    {
        return GenericAutoPrompt::class;
    }
    
    public function onEnter(): void
    {
        if ($import = $this->getRecord()) {
            $import->update([
				'state' => __CLASS__,
                'failed_at' => now(),
            ]);
        }
    }
}