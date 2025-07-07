<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Exceptions\ImportNotAvailable;
use Crumbls\Importer\Exceptions\InputNotProvided;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\StateMachine\State;

abstract class AbstractState extends State
{
    public function getImport(): ImportContract
    {
		$context = $this->getContext();
		if (!is_array($context) || !array_key_exists('model', $context)) {
			throw new ImportNotAvailable();
		}
		return $context['model'];
    }

}