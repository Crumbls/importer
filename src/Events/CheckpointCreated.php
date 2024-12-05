<?php

namespace Crumbls\Importer\Events;

class CheckpointCreated
{
	public string $importId;
	public string $step;
	public array $data;

	public function __construct(string $importId, string $step, array $data)
	{
		$this->importId = $importId;
		$this->step = $step;
		$this->data = $data;
	}
}
