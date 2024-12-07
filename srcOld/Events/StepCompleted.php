<?php

namespace Crumbls\Importer\Events;


class StepCompleted
{
	public string $importId;
	public string $step;
	public array $stats;

	public function __construct(string $importId, string $step, array $stats)
	{
		$this->importId = $importId;
		$this->step = $step;
		$this->stats = $stats;
	}
}
