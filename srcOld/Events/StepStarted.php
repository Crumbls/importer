<?php

namespace Crumbls\Importer\Events;

class StepStarted
{
	public string $importId;
	public string $step;

	public function __construct(string $importId, string $step)
	{
		$this->importId = $importId;
		$this->step = $step;
	}
}